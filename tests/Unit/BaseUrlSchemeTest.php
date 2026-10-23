<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/**
 * Review 2026-10-23, finding 1: a baseUrl without a scheme ("api.voltkit.in", "127.0.0.1:8080") made curl guess
 * http:// and send the API key in clear text. Node's fetch refuses a relative URL; this SDK refuses it when the
 * client is made (spec 2.1: "the controller's origin, exact scheme and host").
 */
final class BaseUrlSchemeTest extends TestCase
{
    use MakesClients;

    /** @return iterable<string, array{0: string}> */
    public static function urlsWithoutAnHttpOrigin(): iterable
    {
        yield 'no scheme' => ['api.voltkit.in'];
        yield 'host and port, no scheme (curl guesses http)' => ['127.0.0.1:8080'];
        yield 'host with a path, no scheme' => ['api.voltkit.in/v1'];
        yield 'scheme-relative' => ['//api.voltkit.in'];
        yield 'ftp' => ['ftp://api.voltkit.in'];
        yield 'file' => ['file:///etc/hostname'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'a scheme and no host' => ['https://'];
        yield 'a leading space' => [' https://api.voltkit.in'];
        yield 'a lone slash' => ['/'];
    }

    #[Test]
    #[DataProvider('urlsWithoutAnHttpOrigin')]
    public function aBaseUrlThatIsNotAnHttpOriginIsRefusedWhenTheClientIsMade(string $baseUrl): void
    {
        $t = new FakeTransport();
        try {
            new StreamClient($baseUrl, 'sk_test_secret_0123456789', transport: $t);
            self::fail("accepted baseUrl $baseUrl");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('baseUrl', $e->getMessage());
            self::assertStringContainsString('https://', $e->getMessage());
            self::assertStringNotContainsString('sk_test_secret', $e->getMessage());
        }
        self::assertSame(0, $t->attempts(), 'nothing is sent');
    }

    #[Test]
    public function aRatesBaseUrlThatIsNotAnHttpOriginIsRefusedToo(): void
    {
        foreach (['api.voltkit.in', '//api.voltkit.in', 'ftp://api.voltkit.in'] as $ratesBaseUrl) {
            try {
                new StreamClient('https://x.test', 'sk_test', $ratesBaseUrl, transport: new FakeTransport());
                self::fail("accepted ratesBaseUrl $ratesBaseUrl");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('ratesBaseUrl', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function httpOrigins(): iterable
    {
        yield 'https' => ['https://x.test', 'https://x.test/v1/rooms'];
        yield 'an upper-case scheme and host' => ['HTTPS://X.test', 'HTTPS://X.test/v1/rooms'];
        yield 'http, for a controller on your own network' => ['http://127.0.0.1:1234', 'http://127.0.0.1:1234/v1/rooms'];
        yield 'a path prefix' => ['https://x.test/rtc/', 'https://x.test/rtc/v1/rooms'];
        yield 'credentials in the URL' => ['https://u:p@x.test', 'https://u:p@x.test/v1/rooms'];
        yield 'an IPv6 host' => ['https://[::1]:8443', 'https://[::1]:8443/v1/rooms'];
        yield 'an IDN host' => ['https://例え.jp', 'https://例え.jp/v1/rooms'];
    }

    #[Test]
    #[DataProvider('httpOrigins')]
    public function anHttpOrHttpsOriginIsAcceptedAsGiven(string $baseUrl, string $roomsUrl): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
        $this->client($t, baseUrl: $baseUrl)->listRooms();
        self::assertSame($roomsUrl, $t->last()->url);
    }
}
