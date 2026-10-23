<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client answering from a script of responses (the last repeats), or throwing a network exception for a null step. */
final class FakePsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<?ResponseInterface> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = $this->responses[min(count($this->requests), count($this->responses)) - 1] ?? null;
        if ($response === null) {
            throw new FakePsr18NetworkException('could not reach ' . $request->getUri(), $request);
        }
        return $response;
    }
}
