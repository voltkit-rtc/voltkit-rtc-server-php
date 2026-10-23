<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/** PSR-17 request and stream factories over the fakes. */
final class FakePsr17 implements RequestFactoryInterface, StreamFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new FakeRequest($method, (string) $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return new FakeStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return new FakeStream((string) file_get_contents($filename));
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new FakeStream((string) stream_get_contents($resource));
    }
}
