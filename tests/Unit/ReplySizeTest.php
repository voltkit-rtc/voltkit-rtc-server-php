<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\Request;
use VoltKit\Rtc\Transport\Response;
use VoltKit\Rtc\Transport\TransportInterface;

/**
 * The reply caps (0.1.1; the SDK red team of 2026-10-28, decision 5 of music-addon/SDK_REPAIR_ROUND.md): a reply past
 * 4 MiB, or one holding more than 100,000 objects and arrays, fails with http-<status> before json_decode builds
 * anything, on a 2xx and on an error status alike; a reply at the limits is read and decoded exactly as before. The
 * curl transport's side (it stops reading at the cap) is in tests/Transport/CurlTransportTest.php.
 */
final class ReplySizeTest extends TestCase
{
    use MakesClients;

    private const KEY = 'sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0';
    private const TOO_LARGE = 'GET /v1/rooms: the reply is too large: over 4 MiB, the most this SDK reads';
    private const TOO_MANY = 'GET /v1/rooms: the reply is too large: over 100,000 objects and arrays, the most this SDK decodes';

    /** A valid rooms reply of exactly $bytes bytes: one room, then a field the SDK passes through. */
    private static function paddedRooms(int $bytes): string
    {
        $head = '{"rooms":[{"roomId":"r"}],"pad":"';
        $tail = '"}';
        return $head . str_repeat('m', $bytes - strlen($head) - strlen($tail)) . $tail;
    }

    /** A valid rooms reply holding exactly $containers objects and arrays, the outer object and the list included. */
    private static function emptyRooms(int $containers): string
    {
        return '{"rooms":[' . str_repeat('{},', $containers - 3) . '{}]}';
    }

    /** @return array{0: string, 1: int, 2: bool, 3: string} */
    private static function seen(StreamError $e): array
    {
        return [$e->getErrorCode(), $e->getStatus(), $e->isRetryable(), $e->getMessage()];
    }

    #[Test]
    public function theCapsAreFourMebibytesAndOneHundredThousandObjectsAndArrays(): void
    {
        self::assertSame(4_194_304, StreamClient::MAX_REPLY_BYTES);
        self::assertSame(100_000, StreamClient::MAX_REPLY_CONTAINERS);
    }

    #[Test]
    public function aReplyAtTheCapIsDecodedAndOneByteOverIsRefusedAndNotRetried(): void
    {
        $t = FakeTransport::raw(200, self::paddedRooms(StreamClient::MAX_REPLY_BYTES));
        $rooms = $this->client($t, retries: 2)->listRooms();
        self::assertSame([['roomId' => 'r']], $rooms['rooms']);

        $t = FakeTransport::raw(200, self::paddedRooms(StreamClient::MAX_REPLY_BYTES + 1));
        $e = $this->failure(fn () => $this->client($t, retries: 2)->listRooms());
        self::assertSame(['http-200', 200, false, self::TOO_LARGE], self::seen($e));
        self::assertSame(1, $t->attempts(), 'a 2xx past the cap would come back the same');
        self::assertNull($e->getPrevious());
    }

    #[Test]
    public function nothingOfAReplyPastTheCapReachesTheError(): void
    {
        $body = substr_replace(self::paddedRooms(StreamClient::MAX_REPLY_BYTES + 1), self::KEY, 40, strlen(self::KEY));
        $t = FakeTransport::raw(200, $body);
        $e = $this->failure(fn () => $this->client($t, apiKey: self::KEY)->listRooms());
        self::assertSame(self::TOO_LARGE, $e->getMessage());
        self::assertFalse(self::holdsPartOf((string) $e, self::KEY));
        self::assertStringNotContainsString('mmmm', (string) $e);
    }

    #[Test]
    public function anErrorStatusPastTheCapKeepsTheStatusRule(): void
    {
        // A 503 or a 429 may pass: retried until the budget is spent, then reported retryable.
        foreach ([503, 429] as $status) {
            $t = FakeTransport::raw($status, 'x' . self::paddedRooms(StreamClient::MAX_REPLY_BYTES));
            $e = $this->failure(fn () => $this->client($t, retries: 2)->listRooms());
            self::assertSame(["http-$status", $status, true, self::TOO_LARGE], self::seen($e));
            self::assertSame(3, $t->attempts(), "$status uses the retry budget");
        }
        // A 400 is a decision the server made: not retried. Its code is in the part the SDK does not read.
        $t = FakeTransport::raw(400, '{"error":"bad-request","message":"' . str_repeat('z', StreamClient::MAX_REPLY_BYTES) . '"}');
        $e = $this->failure(fn () => $this->client($t, retries: 2)->listRooms());
        self::assertSame(['http-400', 400, false, self::TOO_LARGE], self::seen($e));
        self::assertSame(1, $t->attempts());
    }

    #[Test]
    public function anErrorBodyUnderTheCapIsRedactedAndCutAsBefore(): void
    {
        $t = FakeTransport::raw(503, 'bad key ' . self::KEY . str_repeat('y', 3 * 1024 * 1024));
        $e = $this->failure(fn () => $this->client($t, retries: 0, apiKey: self::KEY)->listRooms());
        $want = 'GET /v1/rooms: ' . mb_substr('bad key [redacted]' . str_repeat('y', 300), 0, 300);
        self::assertSame(['http-503', 503, true, $want], self::seen($e));

        $t = FakeTransport::raw(404, '{"error":"no-room","message":"room not found","pad":"' . str_repeat('p', 3 * 1024 * 1024) . '"}');
        $e = $this->failure(fn () => $this->client($t, retries: 0)->listRooms());
        self::assertSame(['no-room', 404, false, 'GET /v1/rooms: room not found'], self::seen($e));
    }

    #[Test]
    public function aTransportThatStoppedAtTheCapIsRefusedWithoutItsBodyBeingRead(): void
    {
        $transport = new class implements TransportInterface {
            public int $calls = 0;

            public function send(Request $request): Response
            {
                $this->calls++;
                return new Response(200, ['Content-Type' => 'application/json'], '{"rooms":[]}', true);
            }
        };
        $c = new StreamClient('https://x.test', 'sk_test', retries: 2, transport: $transport, sleeper: static function (int $ms): void {
        });
        $e = $this->failure(static fn () => $c->listRooms());
        self::assertSame(['http-200', 200, false, self::TOO_LARGE], self::seen($e));
        self::assertSame(1, $transport->calls);
    }

    #[Test]
    public function aRedirectPastTheCapIsStillARedirect(): void
    {
        // A request with the key: the 3xx is the answer, whatever its body.
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(302, ['Location' => 'https://elsewhere.test/v1/rooms'], '', true);
            }
        };
        $c = new StreamClient('https://x.test', 'sk_test', retries: 0, transport: $transport);
        $e = $this->failure(static fn () => $c->listRooms());
        self::assertSame('redirected', $e->getErrorCode());
        self::assertSame(302, $e->getStatus());
    }

    #[Test]
    public function upToTheObjectAndArrayLimitAReplyDecodesAndOneMoreIsRefused(): void
    {
        $text = self::emptyRooms(StreamClient::MAX_REPLY_CONTAINERS);
        self::assertSame(StreamClient::MAX_REPLY_CONTAINERS, substr_count($text, '{') + substr_count($text, '['));
        $rooms = $this->client(FakeTransport::raw(200, $text))->listRooms();
        self::assertCount(StreamClient::MAX_REPLY_CONTAINERS - 2, $rooms['rooms']);

        $t = FakeTransport::raw(200, " \n" . self::emptyRooms(StreamClient::MAX_REPLY_CONTAINERS + 1));
        $e = $this->failure(fn () => $this->client($t, retries: 2)->listRooms());
        self::assertSame(['http-200', 200, false, self::TOO_MANY], self::seen($e));
        self::assertSame(1, $t->attempts());

        $t = FakeTransport::raw(502, self::emptyRooms(StreamClient::MAX_REPLY_CONTAINERS + 1));
        $e = $this->failure(fn () => $this->client($t, retries: 1)->listRooms());
        self::assertSame(['http-502', 502, true, self::TOO_MANY], self::seen($e));
        self::assertSame(2, $t->attempts());
    }

    #[Test]
    public function aPageFullOfBracesThatIsNotJsonIsReportedAsBefore(): void
    {
        // Only a reply that could be a JSON object or array is counted: a page is "not JSON", its text cut as before.
        $page = '<html>' . str_repeat('{[', 60_000) . '</html>';
        $e = $this->failure(fn () => $this->client(FakeTransport::raw(200, $page))->listRooms());
        self::assertSame(['http-200', 200, false, 'GET /v1/rooms: the reply is not JSON: ' . substr($page, 0, 100)], self::seen($e));
        $e = $this->failure(fn () => $this->client(FakeTransport::raw(502, $page), retries: 0)->listRooms());
        self::assertSame(['http-502', 502, true, 'GET /v1/rooms: ' . substr($page, 0, 300)], self::seen($e));
    }

    /**
     * The red team's reply shape at its worst: one-element arrays cost json_decode about 232 bytes each, so 4 MiB of
     * `[0],` needs about 243 MB, past PHP's default memory_limit: a fatal error no catch handles. Refused instead.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function aReplyOfSmallArraysUnderTheByteCapIsRefusedNotAFatalError(): void
    {
        ini_set('memory_limit', '128M');
        $text = '{"rooms":[' . str_repeat('[0],', intdiv(StreamClient::MAX_REPLY_BYTES - 16, 4)) . '[0]]}';
        self::assertLessThanOrEqual(StreamClient::MAX_REPLY_BYTES, strlen($text));
        $t = FakeTransport::raw(200, $text);
        unset($text);
        $e = $this->failure(fn () => $this->client($t)->listRooms());
        self::assertSame(['http-200', 200, false, self::TOO_MANY], self::seen($e));
    }

    /**
     * The costliest reply both limits let through decodes under PHP's default memory_limit: 100,000 objects, the
     * costliest kind (about 424 bytes each), the rest of the 4 MiB in short strings (about 12 bytes per byte).
     */
    #[Test]
    #[RunInSeparateProcess]
    public function theCostliestReplyTheLimitsLetThroughDecodesUnderTheDefaultMemoryLimit(): void
    {
        ini_set('memory_limit', '128M');
        $head = '{"rooms":[';
        $objects = str_repeat('{"a":0},', StreamClient::MAX_REPLY_CONTAINERS - 2);
        $room = StreamClient::MAX_REPLY_BYTES - strlen($head) - strlen($objects) - strlen(']}');
        $strings = intdiv($room + 1, 4);
        $text = $head . $objects . str_repeat('"a",', $strings - 1) . '"a"]}';
        self::assertLessThanOrEqual(StreamClient::MAX_REPLY_BYTES, strlen($text));
        self::assertGreaterThan(StreamClient::MAX_REPLY_BYTES - 4, strlen($text));
        self::assertSame(StreamClient::MAX_REPLY_CONTAINERS, substr_count($text, '{') + substr_count($text, '['));
        $t = FakeTransport::raw(200, $text);
        unset($text, $objects);
        $rooms = $this->client($t)->listRooms();
        self::assertCount(StreamClient::MAX_REPLY_CONTAINERS - 2 + $strings, $rooms['rooms']);
    }
}
