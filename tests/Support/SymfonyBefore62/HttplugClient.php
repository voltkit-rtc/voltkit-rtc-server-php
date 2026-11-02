<?php

declare(strict_types=1);

namespace Symfony\Component\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A stand-in for Symfony's HttplugClient before 6.2, under its name, which is how Psr18Transport knows Symfony's
 * clients: no withOptions(), the HttpClientInterface it wraps in a private untyped `client` property (Symfony 5.4's
 * shape, property names included), sendRequest() through it. Not autoloadable and not analysed: a test requires it in
 * a process of its own, where the real class (final; v7.4 is installed) has not been loaded (Psr18SymfonyBefore62Test).
 */
final class HttplugClient implements ClientInterface
{
    private $client;
    private $responseFactory;
    private $streamFactory;

    public function __construct(?object $client = null, ?object $responseFactory = null, ?object $streamFactory = null)
    {
        $this->client = $client;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->request($request->getMethod(), (string) $request->getUri(), ['headers' => $request->getHeaders()]);
    }
}
