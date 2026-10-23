<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

/** One HTTP reply, read in full: the status, the headers (names in lower case) and the body bytes. */
final class Response
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param int $status the HTTP status
     * @param array<string, string> $headers header name => value; names are lower-cased here, so any case is fine
     * @param string $body the body bytes, '' when there were none
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
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
