<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Transport;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\Loopback;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\CurlTransport;
use VoltKit\Rtc\Transport\Request;

/**
 * The real curl transport against a loopback server (spec 11, item 3): the deadline covering a
 * stalled body, a body that breaks off, redirects on every verb, the rates following one, a
 * non-JSON 200, a slow server inside the deadline, and what the wire really carries.
 */
final class CurlTransportTest extends TestCase
{
    use MakesClients;

    private static ?Loopback $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = Loopback::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        self::server()->forget();
    }

    private static function server(): Loopback
    {
        self::assertNotNull(self::$server);
        return self::$server;
    }

    private function real(string $scenario, int $timeoutMs = 2000, int $retries = 0, string $apiKey = 'sk_test', ?string $ratesScenario = null): StreamClient
    {
        $this->waits = [];
        return new StreamClient(
            self::server()->baseUrl($scenario),
            $apiKey,
            $ratesScenario === null ? null : self::server()->baseUrl($ratesScenario),
            $timeoutMs,
            $retries,
            new CurlTransport(),
            sleeper: function (int $ms): void {
                $this->waits[] = $ms;
            },
        );
    }

    /**
     * What the /echo scenario sent back: the request as the server saw it.
     *
     * @param array<string, mixed> $reply
     * @return array{method: string, path: string, headers: array<string, string>, body: string}
     */
    private static function wire(array $reply): array
    {
        self::assertIsString($reply['method'] ?? null);
        self::assertIsString($reply['path'] ?? null);
        self::assertIsArray($reply['headers'] ?? null);
        self::assertIsString($reply['body'] ?? null);
        $headers = [];
        foreach ($reply['headers'] as $name => $value) {
            self::assertIsString($value);
            $headers[(string) $name] = $value;
        }
        return ['method' => $reply['method'], 'path' => $reply['path'], 'headers' => $headers, 'body' => $reply['body']];
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    private function requestsSeen(int $atLeast): array
    {
        for ($i = 0; $i < 50 && count(self::server()->requests()) < $atLeast; $i++) {
            usleep(20_000);
        }
        return self::server()->requests();
    }

    #[Test]
    public function aBodyThatStallsAfterTheHeadersFailsWithTheTimeoutWithinTimeoutMs(): void
    {
        $c = $this->real('stall', timeoutMs: 150);
        $started = hrtime(true);
        $e = $this->failure(static fn () => $c->listRooms());
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        self::assertSame('network', $e->getErrorCode());
        self::assertSame(0, $e->getStatus());
        self::assertTrue($e->isRetryable());
        self::assertSame('GET /v1/rooms: timed out after 150ms', $e->getMessage(), 'the same error as a request that never got headers');
        self::assertLessThan(1500, $elapsedMs, "bounded by timeoutMs, took {$elapsedMs} ms");
        self::assertCount(1, $this->requestsSeen(1));
    }

    #[Test]
    public function aStalledBodyUsesTheRetryBudgetOnARepeatableCallAndIsSentOnceOtherwise(): void
    {
        $c = $this->real('stall', timeoutMs: 100, retries: 1);
        $e = $this->failure(static fn () => $c->listRooms());
        self::assertSame('GET /v1/rooms: timed out after 100ms', $e->getMessage());
        self::assertCount(2, $this->requestsSeen(2), 'a timeout is retryable, and listRooms is safe to repeat');
        self::assertCount(1, $this->waits);

        $e = $this->failure(static fn () => $c->sendMessage('c', 'hi'));
        self::assertSame('POST /v1/chat/c/messages: timed out after 100ms', $e->getMessage());
        self::assertCount(3, $this->requestsSeen(3), 'sendMessage is never repeated: the message may have been stored');
    }

    #[Test]
    public function aSlowBodyThatFinishesInsideTheDeadlineSucceeds(): void
    {
        self::assertSame(['rooms' => []], $this->real('slow', timeoutMs: 2000)->listRooms());
    }

    #[Test]
    public function aBodyThatBreaksOffIsRetriedAndTheRetrySucceeds(): void
    {
        self::assertSame(['rooms' => []], $this->real('break-once', retries: 2)->listRooms());
        self::assertCount(2, $this->requestsSeen(2));
    }

    #[Test]
    public function aBodyThatAlwaysBreaksSpendsTheBudgetThenFailsWithARetryableNetworkError(): void
    {
        $e = $this->failure(fn () => $this->real('break', retries: 2)->getBalance());
        self::assertSame('network', $e->getErrorCode());
        self::assertSame(0, $e->getStatus());
        self::assertTrue($e->isRetryable());
        self::assertStringStartsWith('GET /v1/billing/balance: cURL error ', $e->getMessage());
        self::assertCount(3, $this->requestsSeen(3));
    }

    #[Test]
    public function aRedirectIsNeverFollowedWithTheKeyOnAnyVerb(): void
    {
        $c = $this->real('redirect', retries: 2);
        $port = self::server()->port;
        $calls = [
            'GET /v1/billing/balance' => static fn () => $c->getBalance(),
            'POST /v1/rooms' => static fn () => $c->createRoom('h'),
            'PUT /v1/rooms/r' => static fn () => $c->ensureRoom('r', 'h'),
            'PATCH /v1/streams/s' => static fn () => $c->updateStream('s', 'public'),
            'DELETE /v1/rooms/r' => static fn () => $c->deleteRoom('r'),
            'PUT /v1/chat/lobby/mutes/u' => static fn () => $c->muteChat('lobby', 'u'),
        ];
        foreach ($calls as $where => $call) {
            $e = $this->failure($call);
            self::assertSame('redirected', $e->getErrorCode(), $where);
            self::assertSame(302, $e->getStatus(), $where);
            self::assertFalse($e->isRetryable(), $where);
            $path = explode(' ', $where)[1];
            self::assertSame(
                "$where: the server answered 302 to http://127.0.0.1:$port/landed$path; a request carrying the API key is never redirected. Point baseUrl at the controller itself (its exact scheme and host)",
                $e->getMessage(),
            );
        }
        $seen = $this->requestsSeen(count($calls));
        self::assertCount(count($calls), $seen, 'one request each: not followed, not retried');
        foreach ($seen as $request) {
            self::assertStringStartsWith('/redirect/', $request['path'], 'the key never went to /landed');
            self::assertSame('sk_test', $request['headers']['x-api-key']);
        }
    }

    #[Test]
    public function theRatesMayFollowARedirectAndCarryNoKey(): void
    {
        $rates = $this->real('ok', ratesScenario: 'rates-redirect')->getRates();
        self::assertSame(3, $rates['version']);
        $seen = $this->requestsSeen(2);
        self::assertSame(['/rates-redirect/api/v1/billing/rtc/rates/current', '/rates/api/v1/billing/rtc/rates/current'], array_column($seen, 'path'));
        foreach ($seen as $request) {
            self::assertArrayNotHasKey('x-api-key', $request['headers'], 'no credential is at stake there');
            self::assertArrayNotHasKey('authorization', $request['headers']);
            self::assertSame('voltkit-rtc-php/' . StreamClient::VERSION, $request['headers']['user-agent']);
        }
    }

    #[Test]
    public function aSuccessThatIsNotJsonIsHttp200AndIsNotRetried(): void
    {
        $e = $this->failure(fn () => $this->real('html', retries: 2)->listRooms());
        self::assertSame('http-200', $e->getErrorCode());
        self::assertFalse($e->isRetryable());
        self::assertSame('GET /v1/rooms: the reply is not JSON: <html>proxy</html>', $e->getMessage());
        self::assertCount(1, $this->requestsSeen(1));
    }

    #[Test]
    public function theWireCarriesTheTrimmedKeyTheUserAgentAndTheBodyAsSerialized(): void
    {
        $c = $this->real('echo', apiKey: " sk_real\r\n");
        $echo = self::wire($c->ensureRoom('r', 'h', ['seats' => 8, 'text' => 'ü/房间']));
        self::assertSame('PUT', $echo['method']);
        self::assertSame('/echo/v1/rooms/r', $echo['path']);
        self::assertSame('sk_real', $echo['headers']['x-api-key'], 'whitespace around the key is dropped');
        self::assertSame('application/json', $echo['headers']['content-type']);
        self::assertSame('voltkit-rtc-php/' . StreamClient::VERSION, $echo['headers']['user-agent']);
        self::assertSame('{"hostIdentity":"h","metadata":{"seats":8,"text":"ü/房间"}}', $echo['body']);
        self::assertArrayNotHasKey('expect', $echo['headers'], 'no Expect: 100-continue dance');

        $tabbed = self::wire($this->real('echo', apiKey: "sk_a\tb")->getRoom('r'));
        self::assertSame("sk_a\tb", $tabbed['headers']['x-api-key'], 'a tab inside the key is kept');
    }

    #[Test]
    public function aCallWithoutABodySendsNoneAndNoContentType(): void
    {
        $this->real('ok')->muteChat('lobby', 'u');
        $this->real('ok')->deleteRoom('r');
        $seen = $this->requestsSeen(2);
        self::assertSame(['PUT', 'DELETE'], array_column($seen, 'method'));
        foreach ($seen as $request) {
            self::assertSame('', $request['body']);
            self::assertArrayNotHasKey('content-type', $request['headers']);
            self::assertArrayNotHasKey('transfer-encoding', $request['headers']);
        }
    }

    #[Test]
    public function aRealServerDeletesTheRoomAndDropsTheReplyThenDeleteRoomResolves(): void
    {
        $this->real('drop-once', retries: 2)->deleteRoom('r');
        self::assertCount(2, $this->requestsSeen(2), 'once to delete, once to find it gone');
    }

    #[Test]
    public function aStatusFromTheServerIsRetriedPerThePolicy(): void
    {
        $e = $this->failure(fn () => $this->real('status/503', retries: 2)->getBalance());
        self::assertSame('from-the-server', $e->getErrorCode());
        self::assertSame(503, $e->getStatus());
        self::assertCount(3, $this->requestsSeen(3));
        self::assertSame(2, count($this->waits));
    }

    #[Test]
    public function aRefusedConnectionIsANetworkErrorThatQuotesNoCredential(): void
    {
        foreach (['http://admin:hunter2pw@127.0.0.1:9', 'http://admin:hunter2@pw@127.0.0.1:9'] as $baseUrl) {
            $c = new StreamClient($baseUrl, 'sk_test', retries: 0, timeoutMs: 2000);
            $e = $this->failure(static fn () => $c->listRooms());
            self::assertSame('network', $e->getErrorCode());
            self::assertSame(0, $e->getStatus());
            self::assertTrue($e->isRetryable());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringStartsWith('GET /v1/rooms: cURL error ', $e->getMessage());
        }
    }

    #[Test]
    public function theTransportRefusesProtocolsOtherThanHttpAndHttps(): void
    {
        $transport = new CurlTransport();
        try {
            $transport->send(new Request('GET', 'file:///etc/hostname', [], null, 1000, false));
            self::fail('read a file');
        } catch (\VoltKit\Rtc\Transport\TransportException $e) {
            self::assertStringContainsString('cURL error', $e->getMessage());
        }
    }

    #[Test]
    public function extraCurlOptionsAreAppliedButCannotReEnableRedirects(): void
    {
        $transport = new CurlTransport([CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'ignored']);
        $c = new StreamClient(self::server()->baseUrl('redirect'), 'sk_test', retries: 0, transport: $transport);
        $e = $this->failure(static fn () => $c->getBalance());
        self::assertSame('redirected', $e->getErrorCode());
        $seen = $this->requestsSeen(1);
        self::assertCount(1, $seen);
        self::assertSame('voltkit-rtc-php/' . StreamClient::VERSION, $seen[0]['headers']['user-agent']);
    }

    #[Test]
    public function aBaseUrlWithoutASchemeIsRefusedRatherThanSentOverPlainHttp(): void
    {
        // Review 2026-10-23, finding 1: given "127.0.0.1:<port>/echo", curl guessed http:// and this server received
        // the key in clear text. The constructor refuses a baseUrl that is not an http(s) origin.
        $schemeless = substr(self::server()->baseUrl('echo'), strlen('http://'));
        try {
            new StreamClient($schemeless, 'sk_test_cleartext', retries: 0, timeoutMs: 2000);
            self::fail('a baseUrl without a scheme was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('baseUrl', $e->getMessage());
        }
        usleep(50_000);
        self::assertSame([], self::server()->requests(), 'nothing reached the server');
    }
}
