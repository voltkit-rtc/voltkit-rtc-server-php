<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/** A PSR-7 request that keeps method, URI, headers and body. */
final class FakeRequest implements RequestInterface
{
    use FakeMessage;

    private UriInterface $uri;

    public function __construct(private string $method, string $uri)
    {
        $this->uri = new FakeUri($uri);
        $this->body = new FakeStream();
    }

    public function getRequestTarget(): string
    {
        $path = $this->uri->getPath();
        $query = $this->uri->getQuery();
        return ($path === '' ? '/' : $path) . ($query === '' ? '' : "?$query");
    }

    public function withRequestTarget(string $requestTarget): static
    {
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): static
    {
        $copy = clone $this;
        $copy->method = $method;
        return $copy;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $copy = clone $this;
        $copy->uri = $uri;
        return $copy;
    }
}
