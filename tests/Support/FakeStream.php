<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\StreamInterface;

/** A string-backed PSR-7 stream: one of the smallest doubles that let Psr18Transport be tested without Guzzle. */
final class FakeStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private string $contents = '')
    {
    }

    public function __toString(): string
    {
        return $this->contents;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->contents);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->position = $offset;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function write(string $string): int
    {
        $this->contents .= $string;
        return strlen($string);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function getContents(): string
    {
        $rest = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);
        return $rest;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
