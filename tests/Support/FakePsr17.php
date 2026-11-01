<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/** PSR-17 request, response and stream factories over the fakes (a real PSR-18 client builds its replies with them). */
final class FakePsr17 implements RequestFactoryInterface, ResponseFactoryInterface, StreamFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new FakeRequest($method, (string) $uri);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new FakeResponse($code, [], '');
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
