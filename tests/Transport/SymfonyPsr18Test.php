<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Transport;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakePsr17;
use VoltKit\Rtc\Tests\Support\Loopback;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\Psr18Transport;

/**
 * Psr18Transport with Symfony's Psr18Client as it comes, against the loopback server. That client follows up to 20
 * redirects and keeps every header but Authorization and Cookie across hosts: before 0.1.2, a controller URL that
 * redirected to another host handed that host the API key (the testers' report of 2026-10-31, "worth a look"). The
 * adapter sends a request carrying the key through the client's withOptions(['max_redirects' => 0]) now.
 * symfony/http-client is a dev dependency; without it (an older vendor/) the test is skipped.
 */
final class SymfonyPsr18Test extends TestCase
{
    use MakesClients;

    private static ?Loopback $server = null;

    public static function setUpBeforeClass(): void
    {
        if (class_exists('Symfony\Component\HttpClient\Psr18Client')) {
            self::$server = Loopback::start();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private static function symfony(): ClientInterface
    {
        /** @var class-string $class */
        $class = 'Symfony\Component\HttpClient\Psr18Client';
        if (!class_exists($class)) {
            self::markTestSkipped('symfony/http-client is not installed: composer install in php-sdk/');
        }
        $psr17 = new FakePsr17();
        // As it comes: HttpClient::create() with its defaults, max_redirects 20 among them.
        $client = new $class(null, $psr17, $psr17);
        self::assertInstanceOf(ClientInterface::class, $client);
        return $client;
    }

    private static function server(): Loopback
    {
        self::assertNotNull(self::$server);
        return self::$server;
    }

    #[Test]
    public function aSymfonyClientAsItComesNeverCarriesTheKeyToTheHostARedirectNames(): void
    {
        $client = self::symfony();
        self::server()->forget();
        $psr17 = new FakePsr17();
        $transport = new Psr18Transport($client, $psr17, $psr17);
        $rtc = new StreamClient(self::server()->baseUrl('redirect-elsewhere'), 'sk_must_not_leave', retries: 0, transport: $transport);
        $e = $this->failure(static fn () => $rtc->getBalance());
        self::assertSame(['redirected', 302], [$e->getErrorCode(), $e->getStatus()]);
        self::assertStringContainsString('the server answered 302 to http://localhost:', $e->getMessage());
        $seen = self::server()->requests();
        self::assertCount(1, $seen, 'only the request that was redirected: nothing landed on the other host');
        self::assertSame('/redirect-elsewhere/v1/billing/balance', $seen[0]['path']);

        // The public rates carry no key and still follow, through the client as it was given.
        self::server()->forget();
        $rates = new StreamClient('https://x.test', 'sk_must_not_leave', self::server()->baseUrl('rates-redirect'), transport: $transport);
        self::assertSame(3, $rates->getRates()['version']);
        $paths = array_map(static fn (array $r): string => $r['path'], self::server()->requests());
        self::assertSame(['/rates-redirect/api/v1/billing/rtc/rates/current', '/rates/api/v1/billing/rtc/rates/current'], $paths);
        foreach (self::server()->requests() as $r) {
            self::assertArrayNotHasKey('x-api-key', $r['headers']);
        }
    }
}
