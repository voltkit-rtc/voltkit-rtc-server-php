<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

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
        return new Response($reply->getStatusCode(), $headers, (string) $reply->getBody());
    }
}
