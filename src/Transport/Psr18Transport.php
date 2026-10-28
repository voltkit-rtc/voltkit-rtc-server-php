<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use VoltKit\Rtc\StreamClient;

/**
 * Send through any PSR-18 client (Guzzle, Symfony HttpClient's Psr18Client, ...), with PSR-17
 * factories for the request and its body. Needs psr/http-client and psr/http-factory, which
 * the package suggests rather than requires; this class is only loaded when you use it.
 *
 * Two things the adapter cannot do for you, because PSR-18 gives it no say (checked against
 * Guzzle 7 and Symfony HttpClient 7 on 2026-10-23):
 *  - redirects: the client must never follow one. The SDK refuses a 3xx that comes back, but a
 *    client that followed one has already sent X-API-Key to the host it named. Guzzle's
 *    sendRequest() never follows (its PSR-18 path forces `allow_redirects` off); Symfony's
 *    Psr18Client follows up to 20 by default, so build it with `max_redirects => 0`:
 *    `new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 10]))`. With
 *    redirects off, getRates() also gets a 3xx back and fails with `http-3xx` instead of
 *    following it;
 *  - the deadline: PSR-18 has no per-request timeout, so Request::$timeoutMs is not passed on.
 *    Give the client a total timeout covering the whole reply (Guzzle: `timeout`, not only
 *    `connect_timeout`; Guzzle has none by default), ideally the same value as the StreamClient's
 *    timeoutMs. A timeout the client reports is still a retryable `network` error, with the
 *    client's own words.
 *
 * The reply body is read from the PSR-7 stream up to StreamClient::MAX_REPLY_BYTES and no
 * further (0.1.1); a stream whose size is known to be larger is not read at all. The client may
 * have buffered more before handing the reply over (Guzzle and Symfony read the whole body by
 * default): to stop a large reply in transit, use your client's own limit (Guzzle's `on_headers`
 * or `progress` callback, Symfony's `on_progress`).
 */
final class Psr18Transport implements TransportInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(#[\SensitiveParameter] Request $request): Response
    {
        $psr = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psr = $psr->withBody($this->streamFactory->createStream($request->body));
        }
        try {
            $reply = $this->client->sendRequest($psr);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), false, $e);
        }
        $headers = [];
        foreach ($reply->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        $body = self::readUpToTheCap($reply->getBody());
        return $body === null
            ? new Response($reply->getStatusCode(), $headers, '', true)
            : new Response($reply->getStatusCode(), $headers, $body);
    }

    /**
     * The body, read from its start in chunks up to the cap; null when it is past the cap. Never (string) $stream:
     * that would copy the whole body, however large, before its length could be checked. A stream that fails
     * mid-read is a body that broke off: a TransportException, so a retryable `network` error.
     */
    private static function readUpToTheCap(StreamInterface $stream): ?string
    {
        $max = StreamClient::MAX_REPLY_BYTES;
        try {
            $size = $stream->getSize();
            if ($size !== null && $size > $max) {
                return null;
            }
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = '';
            while (!$stream->eof()) {
                $chunk = $stream->read(min(65536, $max + 1 - strlen($body)));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > $max) {
                    return null;
                }
            }
            return $body;
        } catch (\RuntimeException $e) {
            throw new TransportException($e->getMessage(), false, $e);
        }
    }
}
