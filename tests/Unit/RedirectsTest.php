<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 3.5: a request carrying the key is never redirected; the public rates may follow. */
final class RedirectsTest extends TestCase
{
    use MakesClients;

    /** @return iterable<string, array{0: string, 1: callable(StreamClient): mixed}> */
    public static function everyVerb(): iterable
    {
        yield 'GET' => ['GET /v1/billing/balance', static fn (StreamClient $c) => $c->getBalance()];
        yield 'POST' => ['POST /v1/rooms', static fn (StreamClient $c) => $c->createRoom('h')];
        yield 'PUT' => ['PUT /v1/rooms/r', static fn (StreamClient $c) => $c->ensureRoom('r', 'h')];
        yield 'PUT without a body' => ['PUT /v1/chat/lobby/mutes/u', static fn (StreamClient $c) => $c->muteChat('lobby', 'u')];
        yield 'DELETE' => ['DELETE /v1/rooms/r', static fn (StreamClient $c) => $c->deleteRoom('r')];
        yield 'PATCH' => ['PATCH /v1/streams/s', static fn (StreamClient $c) => $c->updateStream('s', 'public')];
    }

    #[Test]
    #[DataProvider('everyVerb')]
    public function aRedirectWithTheKeyFailsAtOnceOnEveryVerb(string $where, callable $call): void
    {
        $t = new FakeTransport(['status' => 302, 'headers' => ['location' => 'https://elsewhere.test/v1/billing/balance']]);
        $e = $this->failure(fn () => $call($this->client($t)));
        self::assertSame('redirected', $e->getErrorCode());
        self::assertSame(302, $e->getStatus(), 'the 3xx the server answered with');
        self::assertFalse($e->isRetryable(), 'the same baseUrl redirects the same way');
        self::assertSame(
            "$where: the server answered 302 to https://elsewhere.test/v1/billing/balance; a request carrying the API key is never redirected. Point baseUrl at the controller itself (its exact scheme and host)",
            $e->getMessage(),
        );
        self::assertSame(1, $t->attempts(), 'neither followed nor retried, although most of these calls are repeatable');
        self::assertFalse($t->last()->followRedirects, 'the transport is told not to follow');
        self::assertSame('sk_test', $t->last()->headers['X-API-Key']);
    }

    #[Test]
    public function aRedirectWithoutALocationHeaderIsStillOne(): void
    {
        $t = new FakeTransport(['status' => 308]);
        $e = $this->failure(fn () => $this->client($t)->getBalance());
        self::assertSame('redirected', $e->getErrorCode());
        self::assertSame(308, $e->getStatus());
        self::assertSame(
            'GET /v1/billing/balance: the server answered 308; a request carrying the API key is never redirected. Point baseUrl at the controller itself (its exact scheme and host)',
            $e->getMessage(),
        );
    }

    #[Test]
    public function theLocationIsRedactedAndThenCutTo200Characters(): void
    {
        $key = 'sk_live_Zm9v+YmFyLzk/QmF6+cXV4/dGVzdF9rZXk+MTIz/NDU2Nzg==';
        $t = new FakeTransport(['status' => 302, 'headers' => ['Location' => 'https://elsewhere.test/?key=' . rawurlencode($key)]]);
        $e = $this->failure(fn () => $this->client($t, apiKey: $key)->getBalance());
        self::assertStringContainsString(' to https://elsewhere.test/?key=[redacted]; ', $e->getMessage());
        self::assertFalse(self::holdsPartOf($e->getMessage(), $key));

        $long = 'https://elsewhere.test/' . str_repeat('p', 250);
        $t = new FakeTransport(['status' => 301, 'headers' => ['location' => $long]]);
        $e = $this->failure(fn () => $this->client($t)->getBalance());
        self::assertStringContainsString(' to ' . substr($long, 0, 200) . '; a request', $e->getMessage());

        // The cut happens after the redaction, so a key straddling the 200th character cannot leak.
        $t = new FakeTransport(['status' => 301, 'headers' => ['location' => 'https://elsewhere.test/' . str_repeat('p', 160) . $key]]);
        $e = $this->failure(fn () => $this->client($t, apiKey: $key)->getBalance());
        self::assertFalse(self::holdsPartOf($e->getMessage(), $key));
        self::assertStringContainsString('[redacted]', $e->getMessage());
    }

    #[Test]
    public function theRatesRequestMayFollowRedirectsBecauseNoCredentialIsAtStake(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['success' => true, 'data' => ['version' => 3]]]);
        $this->client($t, ratesBaseUrl: 'https://api.example.com')->getRates();
        self::assertTrue($t->last()->followRedirects);
        self::assertArrayNotHasKey('X-API-Key', $t->last()->headers);
    }

    #[Test]
    public function aRedirectTheRatesTransportDidNotFollowIsAnOrdinaryHttpError(): void
    {
        // A PSR-18 client configured not to follow redirects hands the 3xx back; it is not the key-carrying case.
        $t = new FakeTransport(['status' => 302, 'headers' => ['location' => 'https://www.example.com/']]);
        $e = $this->failure(fn () => $this->client($t, ratesBaseUrl: 'https://api.example.com')->getRates());
        self::assertSame('http-302', $e->getErrorCode());
        self::assertSame(302, $e->getStatus());
        self::assertFalse($e->isRetryable());
        self::assertSame(1, $t->attempts());
    }
}
