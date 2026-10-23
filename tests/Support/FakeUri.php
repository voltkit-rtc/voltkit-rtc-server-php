<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\UriInterface;

/** A PSR-7 URI over parse_url(), read-only: the with* methods return the same URI. */
final class FakeUri implements UriInterface
{
    public function __construct(private readonly string $uri)
    {
    }

    public function getScheme(): string
    {
        return (string) parse_url($this->uri, PHP_URL_SCHEME);
    }

    public function getAuthority(): string
    {
        $user = $this->getUserInfo();
        $port = $this->getPort();
        return ($user === '' ? '' : "$user@") . $this->getHost() . ($port === null ? '' : ":$port");
    }

    public function getUserInfo(): string
    {
        $user = parse_url($this->uri, PHP_URL_USER);
        $pass = parse_url($this->uri, PHP_URL_PASS);
        return is_string($user) ? $user . (is_string($pass) ? ":$pass" : '') : '';
    }

    public function getHost(): string
    {
        return (string) parse_url($this->uri, PHP_URL_HOST);
    }

    public function getPort(): ?int
    {
        $port = parse_url($this->uri, PHP_URL_PORT);
        return is_int($port) ? $port : null;
    }

    public function getPath(): string
    {
        return (string) parse_url($this->uri, PHP_URL_PATH);
    }

    public function getQuery(): string
    {
        return (string) parse_url($this->uri, PHP_URL_QUERY);
    }

    public function getFragment(): string
    {
        return (string) parse_url($this->uri, PHP_URL_FRAGMENT);
    }

    public function withScheme(string $scheme): UriInterface
    {
        return $this;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        return $this;
    }

    public function withHost(string $host): UriInterface
    {
        return $this;
    }

    public function withPort(?int $port): UriInterface
    {
        return $this;
    }

    public function withPath(string $path): UriInterface
    {
        return $this;
    }

    public function withQuery(string $query): UriInterface
    {
        return $this;
    }

    public function withFragment(string $fragment): UriInterface
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->uri;
    }
}
