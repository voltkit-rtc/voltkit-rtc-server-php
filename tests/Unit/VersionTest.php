<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 8: the version string and the User-Agent on every request. */
final class VersionTest extends TestCase
{
    use MakesClients;

    #[Test]
    public function theVersionIsSemverAndTheChangelogNamesIt(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', StreamClient::VERSION);
        $changelog = file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        self::assertIsString($changelog);
        self::assertStringContainsString('## ' . StreamClient::VERSION, $changelog);
        self::assertSame('voltkit-rtc-php/' . StreamClient::VERSION, StreamClient::USER_AGENT);
    }

    #[Test]
    public function everyRequestCarriesTheUserAgentAndNothingIdentifyingBeyondIt(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => [], 'success' => true, 'data' => ['version' => 3]]]);
        $c = $this->client($t, ratesBaseUrl: 'https://api.example.com');
        $c->listRooms();
        $c->getRates();
        $c->deleteRoom('r');
        foreach ($t->calls as $r) {
            self::assertSame('voltkit-rtc-php/' . StreamClient::VERSION, $r->headers['User-Agent'], $r->url);
            self::assertStringNotContainsString(PHP_VERSION, $r->headers['User-Agent']);
            self::assertStringNotContainsString(PHP_OS, $r->headers['User-Agent']);
        }
    }
}
