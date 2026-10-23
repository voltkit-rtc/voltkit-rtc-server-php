<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\StreamInterface;

/** The PSR-7 message half shared by FakeRequest and FakeResponse: headers (case-insensitive names) and a body. */
trait FakeMessage
{
    /** @var array<string, list<string>> */
    private array $headers = [];
    private StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): static
    {
        return $this;
    }

    /** @return array<string, list<string>> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return $this->findHeader($name) !== null;
    }

    /** @return list<string> */
    public function getHeader(string $name): array
    {
        $key = $this->findHeader($name);
        return $key === null ? [] : $this->headers[$key];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /** @param string|list<string> $value */
    public function withHeader(string $name, $value): static
    {
        $copy = clone $this;
        $key = $copy->findHeader($name);
        if ($key !== null) {
            unset($copy->headers[$key]);
        }
        $copy->headers[$name] = is_array($value) ? array_values($value) : [$value];
        return $copy;
    }

    /** @param string|list<string> $value */
    public function withAddedHeader(string $name, $value): static
    {
        $copy = clone $this;
        $key = $copy->findHeader($name) ?? $name;
        $copy->headers[$key] = [...($copy->headers[$key] ?? []), ...(is_array($value) ? array_values($value) : [$value])];
        return $copy;
    }

    public function withoutHeader(string $name): static
    {
        $copy = clone $this;
        $key = $copy->findHeader($name);
        if ($key !== null) {
            unset($copy->headers[$key]);
        }
        return $copy;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $copy = clone $this;
        $copy->body = $body;
        return $copy;
    }

    private function findHeader(string $name): ?string
    {
        foreach (array_keys($this->headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return $key;
            }
        }
        return null;
    }
}
