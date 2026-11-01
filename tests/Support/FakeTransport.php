<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use VoltKit\Rtc\Transport\Request;
use VoltKit\Rtc\Transport\Response;
use VoltKit\Rtc\Transport\TransportException;
use VoltKit\Rtc\Transport\TransportInterface;

/**
 * A transport that plays one scripted step per attempt, the last step repeating,
 * and records every request it was handed. The step language is the shared
 * vectors' (docs/server-sdk-vectors.json, sections retry and goneIsDone):
 *   {status, body}        a JSON reply
 *   {status, raw}         a reply whose body is sent as it is (a web page)
 *   {status, headers}     a reply with headers (a redirect's Location)
 *   {network: true}       nothing landed: the transport failed before any reply
 *   {bodyDropped: true}   the headers arrived and the body then broke off
 *   {hangs: true}         the deadline passed
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<Request> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    private readonly array $steps;

    /**
     * A body is sent as json_encode() writes it: an empty or list-like PHP array as a JSON list, a \stdClass as an
     * object. The client tells the two apart (0.1.2), so a reply that must be an object is given its fields, or
     * `new \stdClass()` for {}.
     *
     * @param array<string, mixed> ...$steps
     */
    public function __construct(array ...$steps)
    {
        $this->steps = $steps === [] ? [['status' => 200, 'body' => new \stdClass()]] : array_values($steps);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    public static function answering(int $status, ?array $body = null, array $headers = []): self
    {
        $step = ['status' => $status, 'headers' => $headers];
        if ($body !== null) {
            $step['body'] = $body;
        }
        return new self($step);
    }

    public static function raw(int $status, string $raw): self
    {
        return new self(['status' => $status, 'raw' => $raw]);
    }

    public static function failing(string $message = 'TypeError: fetch failed'): self
    {
        return new self(['network' => true, 'message' => $message]);
    }

    public function send(Request $request): Response
    {
        $step = $this->steps[min(count($this->calls), count($this->steps) - 1)];
        $this->calls[] = $request;
        if (isset($step['hangs'])) {
            throw new TransportException(
                sprintf('Operation timed out after %d milliseconds', $request->timeoutMs),
                timedOut: true,
            );
        }
        if (isset($step['network'])) {
            $message = $step['message'] ?? 'TypeError: fetch failed';
            assert(is_string($message));
            throw new TransportException($message);
        }
        if (isset($step['bodyDropped'])) {
            throw new TransportException('TypeError: terminated');
        }
        if (array_key_exists('raw', $step)) {
            $body = $step['raw'];
            assert(is_string($body));
        } elseif (array_key_exists('body', $step)) {
            $body = json_encode($step['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $body = '';
        }
        $status = $step['status'] ?? 200;
        assert(is_int($status));
        $headers = $step['headers'] ?? [];
        assert(is_array($headers));
        /** @var array<string, string> $headers */
        return new Response($status, $headers, $body);
    }

    public function attempts(): int
    {
        return count($this->calls);
    }

    public function last(): Request
    {
        $last = end($this->calls);
        if ($last === false) {
            throw new \LogicException('nothing was sent');
        }
        return $last;
    }
}
