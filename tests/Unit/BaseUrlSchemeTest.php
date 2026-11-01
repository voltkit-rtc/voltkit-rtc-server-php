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
        yield 'one slash after the scheme' => ['https:/api.voltkit.in'];
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
            self::assertStringStartsWith('baseUrl must be an absolute http or https URL such as https://api.voltkit.in', $e->getMessage());
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
        yield 'an IDN host in its ASCII form' => ['https://xn--r8jz45g.jp', 'https://xn--r8jz45g.jp/v1/rooms'];
        yield 'a host with an underscore, as a container name may have' => ['http://rtc_controller:8080', 'http://rtc_controller:8080/v1/rooms'];
        // Whitespace around it is dropped, as for the key: a line read from a file works (PHP011-4).
        yield 'a line feed after it, the report\'s example' => ["http://127.0.0.1:19101/\n", 'http://127.0.0.1:19101/v1/rooms'];
        yield 'a leading space' => [' https://api.voltkit.in', 'https://api.voltkit.in/v1/rooms'];
        yield 'CR LF and a tab' => ["\thttps://api.voltkit.in\r\n", 'https://api.voltkit.in/v1/rooms'];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function urlsNoRequestCouldUse(): iterable
    {
        $space = 'baseUrl holds a space, a control character, a backslash or a character outside ASCII';
        $host = 'baseUrl must name a host, and a port only as a number from 1 to 65535';
        $query = 'baseUrl must not hold a query or a fragment';
        // PHP011-4: these passed the constructor and then failed every call as a retried network error.
        yield 'a line feed inside' => ["http://127.0.0.1:19101\n/v1", $space];
        yield 'a space in the host' => ['https://api voltkit.in', $space];
        yield 'a NUL' => ["https://api.voltkit.in/\0", $space];
        yield 'a backslash' => ['https://api.voltkit.in\\v1', $space];
        yield 'an IDN host as it is: curl cannot send it' => ['https://例え.jp', $space];
        yield 'a character outside ASCII in the path' => ['https://api.voltkit.in/café', $space];
        yield 'a query' => ['https://api.voltkit.in?region=eu', $query];
        yield 'a fragment' => ['https://api.voltkit.in/#top', $query];
        yield 'a port that is not a number' => ['https://api.voltkit.in:port', $host];
        yield 'an empty port, from an unset variable' => ['https://api.voltkit.in:', $host];
        yield 'port 0' => ['https://api.voltkit.in:0', $host];
        yield 'a port past 65535' => ['https://api.voltkit.in:65536', $host];
        yield 'no host' => ['https:///v1', $host];
        yield 'an IPv6 address that is none' => ['http://[1:2:3]:8080', $host];
        yield 'a host curl refuses: a quote left from a .env file' => ['https://api.voltkit.in"', $host];
        yield 'a host curl refuses: a comma' => ['https://api.voltkit.in,', $host];
    }

    #[Test]
    #[DataProvider('urlsNoRequestCouldUse')]
    public function aBaseUrlNoRequestCouldUseIsRefusedWhenTheClientIsMadeAndNeverQuoted(string $baseUrl, string $sense): void
    {
        foreach (['baseUrl', 'ratesBaseUrl'] as $option) {
            $t = new FakeTransport();
            try {
                $option === 'baseUrl'
                    ? new StreamClient($baseUrl, 'sk_test', transport: $t)
                    : new StreamClient('https://x.test', 'sk_test', $baseUrl, transport: $t);
                self::fail("accepted $option " . var_export($baseUrl, true));
            } catch (\InvalidArgumentException $e) {
                self::assertTrue(str_starts_with($e->getMessage(), str_replace('baseUrl', $option, $sense)), $e->getMessage());
                self::assertStringEndsWith('(it is not shown here: it may hold credentials)', $e->getMessage());
                self::assertStringNotContainsString(trim($baseUrl), $e->getMessage(), 'the URL is not quoted');
                self::assertNull($e->getPrevious());
            }
            self::assertSame(0, $t->attempts(), 'nothing is sent');
        }
    }

    #[Test]
    public function nothingLeftIsMissingForBaseUrlAndUnsetForRatesBaseUrl(): void
    {
        foreach (['', ' ', "\n", '/', ' / '] as $url) {
            try {
                new StreamClient($url, 'sk_test', transport: new FakeTransport());
                self::fail('accepted ' . var_export($url, true));
            } catch (\InvalidArgumentException $e) {
                self::assertSame($url === '' ? 'baseUrl is required' : 'baseUrl is required: the one given is only whitespace or a slash', $e->getMessage());
            }
            $e = $this->failure(fn () => $this->client(new FakeTransport(), ratesBaseUrl: $url)->getRates());
            self::assertSame('rates-unconfigured', $e->getErrorCode());
        }
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
