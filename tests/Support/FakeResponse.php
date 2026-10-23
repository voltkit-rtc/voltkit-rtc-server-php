<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/** A PSR-7 response with a status, headers and a body. */
final class FakeResponse implements ResponseInterface
{
    use FakeMessage;

    /** @param array<string, string> $headers */
    public function __construct(private int $status, array $headers, string $body)
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = [$value];
        }
        $this->body = new FakeStream($body);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $copy = clone $this;
        $copy->status = $code;
        return $copy;
    }

    public function getReasonPhrase(): string
    {
        return '';
    }
}
