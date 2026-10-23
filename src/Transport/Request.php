<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

use VoltKit\Rtc\Internal\Redactor;

/**
 * One HTTP request, as the client hands it to a transport. The headers hold the API key
 * (X-API-Key) on requests to the controller, and the URL any credentials the base URL had: a
 * Request is a credential, and a transport treats it as one. print_r(), var_dump() and
 * json_encode() of a Request show the key header and the URL's credentials as [redacted], so a
 * transport that logs what it was given does not leak them by accident; a dumper that reads the
 * properties themselves (Symfony's VarDumper behind Laravel's dd()) shows them as they are.
 */
final class Request implements \JsonSerializable
{
    /**
     * @param string $method GET, POST, PUT, PATCH or DELETE
     * @param string $url the absolute URL, credentials included when the base URL had them
     * @param array<string, string> $headers the headers to send, exactly these
     * @param ?string $body the JSON body, already serialized, or null for a request without one
     * @param int $timeoutMs the deadline for the whole exchange, from sending to the last byte of the body
     * @param bool $followRedirects true only for the public rates, which carry no credential
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly int $timeoutMs,
        public readonly bool $followRedirects,
    ) {
    }

    /**
     * The headers with the API key shown as [redacted].
     *
     * @return array<string, string>
     */
    private function safeHeaders(): array
    {
        $safe = $this->headers;
        foreach (array_keys($safe) as $name) {
            if (strcasecmp($name, 'X-API-Key') === 0 || strcasecmp($name, 'Authorization') === 0) {
                $safe[$name] = '[redacted]';
            }
        }
        return $safe;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->method,
            'url' => Redactor::redactUrlCredentials($this->url),
            'headers' => $this->safeHeaders(),
            'body' => $this->body,
            'timeoutMs' => $this->timeoutMs,
            'followRedirects' => $this->followRedirects,
        ];
    }
}
