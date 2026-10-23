<?php

declare(strict_types=1);

namespace VoltKit\Rtc;

/** What the shared registry (docs/sdk-contract.json) says about one error code; see ErrorCodes::info(). */
final readonly class ErrorInfo
{
    /**
     * @param string $code the registry code, e.g. "no-room"
     * @param string $origin "server" (the controller or a media node, passed through unchanged) or "sdk" (raised by the SDK itself)
     * @param ?int $http the REST status a reply with this code carries, when it has one; null for socket-only codes and for `redirected` (any 3xx)
     * @param bool $terminal a join or reconnect with the same token must not be retried: a client SDK concern, carried for completeness
     * @param bool $retryable trying the same call again later can succeed
     * @param bool $rest whether the code can reach a caller of this SDK (its surfaces include the tenant REST API)
     */
    public function __construct(
        public string $code,
        public string $origin,
        public ?int $http,
        public bool $terminal,
        public bool $retryable,
        public bool $rest,
    ) {
    }
}
