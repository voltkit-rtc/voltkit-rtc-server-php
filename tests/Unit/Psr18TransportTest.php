<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakePsr17;
use VoltKit\Rtc\Tests\Support\FakePsr18Client;
use VoltKit\Rtc\Tests\Support\FakeResponse;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\Psr18Transport;
use VoltKit\Rtc\Transport\Request;
use VoltKit\Rtc\Transport\TransportException;

/** The PSR-18 adapter: the request it builds, the reply it reads, the exception it maps. */
final class Psr18TransportTest extends TestCase
{
    use MakesClients;

    #[Test]
    public function itBuildsThePsr7RequestWithMethodUrlHeadersAndBody(): void
    {
        $client = new FakePsr18Client([new FakeResponse(201, ['Content-Type' => 'application/json'], '{"roomId":"r","hostToken":"t"}')]);
        $factory = new FakePsr17();
        $rtc = new StreamClient('https://x.test', 'sk_test', transport: new Psr18Transport($client, $factory, $factory));
        $room = $rtc->ensureRoom('r', 'h', ['seats' => 8]);
        self::assertSame('t', $room['hostToken']);
        self::assertFalse($room['existed']);
        $sent = $client->requests[0];
        self::assertSame('PUT', $sent->getMethod());
        self::assertSame('https://x.test/v1/rooms/r', (string) $sent->getUri());
        self::assertSame('sk_test', $sent->getHeaderLine('X-API-Key'));
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame(StreamClient::USER_AGENT, $sent->getHeaderLine('User-Agent'));
        self::assertSame('{"hostIdentity":"h","metadata":{"seats":8}}', (string) $sent->getBody());
    }

    #[Test]
    public function aRequestWithoutABodySendsAnEmptyStreamAndNoContentType(): void
    {
        $client = new FakePsr18Client([new FakeResponse(200, [], '{"rooms":[]}')]);
        $factory = new FakePsr17();
        $rtc = new StreamClient('https://x.test', 'sk_test', transport: new Psr18Transport($client, $factory, $factory));
        self::assertSame(['rooms' => []], $rtc->listRooms());
        $sent = $client->requests[0];
        self::assertSame('GET', $sent->getMethod());
        self::assertFalse($sent->hasHeader('Content-Type'));
        self::assertSame('', (string) $sent->getBody());
    }

    #[Test]
    public function theReplyHeadersAreReadCaseInsensitivelyForTheRedirectCheck(): void
    {
        $client = new FakePsr18Client([new FakeResponse(302, ['LOCATION' => 'https://elsewhere.test/v1/rooms'], '')]);
        $factory = new FakePsr17();
        $rtc = new StreamClient('https://x.test', 'sk_test', retries: 2, transport: new Psr18Transport($client, $factory, $factory));
        $e = $this->failure(static fn () => $rtc->listRooms());
        self::assertSame('redirected', $e->getErrorCode());
        self::assertStringContainsString(' to https://elsewhere.test/v1/rooms;', $e->getMessage());
        self::assertCount(1, $client->requests, 'the 3xx is the answer: not retried');
    }

    #[Test]
    public function aPsr18NetworkExceptionIsATransportExceptionAndThenARetryableNetworkError(): void
    {
        $factory = new FakePsr17();
        $transport = new Psr18Transport(new FakePsr18Client([null]), $factory, $factory);
        try {
            $transport->send(new Request('GET', 'https://u:p@x.test/v1/rooms', [], null, 1000, false));
            self::fail('no exception');
        } catch (TransportException $e) {
            self::assertFalse($e->timedOut, 'PSR-18 gives no way to tell a timeout apart');
            self::assertStringContainsString('could not reach https://u:p@x.test/v1/rooms', $e->getMessage(), 'the client\'s words, unredacted here');
        }

        $client = new FakePsr18Client([null, new FakeResponse(200, [], '{"rooms":[]}')]);
        $rtc = new StreamClient('https://u:p@x.test', 'sk_test', retries: 2, transport: new Psr18Transport($client, $factory, $factory), sleeper: static function (int $ms): void {
        });
        self::assertSame(['rooms' => []], $rtc->listRooms(), 'retried, as any network failure');
        self::assertCount(2, $client->requests);

        $client = new FakePsr18Client([null]);
        $rtc = new StreamClient('https://u:p@x.test', 'sk_test', retries: 0, transport: new Psr18Transport($client, $factory, $factory));
        $e = $this->failure(static fn () => $rtc->listRooms());
        self::assertSame('network', $e->getErrorCode());
        self::assertSame('GET /v1/rooms: could not reach https://[redacted]@x.test/v1/rooms', $e->getMessage(), 'and redacted by the client');
    }

    #[Test]
    public function multiValuedReplyHeadersAreJoinedWithCommas(): void
    {
        $response = (new FakeResponse(200, [], '{"ok":true}'))->withAddedHeader('X-Many', 'a')->withAddedHeader('X-Many', 'b');
        $factory = new FakePsr17();
        $transport = new Psr18Transport(new FakePsr18Client([$response]), $factory, $factory);
        $reply = $transport->send(new Request('GET', 'https://x.test/v1/rooms', [], null, 1000, false));
        self::assertSame(200, $reply->status);
        self::assertSame('a, b', $reply->header('x-many'));
        self::assertSame('{"ok":true}', $reply->body);
    }
}
