<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakePsr17;
use VoltKit\Rtc\Tests\Support\FakeSymfonyHttpClient;
use VoltKit\Rtc\Tests\Support\FakeSymfonyHttpClientBeforeFiveThree;
use VoltKit\Rtc\Transport\Psr18Transport;

/**
 * PHP012-3 (0.1.3, D13): Symfony's Psr18Client and HttplugClient before 6.2 have no withOptions(), and 0.1.2 then
 * used the client as given: it followed a redirect with the key and reported the other host's reply as a success.
 * The adapter now builds the non-following copy through the HttpClientInterface such a client wraps (its private
 * `client`, which has had withOptions() since Symfony 5.3), and refuses a client it cannot make safe when the
 * transport is made: a leaked key is worse than a startup error.
 *
 * No Symfony older than 6.2 is installed here (the dev lock has 7.4) and both classes are final, so each test runs in
 * a process of its own and loads a stand-in under the real class's name before the real one can be autoloaded. The
 * testers ran the real versions (their report of 2026-11-01): 5.3.14 to 6.1.12 keep the key home with this fix, and
 * 4.4 to 5.2 are refused.
 */
final class Psr18SymfonyBefore62Test extends TestCase
{
    private const PSR18 = 'Symfony\Component\HttpClient\Psr18Client';
    private const HTTPLUG = 'Symfony\Component\HttpClient\HttplugClient';

    /** @return class-string<ClientInterface> the stand-in, loaded under Symfony's name */
    private static function standIn(string $name): string
    {
        if (class_exists($name, false)) {
            self::fail("$name is already loaded in this process: the stand-in cannot take its place");
        }
        require_once dirname(__DIR__) . '/Support/SymfonyBefore62/' . substr($name, (int) strrpos($name, '\\') + 1) . '.php';
        self::assertTrue(class_exists($name, false) && is_subclass_of($name, ClientInterface::class));
        FakeSymfonyHttpClient::$served = [];
        /** @var class-string<ClientInterface> $name */
        return $name;
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function aPsr18ClientBeforeSixTwoSendsTheKeyThroughANonFollowingCopyAndTheRatesThroughItself(): void
    {
        $this->theKeyGoesThroughANonFollowingCopy(self::standIn(self::PSR18));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function anHttplugClientBeforeSixTwoSendsTheKeyThroughANonFollowingCopyAndTheRatesThroughItself(): void
    {
        $this->theKeyGoesThroughANonFollowingCopy(self::standIn(self::HTTPLUG));
    }

    /** @param class-string<ClientInterface> $class */
    private function theKeyGoesThroughANonFollowingCopy(string $class): void
    {
        $wrapped = new FakeSymfonyHttpClient(['max_redirects' => 20, 'timeout' => 10]);
        $client = new $class($wrapped);
        $psr17 = new FakePsr17();
        $transport = new Psr18Transport($client, $psr17, $psr17);
        $rtc = new StreamClient('https://x.test', 'sk_test', 'https://rates.test', retries: 0, transport: $transport);

        $rtc->getBalance();
        $rtc->getRates();
        self::assertSame(
            [
                ['url' => 'https://x.test/v1/billing/balance', 'options' => ['max_redirects' => 0, 'timeout' => 10]],
                ['url' => 'https://rates.test/api/v1/billing/rtc/rates/current', 'options' => ['max_redirects' => 20, 'timeout' => 10]],
            ],
            FakeSymfonyHttpClient::$served,
            'the key through a copy that follows nothing, its other options kept; the rates through the client as given',
        );
        // The caller's client is unchanged: it still wraps the client it was built with, options and all.
        self::assertSame($wrapped, (new \ReflectionProperty($client, 'client'))->getValue($client));
        self::assertSame(['max_redirects' => 20, 'timeout' => 10], $wrapped->options);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function aSymfonyClientThatCannotBeMadeSafeIsRefusedWhenTheTransportIsMade(): void
    {
        $class = self::standIn(self::PSR18);
        $psr17 = new FakePsr17();
        // Before 5.3 the wrapped client has no withOptions() either: nothing can stop it following with the key.
        $client = new $class(new FakeSymfonyHttpClientBeforeFiveThree());
        try {
            new Psr18Transport($client, $psr17, $psr17);
            self::fail('a client that would follow a redirect with the key was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($class, $e->getMessage());
            self::assertStringContainsString('X-API-Key', $e->getMessage());
            self::assertStringContainsString('symfony/http-client 5.3 or later', $e->getMessage());
        }
        // Nor one that wraps nothing it can copy.
        try {
            new Psr18Transport(new $class(null), $psr17, $psr17);
            self::fail('a client wrapping nothing was accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], FakeSymfonyHttpClient::$served, 'nothing was sent');
        }
    }
}
