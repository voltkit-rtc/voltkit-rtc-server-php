<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 5.1, 5.2 and 5.4: the one error type, its fields, its predicates, and what is not a StreamError. */
final class StreamErrorTest extends TestCase
{
    use MakesClients;

    #[Test]
    public function itCarriesCodeStatusMessageAndRetryable(): void
    {
        $e = new StreamError('no-room', 404, 'GET /v1/rooms/r: room not found', false);
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('no-room', $e->getErrorCode());
        self::assertSame('no-room', $e->errorCode);
        self::assertSame(404, $e->getStatus());
        self::assertSame(404, $e->status);
        self::assertSame(404, $e->getCode(), '\Exception::$code is an int, so it is the HTTP status; the string code is errorCode');
        self::assertFalse($e->isRetryable());
        self::assertFalse($e->retryable);
        self::assertSame('GET /v1/rooms/r: room not found', $e->getMessage());
        self::assertSame('GET /v1/rooms/r: room not found', (string) $e->getMessage());
    }

    #[Test]
    public function readonlyFieldsCannotBeChanged(): void
    {
        $e = new StreamError('no-room', 404, 'm', false);
        $this->expectException(\Error::class);
        (new \ReflectionProperty($e, 'errorCode'))->setValue($e, 'other');
    }

    /** @return iterable<string, array{0: string, 1: int, 2: array<string, bool>}> */
    public static function predicates(): iterable
    {
        $none = ['isNotFound' => false, 'isOutOfMinutes' => false, 'isQuota' => false, 'isCapacityExhausted' => false, 'isBroadcastDisabled' => false, 'isBillingUnavailable' => false];
        yield 'no-room 404' => ['no-room', 404, ['isNotFound' => true] + $none];
        yield 'no-stream 404' => ['no-stream', 404, ['isNotFound' => true] + $none];
        yield 'not-found 404' => ['not-found', 404, ['isNotFound' => true] + $none];
        yield 'broadcast-disabled 404: both' => ['broadcast-disabled', 404, ['isNotFound' => true, 'isBroadcastDisabled' => true] + $none];
        yield 'chat-disabled 404' => ['chat-disabled', 404, ['isNotFound' => true] + $none];
        yield 'quota-exhausted 402' => ['quota-exhausted', 402, ['isOutOfMinutes' => true] + $none];
        yield 'daily-limit-reached 402' => ['daily-limit-reached', 402, ['isOutOfMinutes' => true] + $none];
        yield 'monthly-limit-reached 402' => ['monthly-limit-reached', 402, ['isOutOfMinutes' => true] + $none];
        yield 'quota-exhausted by code alone' => ['quota-exhausted', 0, ['isOutOfMinutes' => true] + $none];
        yield 'any 402 by status alone' => ['some-code', 402, ['isOutOfMinutes' => true] + $none];
        yield 'room-quota 429' => ['room-quota', 429, ['isQuota' => true] + $none];
        yield 'room-quota by code alone' => ['room-quota', 0, ['isQuota' => true] + $none];
        yield 'rate-limited 429' => ['rate-limited', 429, ['isQuota' => true] + $none];
        yield 'viewer-quota 429' => ['viewer-quota', 429, ['isQuota' => true] + $none];
        yield 'RATE_LIMITED 429 from the website API' => ['RATE_LIMITED', 429, ['isQuota' => true] + $none];
        yield 'capacity-exhausted 503' => ['capacity-exhausted', 503, ['isCapacityExhausted' => true] + $none];
        yield 'no-nodes 503' => ['no-nodes', 503, ['isCapacityExhausted' => true] + $none];
        yield 'billing-unavailable 503' => ['billing-unavailable', 503, ['isBillingUnavailable' => true] + $none];
        yield 'unavailable 503' => ['unavailable', 503, $none];
        yield 'tenant-suspended 403' => ['tenant-suspended', 403, $none];
        yield 'network 0' => ['network', 0, $none];
        yield 'redirected 302' => ['redirected', 302, $none];
        yield 'http-200' => ['http-200', 200, $none];
        yield 'NOT_FOUND 404 from the website API' => ['NOT_FOUND', 404, ['isNotFound' => true] + $none];
        yield 'a code from a newer server, 418' => ['some-code-from-a-newer-server', 418, $none];
    }

    /** @param array<string, bool> $expected */
    #[Test]
    #[DataProvider('predicates')]
    public function thePredicatesAnswerFromCodeAndStatusAlone(string $code, int $status, array $expected): void
    {
        $e = new StreamError($code, $status, 'm', false);
        $actual = [
            'isNotFound' => $e->isNotFound(),
            'isOutOfMinutes' => $e->isOutOfMinutes(),
            'isQuota' => $e->isQuota(),
            'isCapacityExhausted' => $e->isCapacityExhausted(),
            'isBroadcastDisabled' => $e->isBroadcastDisabled(),
            'isBillingUnavailable' => $e->isBillingUnavailable(),
        ];
        ksort($actual);
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function theTwoLocalRefusalsAreStreamErrorsWithStatusZero(): void
    {
        $t = new FakeTransport();
        $c = $this->client($t);
        $e = $this->failure(fn () => $c->deleteRoom('..'));
        self::assertSame(['bad-request', 0, false], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        $e = $this->failure(fn () => $c->getRates());
        self::assertSame(['rates-unconfigured', 0, false], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        self::assertSame(0, $t->attempts());
    }

    #[Test]
    public function itIsSafeToLogWhole(): void
    {
        $key = 'sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0';
        $t = new FakeTransport(['status' => 401, 'body' => ['error' => "Invalid API key: $key", 'message' => "$key is not a key we know"]]);
        $e = $this->failure(fn () => $this->client($t, apiKey: $key, retries: 0)->listRooms());
        $everything = (string) $e . "\n" . print_r($e, true) . "\n" . json_encode(['error' => $e->getMessage(), 'code' => $e->getErrorCode()]);
        self::assertFalse(self::holdsPartOf($everything, $key));
        self::assertSame('Invalid API key: [redacted]', $e->getErrorCode());
        self::assertSame('GET /v1/rooms: [redacted] is not a key we know', $e->getMessage());
    }
}
