<?php

declare(strict_types=1);

namespace VoltKit\Rtc;

/**
 * Every failed call fails with this one error (spec 5.1). It carries the server's own code
 * (`no-room`, `capacity-exhausted`), `network` when no reply arrived, `http-<status>` when a
 * reply named none, `redirected`, `rates-unconfigured`, or `bad-request` for an id refused
 * locally; the HTTP status; and whether trying again later could work. The message is
 * "<METHOD> <path>: <what went wrong>" and is always safe to log: the API key and any URL
 * credentials have been redacted from it (spec 5.5).
 *
 * \Exception::$code is an int, so getCode() is the HTTP status (0 when nothing reached the
 * server) and the string code is $errorCode / getErrorCode(). Mistakes in your own setup are
 * not StreamErrors: they are \InvalidArgumentException, \RangeException or PHP's own
 * \TypeError, raised before anything is sent (spec 5.4).
 */
final class StreamError extends \RuntimeException
{
    /**
     * @param string $errorCode the machine-readable code; any string, since a newer controller may send one this release does not know
     * @param int $status the HTTP status; 0 when the request never reached the server or the SDK refused the call itself; the 3xx for `redirected`
     * @param string $message "<METHOD> <path>: <what went wrong>", redacted
     * @param bool $retryable true when trying again later could plausibly succeed (a network failure, a 429, a 5xx other than billing-unavailable)
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly bool $retryable,
    ) {
        parent::__construct($message, $status);
    }

    /** The machine-readable code: the server's, or the SDK's own (`network`, `http-<status>`, `redirected`, ...). */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The HTTP status; 0 when nothing reached the server. The same value as getCode(). */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Whether trying again later could work. When true, the SDK has already spent its retries on a repeatable call. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /** The room, stream or message is gone, or was never yours (status 404). `broadcast-disabled` and `chat-disabled` are 404s too: test isBroadcastDisabled() first. */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** The account cannot use more right now: out of minutes or credits, or a daily or monthly limit reached (402, or those codes). */
    public function isOutOfMinutes(): bool
    {
        return $this->status === 402
            || $this->errorCode === 'quota-exhausted'
            || $this->errorCode === 'daily-limit-reached'
            || $this->errorCode === 'monthly-limit-reached';
    }

    /** At your own concurrent-room limit (`room-quota`), or any 429: `rate-limited`, `viewer-quota`, `stream-quota` included. */
    public function isQuota(): bool
    {
        return $this->errorCode === 'room-quota' || $this->status === 429;
    }

    /** The fleet is full (`capacity-exhausted`, `no-nodes`): the one to alert on, since it is about capacity rather than correctness. */
    public function isCapacityExhausted(): bool
    {
        return $this->errorCode === 'capacity-exhausted' || $this->errorCode === 'no-nodes';
    }

    /** Broadcast is not switched on for this deployment. Not something a retry fixes. */
    public function isBroadcastDisabled(): bool
    {
        return $this->errorCode === 'broadcast-disabled';
    }

    /** The project is billed from its wallet and no price list is active, or the controller has no wallet billing: an operator fixes it, not a retry. */
    public function isBillingUnavailable(): bool
    {
        return $this->errorCode === 'billing-unavailable';
    }
}
