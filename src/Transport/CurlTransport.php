<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

use VoltKit\Rtc\StreamClient;

/**
 * The default transport, on ext-curl. CURLOPT_TIMEOUT_MS is one deadline for the whole
 * exchange, connect to last byte, which is what spec 3.4 asks for; CURLOPT_FOLLOWLOCATION is
 * off unless the request says it may follow (only the public rates do), so a 3xx comes back
 * as the reply and the client refuses it with `redirected` (spec 3.5). A body is read up to
 * StreamClient::MAX_REPLY_BYTES and no further (0.1.1): one declared longer is not read at
 * all, one that streams on is cut off at the cap, and the Response says $tooLarge.
 */
final class CurlTransport implements TransportInterface
{
    /** @var array<int, mixed> */
    private readonly array $curlOptions;

    /**
     * @param array<int, mixed> $curlOptions extra CURLOPT_* values applied to every request before the SDK's own
     *   (a proxy, a CA bundle, an interface to bind). The options the SDK needs for correctness, among them
     *   FOLLOWLOCATION, TIMEOUT_MS, the URL, the method, the headers and the protocols, are set after yours and win;
     *   where the reply goes is the SDK's too (RETURNTRANSFER, FILE, WRITEFUNCTION, WRITEHEADER and HEADERFUNCTION
     *   of yours are dropped), so the reply cap holds.
     */
    public function __construct(array $curlOptions = [])
    {
        $this->curlOptions = $curlOptions;
    }

    public function send(#[\SensitiveParameter] Request $request): Response
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }
        $max = StreamClient::MAX_REPLY_BYTES;
        /** @var array<string, string> $headers */
        $headers = [];
        $hopStatus = 0;
        $tooLarge = false;
        $following = $request->followRedirects;
        // One block of headers per hop: when a redirect is followed, the final reply's headers are the ones kept.
        $onHeader = static function (
            \CurlHandle $handle,
            string $line,
        ) use (
            &$headers,
            &$hopStatus,
            &$tooLarge,
            $following,
            $max,
        ): int {
            if (str_starts_with($line, 'HTTP/')) {
                $headers = [];
                $hopStatus = (int) (explode(' ', $line, 3)[1] ?? '0');
            } elseif (trim($line) === '') {
                // The end of a hop's headers. A body declared past the cap is refused before a byte of it is
                // read: a callback returning less than it was handed stops the transfer.
                if (self::declaredPastTheCap($headers, $hopStatus, $following, $max)) {
                    $tooLarge = true;
                    return 0;
                }
            } else {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }
            }
            return strlen($line);
        };
        // The body, up to the cap and no further: one byte past it the transfer stops, so a reply of any size
        // costs at most the cap in memory, and a server streaming without end is not read to its end.
        $body = '';
        $onBody = static function (\CurlHandle $handle, string $chunk) use (&$body, &$tooLarge, $max): int {
            if (strlen($body) + strlen($chunk) > $max) {
                $tooLarge = true;
                $body = '';
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        };
        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }
        // No "Expect: 100-continue" on larger bodies: it costs a round trip and some proxies mishandle it.
        $headerLines[] = 'Expect:';

        $options = $this->curlOptions + [CURLOPT_USERAGENT => ''];
        // Where the reply goes is the SDK's: options that would send it elsewhere are dropped, and the two
        // callbacks are set last, so no option of yours applied after them can take the body away from the cap.
        unset(
            $options[CURLOPT_RETURNTRANSFER],
            $options[CURLOPT_FILE],
            $options[CURLOPT_WRITEFUNCTION],
            $options[CURLOPT_WRITEHEADER],
            $options[CURLOPT_HEADERFUNCTION],
        );
        $options[CURLOPT_URL] = $request->url;
        $options[CURLOPT_CUSTOMREQUEST] = $request->method;
        $options[CURLOPT_HTTPHEADER] = $headerLines;
        $options[CURLOPT_HEADER] = false;
        $options[CURLOPT_TIMEOUT_MS] = $request->timeoutMs;
        $options[CURLOPT_CONNECTTIMEOUT_MS] = $request->timeoutMs;
        // Sub-second timeouts need NOSIGNAL: without it curl's DNS timeout is in whole seconds and uses signals.
        $options[CURLOPT_NOSIGNAL] = true;
        $options[CURLOPT_FOLLOWLOCATION] = $request->followRedirects;
        $options[CURLOPT_MAXREDIRS] = $request->followRedirects ? 5 : 0;
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        } else {
            // A GET, DELETE or bodiless PUT: nothing to upload, and no chunked encoding announced.
            unset($options[CURLOPT_POSTFIELDS]);
            $options[CURLOPT_UPLOAD] = false;
        }
        $options[CURLOPT_HEADERFUNCTION] = $onHeader;
        $options[CURLOPT_WRITEFUNCTION] = $onBody;
        if (!curl_setopt_array($ch, $options)) {
            throw new TransportException('curl_setopt_array refused an option: ' . curl_error($ch));
        }

        $done = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($tooLarge) {
            // Stopped on purpose at the cap: the reply's status and headers, its body unread or let go.
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            return new Response($status !== 0 ? $status : $hopStatus, $headers, '', true);
        }
        if ($errno !== 0 || $done === false) {
            $error = curl_error($ch);
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            $words = $error === '' ? (curl_strerror($errno) ?? 'unknown error') : $error;
            throw new TransportException(sprintf('cURL error %d: %s', $errno, $words), $timedOut);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status === 0) {
            throw new TransportException('cURL error: no HTTP status in the reply');
        }
        return new Response($status, $headers, $body);
    }

    /**
     * Whether a hop's headers declare a body past the cap, for a reply whose body would be read: not an interim
     * 1xx, and not a redirect curl is about to follow (its body is never read; the hop it leads to is checked).
     *
     * @param array<string, string> $headers the hop's headers, names in lower case
     */
    private static function declaredPastTheCap(array $headers, int $status, bool $following, int $max): bool
    {
        $declared = $headers['content-length'] ?? '';
        if ($status < 200 || !ctype_digit($declared)) {
            return false;
        }
        if ($following && in_array($status, [301, 302, 303, 307, 308], true) && isset($headers['location'])) {
            return false;
        }
        // More digits than an int holds is past any cap; (int) would hold it at PHP_INT_MAX anyway.
        return strlen(ltrim($declared, '0')) > 18 || (int) $declared > $max;
    }
}
