<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 4.1: the retry policy, with the sleeper injected so no test waits. */
final class RetriesTest extends TestCase
{
    use MakesClients;

    private const UNAVAILABLE = ['status' => 503, 'body' => ['error' => 'unavailable', 'message' => 'from the server']];

    #[Test]
    public function theWaitsAre200Then400Then800Then1600MillisecondsPlusUpTo100OfJitter(): void
    {
        $t = new FakeTransport(self::UNAVAILABLE);
        $this->failure(fn () => $this->client($t, retries: 4)->getBalance());
        self::assertSame(5, $t->attempts());
        self::assertCount(4, $this->waits, 'one wait before each retry, none before the first attempt');
        foreach ([200, 400, 800, 1600] as $i => $base) {
            self::assertGreaterThanOrEqual($base, $this->waits[$i], "retry $i");
            self::assertLessThanOrEqual($base + 100, $this->waits[$i], "retry $i");
        }
    }

    #[Test]
    public function theJitterVaries(): void
    {
        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $t = new FakeTransport(self::UNAVAILABLE);
            $this->failure(fn () => $this->client($t, retries: 1)->getBalance());
            $seen[$this->waits[0]] = true;
        }
        self::assertGreaterThan(1, count($seen), 'a fleet of servers must not retry in step');
    }

    #[Test]
    public function withoutASleeperTheClientReallySleeps(): void
    {
        $t = new FakeTransport(self::UNAVAILABLE);
        $c = new StreamClient('https://x.test', 'sk_test', retries: 1, transport: $t);
        $started = hrtime(true);
        $this->failure(fn () => $c->getBalance());
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        self::assertGreaterThanOrEqual(190, $elapsedMs, 'the first retry waits about 200 ms');
        self::assertLessThan(1000, $elapsedMs);
    }

    #[Test]
    public function theWaitsStopDoublingAtTheLongestTimeoutSoAHugeRetryCountCannotOverflow(): void
    {
        // Review 2026-10-23, finding 7: 200 * 2 ** 56 overflows PHP's int into a float, and the 58th attempt died
        // with a TypeError from sleep() instead of waiting. The wait is held at MAX_TIMEOUT_MS, as the deadline is.
        $t = new FakeTransport(self::UNAVAILABLE);
        $this->failure(fn () => $this->client($t, retries: 60)->getBalance());
        self::assertSame(61, $t->attempts());
        self::assertCount(60, $this->waits);
        foreach ($this->waits as $i => $wait) {
            self::assertLessThanOrEqual(StreamClient::MAX_TIMEOUT_MS + StreamClient::BACKOFF_JITTER_MAX_MS, $wait, "retry $i");
        }
        self::assertGreaterThanOrEqual(StreamClient::MAX_TIMEOUT_MS, $this->waits[59], 'held at the cap, not wrapped around');
        self::assertLessThanOrEqual(1600 + 100, $this->waits[3], 'the early waits are unchanged');
    }

    #[Test]
    public function aTransientFailureIsRetriedUntilTheBudgetIsSpentAndTheLastErrorIsReported(): void
    {
        $t = new FakeTransport(
            ['status' => 503, 'body' => ['error' => 'unavailable', 'message' => 'first']],
            ['status' => 503, 'body' => ['error' => 'unavailable', 'message' => 'second']],
            ['status' => 503, 'body' => ['error' => 'unavailable', 'message' => 'third']],
            ['status' => 200, 'body' => ['ok' => true]],
        );
        $e = $this->failure(fn () => $this->client($t, retries: 2)->getBalance());
        self::assertSame(3, $t->attempts());
        self::assertSame('GET /v1/billing/balance: third', $e->getMessage(), 'the last error, not the first');
        self::assertTrue($e->isRetryable(), 'so the caller knows the SDK has already tried');
    }

    #[Test]
    public function aLaterAttemptThatSucceedsWins(): void
    {
        $t = new FakeTransport(self::UNAVAILABLE, ['status' => 200, 'body' => ['ok' => true]]);
        self::assertSame(['ok' => true], $this->client($t)->getBalance());
        self::assertSame(2, $t->attempts());

        $t = new FakeTransport(self::UNAVAILABLE, self::UNAVAILABLE, ['status' => 200, 'body' => ['ok' => true]]);
        self::assertSame(['ok' => true], $this->client($t)->getBalance());
        self::assertSame(3, $t->attempts());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function transientSteps(): iterable
    {
        yield '500 internal' => [['status' => 500, 'body' => ['error' => 'internal']]];
        yield '503 unavailable' => [self::UNAVAILABLE];
        yield '502 proxy page' => [['status' => 502, 'raw' => '<html>502 Bad Gateway</html>']];
        yield '429 rate-limited' => [['status' => 429, 'body' => ['error' => 'rate-limited']]];
        yield 'nothing landed' => [['network' => true]];
        yield 'the body broke off' => [['bodyDropped' => true]];
        yield 'the deadline passed' => [['hangs' => true]];
    }

    /** @param array<string, mixed> $step */
    #[Test]
    #[DataProvider('transientSteps')]
    public function everyTransientFailureIsRetriedOnARepeatableCall(array $step): void
    {
        $t = new FakeTransport($step);
        $e = $this->failure(fn () => $this->client($t)->createToken('r', 'u', 'viewer'));
        self::assertSame(3, $t->attempts());
        self::assertTrue($e->isRetryable());
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function nonTransientSteps(): iterable
    {
        yield '409 room-exists' => [['status' => 409, 'body' => ['error' => 'room-exists']], 'room-exists'];
        yield '404 no-room' => [['status' => 404, 'body' => ['error' => 'no-room']], 'no-room'];
        yield '400 bad-request' => [['status' => 400, 'body' => ['error' => 'bad-request']], 'bad-request'];
        yield '401 unauthorized' => [['status' => 401, 'body' => ['error' => 'unauthorized']], 'unauthorized'];
        yield '402 quota-exhausted' => [['status' => 402, 'body' => ['error' => 'quota-exhausted']], 'quota-exhausted'];
        yield '403 tenant-suspended' => [['status' => 403, 'body' => ['error' => 'tenant-suspended']], 'tenant-suspended'];
        yield '503 billing-unavailable: the one 503 an operator fixes' => [['status' => 503, 'body' => ['error' => 'billing-unavailable']], 'billing-unavailable'];
        yield 'a redirect' => [['status' => 302, 'headers' => ['location' => 'https://elsewhere.test/']], 'redirected'];
        yield 'a 200 that is not JSON' => [['status' => 200, 'raw' => '<html>proxy</html>'], 'http-200'];
    }

    /** @param array<string, mixed> $step */
    #[Test]
    #[DataProvider('nonTransientSteps')]
    public function aDecisionTheServerMadeIsNotRetried(array $step, string $code): void
    {
        $t = new FakeTransport($step, ['status' => 200, 'body' => ['ok' => true]]);
        $e = $this->failure(fn () => $this->client($t)->getBalance());
        self::assertSame(1, $t->attempts());
        self::assertSame($code, $e->getErrorCode());
        self::assertFalse($e->isRetryable());
        self::assertSame([], $this->waits);
    }

    /** @return iterable<string, array{0: callable(StreamClient): mixed}> */
    public static function neverRepeatedCalls(): iterable
    {
        yield 'createRoom' => [static fn (StreamClient $c) => $c->createRoom('h')];
        yield 'createStream' => [static fn (StreamClient $c) => $c->createStream('s')];
        yield 'sendMessage' => [static fn (StreamClient $c) => $c->sendMessage('c', 'hi')];
    }

    #[Test]
    #[DataProvider('neverRepeatedCalls')]
    public function createsAndSendsAreSentOnceHoweverTheyFail(callable $call): void
    {
        foreach (self::transientSteps() as $what => [$step]) {
            $t = new FakeTransport($step, ['status' => 201, 'body' => []]);
            $e = $this->failure(fn () => $call($this->client($t, retries: 2)));
            self::assertSame(1, $t->attempts(), "$what: sent once");
            self::assertTrue($e->isRetryable(), "$what: the flag still says a later try could work");
        }
    }

    #[Test]
    public function retriesZeroTurnsRetriesOff(): void
    {
        $t = new FakeTransport(self::UNAVAILABLE, ['status' => 200, 'body' => ['ok' => true]]);
        $e = $this->failure(fn () => $this->client($t, retries: 0)->getBalance());
        self::assertSame(1, $t->attempts());
        self::assertSame('unavailable', $e->getErrorCode());
        self::assertTrue($e->isRetryable());
    }

    #[Test]
    public function aRetryCarriesNoKeyToTheRatesHostEither(): void
    {
        $t = new FakeTransport(
            ['status' => 500, 'body' => ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'x']]],
            ['status' => 200, 'body' => ['success' => true, 'data' => ['version' => 3]]],
        );
        self::assertSame(['version' => 3], $this->client($t, ratesBaseUrl: 'https://api.example.com')->getRates());
        self::assertSame(2, $t->attempts());
        foreach ($t->calls as $r) {
            self::assertArrayNotHasKey('X-API-Key', $r->headers);
        }
    }

    #[Test]
    public function errorsRaisedBeforeSendingAreNeverRetried(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => []]);
        $c = $this->client($t);
        $this->failure(fn () => $c->deleteRoom('..'));
        $this->failure(fn () => $c->getRates());
        try {
            $c->ensureRoom('r', 'h', ['x' => NAN]);
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(0, $t->attempts());
        self::assertSame([], $this->waits);
    }
}
