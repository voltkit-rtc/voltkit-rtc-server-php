<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

/**
 * One HTTP reply, read in full: the status, the headers (names in lower case) and the body bytes; or, when the body
 * was past StreamClient::MAX_REPLY_BYTES, the status, the headers and $tooLarge, the body left unread.
 */
final class Response
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param int $status the HTTP status
     * @param array<string, string> $headers header name => value; names are lower-cased here, so any case is fine
     * @param string $body the body bytes, '' when there were none (or when $tooLarge)
     * @param bool $tooLarge the transport stopped reading because the body declared or reached more than
     *   StreamClient::MAX_REPLY_BYTES (0.1.1); the client then fails the call without decoding anything. A transport
     *   that does not set it hands the whole body over, and the client checks its length itself
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
        public readonly bool $tooLarge = false,
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = $value;
        }
        $this->headers = $lower;
    }

    /** A header's value, whatever the case of the name; null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
