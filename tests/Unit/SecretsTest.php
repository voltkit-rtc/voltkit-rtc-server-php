<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\Request;

/** Spec 2.4: the client never shows its secrets, however it is printed, serialized or inspected. */
final class SecretsTest extends TestCase
{
    use MakesClients;

    private const KEY = 'sk_live_Zm9v+YmFyLzk/QmF6+cXV4/dGVzdF9rZXk+MTIz/NDU2Nzg==';

    private function makeClient(FakeTransport $t): StreamClient
    {
        return $this->client($t, apiKey: self::KEY, baseUrl: 'https://svc:hunter2@x.test/', ratesBaseUrl: 'https://web:s3cret@api.example.com');
    }

    /** @return array<string, string> every way PHP shows an object */
    private function everyPrinting(StreamClient $c): array
    {
        ob_start();
        var_dump($c);
        $varDump = (string) ob_get_clean();
        ob_start();
        debug_zval_dump($c);
        $zvalDump = (string) ob_get_clean();
        return [
            'var_dump' => $varDump,
            'debug_zval_dump' => $zvalDump,
            'print_r' => print_r($c, true),
            'json_encode' => json_encode($c, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            '__toString' => (string) $c,
            'var_export' => var_export($c, true),
            'var_export of the array cast' => var_export((array) $c, true),
            'print_r of the array cast' => print_r((array) $c, true),
        ];
    }

    #[Test]
    public function noPrintingShowsTheKeyOrAUrlPassword(): void
    {
        $c = $this->makeClient(new FakeTransport());
        foreach ($this->everyPrinting($c) as $how => $text) {
            foreach ([self::KEY, 'Zm9v', 'YmFy', 'hunter2', 's3cret'] as $piece) {
                self::assertStringNotContainsString($piece, $text, "$how shows $piece");
            }
        }
    }

    #[Test]
    public function theUrlsAreShownWithTheirCredentialsRedacted(): void
    {
        $c = $this->makeClient(new FakeTransport());
        foreach (['var_dump', 'print_r', 'json_encode', '__toString'] as $how) {
            $text = $this->everyPrinting($c)[$how];
            self::assertStringContainsString('https://[redacted]@x.test', $text, $how);
            self::assertStringContainsString('https://[redacted]@api.example.com', $text, $how);
        }
        self::assertSame(
            ['baseUrl' => 'https://[redacted]@x.test', 'ratesBaseUrl' => 'https://[redacted]@api.example.com', 'timeoutMs' => 10000, 'retries' => 2],
            $c->jsonSerialize(),
        );
        self::assertSame(
            'StreamClient(baseUrl: https://[redacted]@x.test, ratesBaseUrl: https://[redacted]@api.example.com, timeoutMs: 10000, retries: 2)',
            (string) $c,
        );
    }

    #[Test]
    public function urlsWithoutCredentialsAreShownAsGiven(): void
    {
        $c = $this->client(new FakeTransport(), baseUrl: 'https://x.test/', ratesBaseUrl: 'https://api.example.com/');
        self::assertSame(
            'StreamClient(baseUrl: https://x.test, ratesBaseUrl: https://api.example.com, timeoutMs: 10000, retries: 2)',
            (string) $c,
        );
        $c = $this->client(new FakeTransport(), baseUrl: 'https://x.test');
        self::assertSame('StreamClient(baseUrl: https://x.test, ratesBaseUrl: null, timeoutMs: 10000, retries: 2)', (string) $c);
    }

    #[Test]
    public function requestsStillGoToTheUrlsAsGivenWithTheKey(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => [], 'success' => true, 'data' => ['version' => 3]]]);
        $c = $this->makeClient($t);
        $c->listRooms();
        self::assertSame('https://svc:hunter2@x.test/v1/rooms', $t->last()->url);
        self::assertSame(self::KEY, $t->last()->headers['X-API-Key']);
        $c->getRates();
        self::assertSame('https://web:s3cret@api.example.com/api/v1/billing/rtc/rates/current', $t->last()->url);
        self::assertArrayNotHasKey('X-API-Key', $t->last()->headers);
    }

    #[Test]
    public function theClientCannotBeSerialized(): void
    {
        $c = $this->makeClient(new FakeTransport());
        try {
            serialize($c);
            self::fail('serialize() carried the client');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringContainsString('StreamClient', $e->getMessage());
        }
    }

    #[Test]
    public function aClonedClientStillWorksAndStillHidesTheKey(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
        $copy = clone $this->makeClient($t);
        $copy->listRooms();
        self::assertSame(self::KEY, $t->last()->headers['X-API-Key']);
        self::assertStringNotContainsString(self::KEY, print_r($copy, true));
    }

    #[Test]
    public function aPrintedTransportRequestRedactsTheKeyHeader(): void
    {
        // A custom transport that logs the Request object it was handed must not print the key by accident.
        $request = new Request('GET', 'https://x.test/v1/rooms', ['X-API-Key' => self::KEY, 'User-Agent' => 'ua'], null, 10000, false);
        $shown = print_r($request, true);
        self::assertStringNotContainsString(self::KEY, $shown);
        self::assertStringContainsString('[redacted]', $shown);
        self::assertSame(self::KEY, $request->headers['X-API-Key'], 'the transport itself still reads the real header');
        self::assertStringNotContainsString(self::KEY, json_encode($request, JSON_THROW_ON_ERROR));
    }
}
