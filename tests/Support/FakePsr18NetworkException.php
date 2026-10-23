<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** What a PSR-18 client throws when the request could not be sent. */
final class FakePsr18NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
