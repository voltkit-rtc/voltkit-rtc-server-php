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
 * Redirects: a request carrying the key must never follow one. The SDK refuses a 3xx that comes
 * back, but a client that followed one has already sent X-API-Key to the host it named, and PSR-18
 * gives the adapter no say. Symfony's Psr18Client and HttplugClient follow up to 20 by default and
 * keep every header but Authorization and Cookie across hosts (the testers' report of 2026-10-31:
 * the key reached the other host), so for a request carrying the key this adapter sends through
 * their withOptions(['max_redirects' => 0]) copy (Symfony 6.2 and later; 0.1.2), and the public
 * rates through the client as given. Guzzle's sendRequest() never follows (its PSR-18 path forces
 * `allow_redirects` off). Any other client must be built not to follow: check yours.
 *
 * One thing the adapter cannot do for you (checked against Guzzle 7 and Symfony HttpClient 7):
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
    /** Symfony's PSR-18 clients, which follow redirects by default and can be told not to (withoutRedirects()). */
    private const SYMFONY_CLIENTS = [
        'Symfony\\Component\\HttpClient\\Psr18Client',
        'Symfony\\Component\\HttpClient\\HttplugClient',
    ];

    /** The client for a request that carries the key: one that follows no redirect where the adapter can see to it. */
    private readonly ClientInterface $keyClient;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        $this->keyClient = self::withoutRedirects($client);
    }

    /**
     * `$client` for requests that carry the key: for Symfony's PSR-18 clients a copy that follows no redirect, made
     * by their withOptions() (Symfony 6.2 and later); any other client as it is.
     */
    private static function withoutRedirects(ClientInterface $client): ClientInterface
    {
        // By name: both classes are final, and Symfony is no dependency of this package.
        if (in_array($client::class, self::SYMFONY_CLIENTS, true) && method_exists($client, 'withOptions')) {
            $copy = $client->withOptions(['max_redirects' => 0]);
            return $copy instanceof ClientInterface ? $copy : $client;
        }
        return $client;
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
            // The public rates carry no credential and may follow a redirect, as the caller's client does.
            $reply = ($request->followRedirects ? $this->client : $this->keyClient)->sendRequest($psr);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), false, $e);
        }
        $headers = [];
        foreach ($reply->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        $status = $reply->getStatusCode();
        if (!$request->followRedirects && $status >= 300 && $status < 400) {
            // A redirect the key never follows is decided at its headers (spec 3.5): the body is not read, so a
            // stalled or endless one cannot turn `redirected` into a retried `network` error.
            $reply->getBody()->close();
            return new Response($status, $headers, '');
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
