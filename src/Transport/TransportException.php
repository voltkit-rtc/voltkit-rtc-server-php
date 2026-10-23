<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

/**
 * A transport could not read a complete reply: the connection was refused or dropped, DNS
 * failed, the deadline passed, or the body broke off after the headers. The client reports it
 * as a StreamError with code `network`, status 0, retryable, and redacts the message first
 * (a transport's words may quote the URL, credentials and all). It is never attached to the
 * StreamError as a previous exception, for the same reason.
 */
final class TransportException extends \RuntimeException
{
    /**
     * @param string $message the transport's own words (redacted by the client before they reach a message)
     * @param bool $timedOut whether the deadline passed; the client then says "timed out after <timeoutMs>ms"
     */
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
