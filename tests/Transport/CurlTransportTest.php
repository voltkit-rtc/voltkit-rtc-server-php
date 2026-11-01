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
use VoltKit\Rtc\Transport\TransportException;

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
        foreach (['http://admin:hunter2pw@127.0.0.1:9', 'http://admin:hunter2%40pw@127.0.0.1:9'] as $baseUrl) {
            $c = new StreamClient($baseUrl, 'sk_test', retries: 0, timeoutMs: 2000);
            $e = $this->failure(static fn () => $c->listRooms());
            self::assertSame('network', $e->getErrorCode());
            self::assertSame(0, $e->getStatus());
            self::assertTrue($e->isRetryable());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringStartsWith('GET /v1/rooms: cURL error ', $e->getMessage());
        }
        // A raw @ in the password, which curl reads as a host it refuses, is refused when the client is made (0.1.2).
        try {
            new StreamClient('http://admin:hunter2@pw@127.0.0.1:9', 'sk_test');
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
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

    /**
     * User info in baseUrl is a transport question (spec 2.2, vectors options.baseUrls.credentials). curl sends it, as
     * Basic credentials, so with this transport it stays accepted and goes where it was told. But curl takes the FIRST
     * @ for the end of the user info, so a password holding a raw @ left a host curl refuses, and every call failed as
     * a retried network error: that one is refused when the client is made; written as %40 it works.
     */
    #[Test]
    public function curlSendsUserInfoSoItIsAcceptedExceptARawAtWhichCurlCannotSend(): void
    {
        $withUser = static fn (string $userInfo): string => str_replace('http://', "http://$userInfo@", self::server()->baseUrl('echo'));
        foreach (['user:hunter2' => 'user:hunter2', 'hunter2' => 'hunter2:', 'user:p%40hunter2' => 'user:p@hunter2'] as $userInfo => $sent) {
            self::server()->forget();
            (new StreamClient($withUser($userInfo), 'sk_test', retries: 0, timeoutMs: 2000))->getUsage();
            $seen = self::server()->requests();
            self::assertCount(1, $seen, $userInfo);
            self::assertSame('Basic ' . base64_encode($sent), $seen[0]['headers']['authorization'] ?? null, $userInfo);
            self::assertSame('sk_test', $seen[0]['headers']['x-api-key'] ?? null);
            self::assertSame('/echo/v1/usage', $seen[0]['path']);
        }
        self::server()->forget();
        foreach ([null, new CurlTransport()] as $transport) {
            try {
                new StreamClient($withUser('user:p@hunter2'), 'sk_test', transport: $transport);
                self::fail('a raw @ in the password was accepted with curl');
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('baseUrl holds an @ in its user info', $e->getMessage());
                self::assertStringNotContainsString('hunter2', $e->getMessage());
            }
        }
        // Why: curl itself refuses that URL, on every call.
        try {
            (new CurlTransport())->send(new Request('GET', $withUser('user:p@hunter2') . '/v1/rooms', [], null, 2000, false));
            self::fail('curl sent it');
        } catch (TransportException $e) {
            self::assertStringContainsString('cURL error 3', $e->getMessage());
        }
        usleep(50_000);
        self::assertSame([], self::server()->requests(), 'nothing reached the server');
    }

    // --- the reply cap (0.1.1): read up to StreamClient::MAX_REPLY_BYTES and no further ---------------------------

    private const TOO_LARGE = 'the reply is too large: over 4 MiB, the most this SDK reads';

    /** How many bytes the big-stream scenario wrote before the client left, once the server has said so. */
    private function bytesWritten(): int
    {
        for ($i = 0; $i < 250; $i++) {
            foreach (self::server()->requests() as $line) {
                if ($line['method'] === 'WRITTEN') {
                    return (int) $line['body'];
                }
            }
            usleep(20_000);
        }
        self::fail('the server never said what it wrote');
    }

    /** The requests the server received, without its own WRITTEN line. */
    private function gets(): int
    {
        return count(array_filter(self::server()->requests(), static fn (array $r): bool => $r['method'] === 'GET'));
    }

    #[Test]
    public function aStreamedReplyPastTheCapIsRefusedAndNotReadToItsEnd(): void
    {
        // Eight times the cap and no length declared: the transport has to stop reading on its own.
        $total = 8 * StreamClient::MAX_REPLY_BYTES;
        $c = $this->real('big-stream/' . $total, timeoutMs: 10000, retries: 2);
        $started = hrtime(true);
        $e = $this->failure(static fn () => $c->listRooms());
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        self::assertSame('http-200', $e->getErrorCode());
        self::assertSame(200, $e->getStatus());
        self::assertFalse($e->isRetryable());
        self::assertSame('GET /v1/rooms: ' . self::TOO_LARGE, $e->getMessage());
        self::assertLessThan(5000, $elapsedMs);
        self::assertLessThan($total, $this->bytesWritten(), 'the server wrote it all: the client read past the cap');
        self::assertSame(1, $this->gets(), 'a 2xx past the cap is not retried');
    }

    #[Test]
    public function aStreamedReplyAtTheCapIsReadWhole(): void
    {
        $rooms = $this->real('big-stream/' . StreamClient::MAX_REPLY_BYTES, timeoutMs: 10000)->listRooms();
        self::assertSame([['roomId' => 'r']], $rooms['rooms']);
        self::assertSame(StreamClient::MAX_REPLY_BYTES, $this->bytesWritten());
    }

    #[Test]
    public function aDeclaredLengthAtTheCapIsReadAndOneByteMoreIsRefused(): void
    {
        $rooms = $this->real('big/' . StreamClient::MAX_REPLY_BYTES, timeoutMs: 10000)->listRooms();
        self::assertSame([['roomId' => 'r']], $rooms['rooms']);
        $c = $this->real('big/' . (StreamClient::MAX_REPLY_BYTES + 1), timeoutMs: 10000, retries: 2);
        $e = $this->failure(static fn () => $c->listRooms());
        self::assertSame(['http-200', 200, false], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        self::assertSame('GET /v1/rooms: ' . self::TOO_LARGE, $e->getMessage());
    }

    #[Test]
    public function aDeclaredLengthPastTheCapIsRefusedBeforeTheBodyIsRead(): void
    {
        // A gigabyte declared, a few bytes sent, then nothing: the client must not wait for the rest.
        $c = $this->real('declared-huge', timeoutMs: 5000);
        $started = hrtime(true);
        $e = $this->failure(static fn () => $c->listRooms());
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        self::assertSame(['http-200', 200, false], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        self::assertSame('GET /v1/rooms: ' . self::TOO_LARGE, $e->getMessage());
        self::assertLessThan(2000, $elapsedMs, "refused at the headers, took {$elapsedMs} ms");
    }

    #[Test]
    public function aRedirectDeclaringAHugeBodyIsStillARedirectAnsweredAtOnce(): void
    {
        $c = $this->real('redirect-huge', timeoutMs: 5000, retries: 2);
        $started = hrtime(true);
        $e = $this->failure(static fn () => $c->getBalance());
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        self::assertSame('redirected', $e->getErrorCode());
        self::assertSame(302, $e->getStatus());
        self::assertStringContainsString('/landed/v1/billing/balance', $e->getMessage());
        self::assertLessThan(2000, $elapsedMs, "took {$elapsedMs} ms");
        self::assertSame(1, $this->gets());
    }

    /**
     * Spec 3.5 (N049-4): the body of a refused redirect is not read. curl read it to its end, so a 302 whose body
     * stalled held the request until the deadline, and then failed as a retryable `network` error, retried, instead
     * of `redirected` at once.
     */
    #[Test]
    public function aRedirectWhoseBodyStallsIsRefusedAtOnceWithoutReadingIt(): void
    {
        $c = $this->real('redirect-stall', timeoutMs: 5000, retries: 2);
        $started = hrtime(true);
        $e = $this->failure(static fn () => $c->getBalance());
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        self::assertSame(['redirected', 302], [$e->getErrorCode(), $e->getStatus()], $e->getMessage());
        self::assertStringContainsString('/landed/v1/billing/balance', $e->getMessage());
        self::assertLessThan(2000, $elapsedMs, "took {$elapsedMs} ms");
        self::assertSame(1, $this->gets(), 'not retried');
    }

    #[Test]
    public function theRatesFollowARedirectAndTheReplyTheyLandOnIsCappedToo(): void
    {
        $c = $this->real('echo', timeoutMs: 10000, ratesScenario: 'rates-to-big/' . (StreamClient::MAX_REPLY_BYTES + 1));
        $e = $this->failure(static fn () => $c->getRates());
        self::assertSame(['http-200', 200, false], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        self::assertSame('GET ' . StreamClient::RATES_PATH . ': ' . self::TOO_LARGE, $e->getMessage());
        self::assertSame(2, $this->gets(), 'the redirect, then the reply it led to');
    }

    #[Test]
    public function outputOptionsOfTheCallersCannotTakeTheBodyAwayFromTheCap(): void
    {
        $file = tmpfile();
        self::assertIsResource($file);
        $transport = new CurlTransport([
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE => $file,
            CURLOPT_WRITEFUNCTION => static fn (\CurlHandle $h, string $data): int => strlen($data),
        ]);
        $c = new StreamClient(self::server()->baseUrl('ok'), 'sk_test', retries: 0, transport: $transport);
        self::assertSame(['ok' => true], $c->getUsage(), 'the body reaches the client');
        $big = self::server()->baseUrl('big-stream/' . (2 * StreamClient::MAX_REPLY_BYTES));
        $c = new StreamClient($big, 'sk_test', retries: 0, timeoutMs: 10000, transport: $transport);
        $e = $this->failure(static fn () => $c->listRooms());
        self::assertSame('GET /v1/rooms: ' . self::TOO_LARGE, $e->getMessage());
        $stat = fstat($file);
        self::assertIsArray($stat);
        self::assertSame(0, $stat['size'], 'nothing went to the file');
    }
}
