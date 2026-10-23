<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\CurlTransport;

/** Spec 2.1 and 2.2: the options, their defaults and what the constructor refuses; 13.8: fromEnv. */
final class ConstructionTest extends TestCase
{
    use MakesClients;

    #[Test]
    public function baseUrlIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl is required');
        new StreamClient('', 'sk_test', transport: new FakeTransport());
    }

    #[Test]
    public function apiKeyIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey is required');
        new StreamClient('https://x.test', '', transport: new FakeTransport());
    }

    #[Test]
    public function constructionErrorsAreNotStreamErrors(): void
    {
        foreach ([fn () => new StreamClient('', 'k'), fn () => new StreamClient('https://x.test', '')] as $make) {
            $thrown = null;
            try {
                $make();
            } catch (\Throwable $t) {
                $thrown = $t;
            }
            // A handler must not mistake a setup mistake for a VoltKit answer: the language's argument error, not a StreamError.
            self::assertInstanceOf(\InvalidArgumentException::class, $thrown);
        }
    }

    #[Test]
    public function aTrailingSlashOnBaseUrlIsDroppedOnce(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
        $this->client($t, baseUrl: 'https://x.test/')->listRooms();
        self::assertSame('https://x.test/v1/rooms', $t->last()->url);

        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
        $this->client($t, baseUrl: 'https://x.test')->listRooms();
        self::assertSame('https://x.test/v1/rooms', $t->last()->url);
    }

    #[Test]
    public function aTrailingSlashOnRatesBaseUrlIsDropped(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['success' => true, 'data' => ['version' => 3]]]);
        $this->client($t, ratesBaseUrl: 'https://api.example.com/')->getRates();
        self::assertSame('https://api.example.com/api/v1/billing/rtc/rates/current', $t->last()->url);
    }

    /** @return iterable<string, array{0: ?string}> */
    public static function unsetRatesBaseUrls(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'a lone slash' => ['/'];
    }

    #[Test]
    #[DataProvider('unsetRatesBaseUrls')]
    public function anEmptyOrSlashRatesBaseUrlCountsAsUnset(?string $ratesBaseUrl): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['success' => true, 'data' => []]]);
        $e = $this->failure(fn () => $this->client($t, ratesBaseUrl: $ratesBaseUrl)->getRates());
        self::assertSame('rates-unconfigured', $e->getErrorCode());
        self::assertSame(0, $e->getStatus());
        self::assertFalse($e->isRetryable());
        self::assertSame(
            'getRates needs ratesBaseUrl: the website API origin that serves the public rates, e.g. https://api.example.com',
            $e->getMessage(),
        );
        self::assertSame(0, $t->attempts(), 'never falls back to asking the controller');
    }

    #[Test]
    public function theDefaultsAreTenSecondsAndTwoRetries(): void
    {
        $t = new FakeTransport(['status' => 503, 'body' => ['error' => 'unavailable']]);
        $c = new StreamClient('https://x.test', 'sk_test', transport: $t, sleeper: static function (int $ms): void {
        });
        $this->failure(fn () => $c->getBalance());
        self::assertSame(3, $t->attempts(), 'retries 2 is three attempts');
        self::assertSame(10000, $t->last()->timeoutMs);
        self::assertSame(StreamClient::DEFAULT_TIMEOUT_MS, 10000);
        self::assertSame(StreamClient::DEFAULT_RETRIES, 2);
    }

    #[Test]
    public function theDefaultTransportIsCurl(): void
    {
        $c = new StreamClient('https://x.test', 'sk_test');
        $shown = print_r($c, true);
        self::assertStringContainsString(CurlTransport::class, $shown);
    }

    /** @return iterable<string, array{0: int}> */
    public static function badTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'very negative' => [PHP_INT_MIN];
    }

    #[Test]
    #[DataProvider('badTimeouts')]
    public function aTimeoutThatIsNotAboveZeroIsRefused(int $timeoutMs): void
    {
        try {
            new StreamClient('https://x.test', 'sk_test', timeoutMs: $timeoutMs, transport: new FakeTransport());
            self::fail('accepted');
        } catch (\RangeException $e) {
            self::assertSame("timeoutMs must be a number above 0, got $timeoutMs", $e->getMessage());
        }
    }

    #[Test]
    public function aTimeoutPastTheLongestATimerHoldsIsHeldThere(): void
    {
        foreach ([2147483647 => 2147483647, 2147483648 => 2147483647, PHP_INT_MAX => 2147483647, 1500 => 1500] as $given => $effective) {
            $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
            $this->client($t, timeoutMs: $given)->listRooms();
            self::assertSame($effective, $t->last()->timeoutMs, "timeoutMs $given");
        }
        self::assertSame(2147483647, StreamClient::MAX_TIMEOUT_MS);
    }

    #[Test]
    public function negativeRetriesAreRefused(): void
    {
        foreach ([-1, -5, PHP_INT_MIN] as $retries) {
            try {
                new StreamClient('https://x.test', 'sk_test', retries: $retries, transport: new FakeTransport());
                self::fail("accepted retries $retries");
            } catch (\RangeException $e) {
                self::assertSame("retries must be a whole number of 0 or more, got $retries", $e->getMessage());
            }
        }
    }

    #[Test]
    public function retriesZeroOneAndMoreAreHonoured(): void
    {
        foreach ([0 => 1, 1 => 2, 2 => 3, 5 => 6] as $retries => $attempts) {
            $t = new FakeTransport(['status' => 503, 'body' => ['error' => 'unavailable']]);
            $this->failure(fn () => $this->client($t, retries: $retries)->listRooms());
            self::assertSame($attempts, $t->attempts(), "retries $retries");
        }
    }

    #[Test]
    public function nonNumbersAreRefusedByTheLanguageBeforeTheConstructorRuns(): void
    {
        // A statically typed language refuses non-numbers at the type check (spec 2.2); the range rules stay ours.
        // Called directly from this strict_types file: a call through Reflection or call_user_func would coerce.
        foreach (['timeoutMs' => ['5000', true, NAN, 1.5], 'retries' => ['2', true, NAN, 1.5, -0.5]] as $option => $values) {
            foreach ($values as $value) {
                try {
                    if ($option === 'timeoutMs') {
                        /** @phpstan-ignore argument.type */
                        new StreamClient('https://x.test', 'sk_test', timeoutMs: $value);
                    } else {
                        /** @phpstan-ignore argument.type */
                        new StreamClient('https://x.test', 'sk_test', retries: $value);
                    }
                    self::fail("$option " . var_export($value, true) . ' accepted');
                } catch (\TypeError $e) {
                    self::assertStringContainsString($option, $e->getMessage());
                }
            }
        }
    }

    #[Test]
    public function fromEnvReadsTheThreeVariables(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => [], 'success' => true, 'data' => ['version' => 3]]]);
        $this->withEnv(
            ['VOLTKIT_RTC_URL' => 'https://env.test/', 'VOLTKIT_RTC_SECRET' => " sk_env\n", 'VOLTKIT_API_URL' => 'https://api.env.test/'],
            function () use ($t): void {
                $c = StreamClient::fromEnv($t);
                $c->listRooms();
                self::assertSame('https://env.test/v1/rooms', $t->last()->url);
                self::assertSame('sk_env', $t->last()->headers['X-API-Key']);
                $c->getRates();
                self::assertSame('https://api.env.test/api/v1/billing/rtc/rates/current', $t->last()->url);
            },
        );
    }

    #[Test]
    public function fromEnvReadsWhatTheFrameworkPutInServerOrEnvArrays(): void
    {
        // Laravel's dotenv writes $_ENV and $_SERVER but not the process environment, so getenv() alone misses it.
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
        $_SERVER['VOLTKIT_RTC_URL'] = 'https://server.test';
        $_ENV['VOLTKIT_RTC_SECRET'] = 'sk_from_env_array';
        try {
            StreamClient::fromEnv($t)->listRooms();
            self::assertSame('https://server.test/v1/rooms', $t->last()->url);
            self::assertSame('sk_from_env_array', $t->last()->headers['X-API-Key']);
        } finally {
            unset($_SERVER['VOLTKIT_RTC_URL'], $_ENV['VOLTKIT_RTC_SECRET']);
        }
    }

    #[Test]
    public function fromEnvNamesTheMissingVariableAndNeverTheValue(): void
    {
        $this->withEnv(['VOLTKIT_RTC_URL' => '', 'VOLTKIT_RTC_SECRET' => 'sk_present', 'VOLTKIT_API_URL' => ''], function (): void {
            try {
                StreamClient::fromEnv(new FakeTransport());
                self::fail('made a client without a URL');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('VOLTKIT_RTC_URL', $e->getMessage());
                self::assertStringNotContainsString('sk_present', $e->getMessage());
            }
        });
        $this->withEnv(['VOLTKIT_RTC_URL' => 'https://x.test', 'VOLTKIT_RTC_SECRET' => '', 'VOLTKIT_API_URL' => ''], function (): void {
            try {
                StreamClient::fromEnv(new FakeTransport());
                self::fail('made a client without a secret');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('VOLTKIT_RTC_SECRET', $e->getMessage());
            }
        });
        $this->withEnv(['VOLTKIT_RTC_URL' => 'https://x.test', 'VOLTKIT_RTC_SECRET' => "\n", 'VOLTKIT_API_URL' => ''], function (): void {
            try {
                StreamClient::fromEnv(new FakeTransport());
                self::fail('made a client with a whitespace secret');
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('apiKey is required: the one given is only whitespace', $e->getMessage());
            }
        });
    }

    #[Test]
    public function fromEnvWithoutTheRatesUrlLeavesRatesUnconfigured(): void
    {
        $this->withEnv(['VOLTKIT_RTC_URL' => 'https://x.test', 'VOLTKIT_RTC_SECRET' => 'sk_test', 'VOLTKIT_API_URL' => ''], function (): void {
            $t = new FakeTransport();
            $e = $this->failure(fn () => StreamClient::fromEnv($t)->getRates());
            self::assertSame('rates-unconfigured', $e->getErrorCode());
        });
    }

    /** @param array<string, string> $vars empty string: unset */
    private function withEnv(array $vars, callable $run): void
    {
        $before = [];
        foreach ($vars as $name => $value) {
            $before[$name] = getenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($value === '' ? $name : "$name=$value");
        }
        try {
            $run();
        } finally {
            foreach ($before as $name => $old) {
                putenv($old === false ? $name : "$name=$old");
            }
        }
    }
}
