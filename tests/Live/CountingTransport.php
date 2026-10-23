<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Live;

use VoltKit\Rtc\Transport\CurlTransport;
use VoltKit\Rtc\Transport\Request;
use VoltKit\Rtc\Transport\Response;
use VoltKit\Rtc\Transport\TransportInterface;

/** A transport that counts attempts, so "one attempt" can be asserted against the real server in the live smoke. */
final class CountingTransport implements TransportInterface
{
    public int $sent = 0;

    public function __construct(private readonly TransportInterface $inner = new CurlTransport())
    {
    }

    public function send(Request $request): Response
    {
        $this->sent++;
        return $this->inner->send($request);
    }

    /** The attempts since the last call, and a reset. */
    public function take(): int
    {
        $n = $this->sent;
        $this->sent = 0;
        return $n;
    }
}
