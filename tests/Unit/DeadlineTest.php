<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 3.4 against the fake transport: the deadline's error, and a body that breaks off. The real transport: tests/Transport. */
final class DeadlineTest extends TestCase
{
    use MakesClients;

    #[Test]
    public function theDeadlinePassingIsANetworkErrorNamingTheConfiguredMilliseconds(): void
    {
        $t = new FakeTransport(['hangs' => true]);
        $e = $this->failure(fn () => $this->client($t, retries: 0)->listRooms());
        self::assertSame('network', $e->getErrorCode());
        self::assertSame(0, $e->getStatus());
        self::assertTrue($e->isRetryable());
        self::assertSame('GET /v1/rooms: timed out after 10000ms', $e->getMessage());

        $t = new FakeTransport(['hangs' => true]);
        $e = $this->failure(fn () => $this->client($t, retries: 0, timeoutMs: 20)->listRooms());
        self::assertSame('GET /v1/rooms: timed out after 20ms', $e->getMessage());
        self::assertSame(20, $t->last()->timeoutMs, 'the transport is handed the deadline');
    }

    #[Test]
    public function aTimeoutUsesTheRetryBudgetOnARepeatableCallAndIsSentOnceOtherwise(): void
    {
        $t = new FakeTransport(['hangs' => true]);
        $this->failure(fn () => $this->client($t, retries: 1)->listRooms());
        self::assertSame(2, $t->attempts(), 'a timeout is retryable, and listRooms is safe to repeat');

        $t = new FakeTransport(['hangs' => true]);
        $e = $this->failure(fn () => $this->client($t, retries: 2)->sendMessage('c', 'hi'));
        self::assertSame(1, $t->attempts(), 'sendMessage is never repeated: the message may have been stored');
        self::assertSame('POST /v1/chat/c/messages: timed out after 10000ms', $e->getMessage());
        self::assertTrue($e->isRetryable(), 'still reported retryable, so the caller knows what happened');
    }

    #[Test]
    public function aBodyThatBreaksOffIsANetworkErrorWithTheTransportsWordsAndIsRetried(): void
    {
        $t = new FakeTransport(['bodyDropped' => true], ['status' => 200, 'body' => ['rooms' => [['roomId' => 'r']]]]);
        $out = $this->client($t)->listRooms();
        self::assertSame([['roomId' => 'r']], $out['rooms']);
        self::assertSame(2, $t->attempts());

        $t = new FakeTransport(['bodyDropped' => true]);
        $e = $this->failure(fn () => $this->client($t)->getBalance());
        self::assertSame('network', $e->getErrorCode());
        self::assertSame(0, $e->getStatus());
        self::assertTrue($e->isRetryable());
        self::assertSame('GET /v1/billing/balance: TypeError: terminated', $e->getMessage());
        self::assertSame(3, $t->attempts(), 'retries 2 is three attempts');
    }

    #[Test]
    public function aTransportThatThrowsSomethingElseIsStillANetworkError(): void
    {
        $t = new class implements \VoltKit\Rtc\Transport\TransportInterface {
            public int $calls = 0;

            public function send(\VoltKit\Rtc\Transport\Request $request): \VoltKit\Rtc\Transport\Response
            {
                $this->calls++;
                throw new \RuntimeException('socket closed by https://u:p@proxy.test');
            }
        };
        $this->waits = [];
        $c = new \VoltKit\Rtc\StreamClient('https://x.test', 'sk_test', retries: 1, transport: $t, sleeper: static function (int $ms): void {
        });
        $e = $this->failure(static fn () => $c->listRooms());
        self::assertSame('network', $e->getErrorCode());
        self::assertSame('GET /v1/rooms: socket closed by https://[redacted]@proxy.test', $e->getMessage(), 'redacted, like any transport words');
        self::assertSame(2, $t->calls);
        self::assertNull($e->getPrevious(), 'the transport\'s unredacted exception is not attached');
    }
}
