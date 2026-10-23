<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

/**
 * The default transport, on ext-curl. CURLOPT_TIMEOUT_MS is one deadline for the whole
 * exchange, connect to last byte, which is what spec 3.4 asks for; CURLOPT_FOLLOWLOCATION is
 * off unless the request says it may follow (only the public rates do), so a 3xx comes back
 * as the reply and the client refuses it with `redirected` (spec 3.5).
 */
final class CurlTransport implements TransportInterface
{
    /** @var array<int, mixed> */
    private readonly array $curlOptions;

    /**
     * @param array<int, mixed> $curlOptions extra CURLOPT_* values applied to every request before the SDK's own
     *   (a proxy, a CA bundle, an interface to bind). The options the SDK needs for correctness, among them
     *   FOLLOWLOCATION, TIMEOUT_MS, the URL, the method, the headers and the protocols, are set after yours and win.
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
        $status = 0;
        /** @var array<string, string> $headers */
        $headers = [];
        // One block of headers per hop: when a redirect is followed, the final reply's headers are the ones kept.
        $onHeader = static function (\CurlHandle $handle, string $line) use (&$headers): int {
            if (str_starts_with($line, 'HTTP/')) {
                $headers = [];
            } else {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }
            }
            return strlen($line);
        };
        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }
        // No "Expect: 100-continue" on larger bodies: it costs a round trip and some proxies mishandle it.
        $headerLines[] = 'Expect:';

        $options = $this->curlOptions + [CURLOPT_USERAGENT => ''];
        $options[CURLOPT_URL] = $request->url;
        $options[CURLOPT_CUSTOMREQUEST] = $request->method;
        $options[CURLOPT_HTTPHEADER] = $headerLines;
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_HEADER] = false;
        $options[CURLOPT_HEADERFUNCTION] = $onHeader;
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
        if (!curl_setopt_array($ch, $options)) {
            throw new TransportException('curl_setopt_array refused an option: ' . curl_error($ch));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno !== 0 || !is_string($body)) {
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
}
