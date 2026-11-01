<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 3.6: how a reply is read. */
final class ReplyTest extends TestCase
{
    use MakesClients;

    #[Test]
    public function aSuccessWithAnEmptyBodyResolvesToNothing(): void
    {
        $t = new FakeTransport(['status' => 200]);
        $c = $this->client($t);
        $c->deleteRoom('r');
        $c->muteChat('lobby', 'u');
        $c->unmuteChat('lobby', 'u');
        $c->deleteMessage('lobby', '1-0');
        $c->deleteStream('s');
        self::assertSame(5, $t->attempts(), 'each resolved without a value');
    }

    #[Test]
    public function aSuccessWithAJsonBodyIsTheValueAsSent(): void
    {
        $reply = ['room' => ['roomId' => 'r', 'demo' => true, 'metadata' => ['seats' => 8]], 'participants' => [], 'producers' => [['id' => 'p1']]];
        $t = new FakeTransport(['status' => 200, 'body' => $reply]);
        self::assertSame($reply, $this->client($t)->getRoom('r'), 'unknown fields such as producers pass through');
    }

    #[Test]
    public function aSuccessThatIsNotJsonFailsWithHttpStatusAndIsNotRetried(): void
    {
        $t = new FakeTransport(['status' => 200, 'raw' => '<html>proxy</html>']);
        $e = $this->failure(fn () => $this->client($t)->listRooms());
        self::assertSame('http-200', $e->getErrorCode());
        self::assertSame(200, $e->getStatus());
        self::assertFalse($e->isRetryable());
        self::assertSame('GET /v1/rooms: the reply is not JSON: <html>proxy</html>', $e->getMessage());
        self::assertSame(1, $t->attempts(), 'the same page would come back');

        $t = new FakeTransport(['status' => 200, 'raw' => str_repeat('y', 120)]);
        $e = $this->failure(fn () => $this->client($t)->listRooms());
        self::assertSame('GET /v1/rooms: the reply is not JSON: ' . str_repeat('y', 100), $e->getMessage(), 'the first 100 characters');
    }

    #[Test]
    public function aSuccessWhoseJsonIsNotAnObjectWhereOneIsNeededFailsWithHttpStatus(): void
    {
        // Every SDK refuses it now (spec 3.6, D4), in its own words: nothing of the reply is quoted (0.1.2).
        $t = new FakeTransport(['status' => 200, 'raw' => '"a string"']);
        $e = $this->failure(fn () => $this->client($t)->getRoom('r'));
        self::assertSame('http-200', $e->getErrorCode());
        self::assertFalse($e->isRetryable());
        self::assertSame('GET /v1/rooms/r: the reply is not the expected shape: it is not an object', $e->getMessage());
        self::assertSame(1, $t->attempts());

        $t = new FakeTransport(['status' => 200]);
        $e = $this->failure(fn () => $this->client($t)->getUsage());
        self::assertSame('GET /v1/usage: the reply is not the expected shape: it is not an object', $e->getMessage(), 'an empty 2xx where a value is needed');
    }

    #[Test]
    public function theControllersErrorShapeGivesCodeAndMessage(): void
    {
        $t = new FakeTransport(['status' => 404, 'body' => ['error' => 'no-room', 'message' => 'room not found']]);
        $e = $this->failure(fn () => $this->client($t)->createToken('show-42', 'u', 'viewer'));
        self::assertSame('no-room', $e->getErrorCode());
        self::assertSame(404, $e->getStatus());
        self::assertSame(404, $e->getCode(), 'getCode() is the HTTP status: \Exception::$code is an int');
        self::assertFalse($e->isRetryable());
        self::assertSame('POST /v1/rooms/show-42/tokens: room not found', $e->getMessage());
    }

    #[Test]
    public function theWebsiteApisErrorShapeGivesCodeAndMessageToo(): void
    {
        $t = new FakeTransport(['status' => 500, 'body' => ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred'], 'requestId' => 'req_1']]);
        $e = $this->failure(fn () => $this->client($t, ratesBaseUrl: 'https://api.example.com')->getRates());
        self::assertSame('INTERNAL_ERROR', $e->getErrorCode(), 'the website API\'s own code passes through');
        self::assertSame(500, $e->getStatus());
        self::assertTrue($e->isRetryable());
        self::assertSame('GET /api/v1/billing/rtc/rates/current: An unexpected error occurred', $e->getMessage());
    }

    #[Test]
    public function emptyStringsCountAsAbsent(): void
    {
        $t = new FakeTransport(['status' => 500, 'body' => ['error' => '', 'message' => 'boom']]);
        $e = $this->failure(fn () => $this->client($t)->getUsage());
        self::assertSame('http-500', $e->getErrorCode());
        self::assertSame('GET /v1/usage: boom', $e->getMessage());

        $t = new FakeTransport(['status' => 404, 'body' => ['error' => 'no-room', 'message' => '']]);
        $e = $this->failure(fn () => $this->client($t)->getRoom('show-42'));
        self::assertSame('no-room', $e->getErrorCode());
        self::assertSame('GET /v1/rooms/show-42: {"error":"no-room","message":""}', $e->getMessage(), 'the whole body text when there is no message');
    }

    #[Test]
    public function aReplyThatNamesNoCodeIsHttpStatusWithTheTextCutAt300(): void
    {
        $t = new FakeTransport(['status' => 500, 'raw' => '"oops"']);
        $e = $this->failure(fn () => $this->client($t)->getUsage());
        self::assertSame('http-500', $e->getErrorCode());
        self::assertSame('GET /v1/usage: "oops"', $e->getMessage(), 'JSON that is not an object has neither code nor message');

        $t = new FakeTransport(['status' => 500, 'body' => ['success' => false, 'error' => ['message' => 'An unexpected error occurred']]]);
        $e = $this->failure(fn () => $this->client($t)->getUsage());
        self::assertSame('http-500', $e->getErrorCode());
        self::assertSame('GET /v1/usage: An unexpected error occurred', $e->getMessage(), 'a nested error object without a code');

        $page = '<html>' . str_repeat('x', 400) . '</html>';
        $t = new FakeTransport(['status' => 503, 'raw' => $page]);
        $e = $this->failure(fn () => $this->client($t)->listStreams());
        self::assertSame('http-503', $e->getErrorCode());
        self::assertSame('GET /v1/streams: ' . substr($page, 0, 300), $e->getMessage());
        self::assertTrue($e->isRetryable(), 'a 503 may pass, whatever answered it');

        $t = new FakeTransport(['status' => 502, 'body' => ['error' => [], 'message' => 'ignored']]);
        $e = $this->failure(fn () => $this->client($t)->getUsage());
        self::assertSame('http-502', $e->getErrorCode(), 'an error field that is an empty object names no code');
    }

    #[Test]
    public function retryableIsFiveHundredsAndTooManyRequestsExceptBillingUnavailable(): void
    {
        $cases = [
            [500, 'internal', true], [502, 'http-502', true], [503, 'unavailable', true], [503, 'capacity-exhausted', true],
            [429, 'rate-limited', true], [429, 'viewer-quota', true], [503, 'billing-unavailable', false],
            [400, 'bad-request', false], [401, 'unauthorized', false], [402, 'quota-exhausted', false], [403, 'tenant-suspended', false],
            [404, 'no-room', false], [409, 'room-exists', false], [418, 'some-code-from-a-newer-server', false], [500, 'panic', true],
        ];
        foreach ($cases as [$status, $code, $retryable]) {
            $t = str_starts_with($code, 'http-')
                ? new FakeTransport(['status' => $status, 'raw' => 'page'])
                : new FakeTransport(['status' => $status, 'body' => ['error' => $code, 'message' => 'm']]);
            $e = $this->failure(fn () => $this->client($t, retries: 0)->getBalance());
            self::assertSame($code, $e->getErrorCode(), "$status $code: an unknown code passes through unchanged");
            self::assertSame($status, $e->getStatus());
            self::assertSame($retryable, $e->isRetryable(), "$status $code: retryable");
        }
    }

    #[Test]
    public function aNumberInTheCodeOrMessageFieldCountsAsAbsent(): void
    {
        $t = new FakeTransport(['status' => 500, 'body' => ['error' => 12, 'message' => ['not' => 'a string']]]);
        $e = $this->failure(fn () => $this->client($t, retries: 0)->getUsage());
        self::assertSame('http-500', $e->getErrorCode());
        self::assertSame('GET /v1/usage: {"error":12,"message":{"not":"a string"}}', $e->getMessage());
    }
}
