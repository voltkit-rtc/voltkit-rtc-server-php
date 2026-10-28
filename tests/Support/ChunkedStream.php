<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 body of $length bytes, made as it is read and never held whole: $prefix, `m` padding, $suffix. Its size may
 * be hidden (a reply read to the close), it may fail after $failAfter bytes (a connection reset mid-body), and it
 * counts what was read, so a test can tell how far a reader went. Reading it whole (a string cast, getContents) is a
 * test failure: the adapter must read in chunks.
 */
final class ChunkedStream implements StreamInterface
{
    public int $bytesRead = 0;
    private int $position = 0;

    public function __construct(
        private readonly string $prefix,
        private readonly int $length,
        private readonly string $suffix = '',
        private readonly bool $sizeKnown = false,
        private readonly ?int $failAfter = null,
    ) {
    }

    public function __toString(): string
    {
        throw new \LogicException('the body was read whole');
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return $this->sizeKnown ? $this->length : null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= $this->length;
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
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->failAfter !== null && $this->position >= $this->failAfter) {
            throw new \RuntimeException('Connection reset by peer');
        }
        $n = max(0, min($length, $this->length - $this->position));
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $at = $this->position + $i;
            if ($at < strlen($this->prefix)) {
                $out .= $this->prefix[$at];
            } elseif ($at >= $this->length - strlen($this->suffix)) {
                $out .= $this->suffix[$at - ($this->length - strlen($this->suffix))];
            } else {
                // The padding in one piece up to the suffix or the end of this read.
                $run = min($n - $i, $this->length - strlen($this->suffix) - $at);
                $out .= str_repeat('m', $run);
                $i += $run - 1;
            }
        }
        $this->position += $n;
        $this->bytesRead += $n;
        return $out;
    }

    public function getContents(): string
    {
        throw new \LogicException('the body was read whole');
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
