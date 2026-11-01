<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Vectors;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\ErrorCodes;
use VoltKit\Rtc\Internal\Redactor;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\Calls;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Tests\Support\Vectors;
use VoltKit\Rtc\Tests\Support\WeakCalls;
use VoltKit\Rtc\Transport\CurlTransport;
use VoltKit\Rtc\Webhooks;

/**
 * The shared vectors (docs/server-sdk-vectors.json), one test per section, every case
 * iterated: this SDK must answer each the way the Node SDK does (spec 11, item 2).
 */
final class VectorsTest extends TestCase
{
    use MakesClients;

    /**
     * A fake transport playing the vectors' reply or steps. Each body is taken from `$twin`, the same case with its
     * JSON objects kept as objects: decoded to arrays, {} would be sent as [] and {"0":1} as a list.
     *
     * @param array<string, mixed> $case
     */
    private static function transportFor(array $case, \stdClass $twin): FakeTransport
    {
        if (isset($case['transportError']) && is_string($case['transportError'])) {
            return FakeTransport::failing($case['transportError']);
        }
        if (isset($case['steps']) && is_array($case['steps'])) {
            /** @var list<array<string, mixed>> $steps */
            $steps = array_values($case['steps']);
            $twins = is_array($twin->steps ?? null) ? $twin->steps : [];
        } else {
            $reply = $case['reply'] ?? ['status' => 200, 'body' => new \stdClass()];
            assert(is_array($reply));
            /** @var array<string, mixed> $reply */
            $steps = [$reply];
            $twins = [$twin->reply ?? null];
        }
        foreach ($steps as $i => $step) {
            $faithful = $twins[$i] ?? null;
            if (array_key_exists('body', $step) && $faithful instanceof \stdClass && property_exists($faithful, 'body')) {
                $steps[$i]['body'] = $faithful->body;
            }
        }
        return new FakeTransport(...$steps);
    }

    /** `$value` as the client hands JSON back: objects as arrays. */
    private static function plain(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $case */
    private function clientFor(array $case, FakeTransport $t): StreamClient
    {
        $apiKey = $case['apiKey'] ?? 'sk_test';
        $baseUrl = $case['baseUrl'] ?? 'https://x.test/';
        $rates = $case['ratesBaseUrl'] ?? null;
        $retries = $case['retries'] ?? 2;
        $timeout = $case['timeoutMs'] ?? 10000;
        assert(is_string($apiKey) && is_string($baseUrl) && ($rates === null || is_string($rates)) && is_int($retries) && is_int($timeout));
        return $this->client($t, $retries, $apiKey, $baseUrl, $rates, $timeout);
    }

    /** @param array<string, mixed> $expected */
    private static function assertError(StreamError $e, array $expected, string $what, bool $prefixOnly = false): void
    {
        if (isset($expected['code'])) {
            self::assertSame($expected['code'], $e->getErrorCode(), "$what: code");
        }
        if (array_key_exists('status', $expected)) {
            self::assertSame($expected['status'], $e->getStatus(), "$what: status");
        }
        if (array_key_exists('retryable', $expected)) {
            self::assertSame($expected['retryable'], $e->isRetryable(), "$what: retryable");
        }
        if (isset($expected['message']) && is_string($expected['message'])) {
            if ($prefixOnly) {
                $prefix = substr($expected['message'], 0, (int) strpos($expected['message'], ': ') + 2);
                self::assertTrue(str_starts_with($e->getMessage(), $prefix), "$what: the method and path: " . $e->getMessage());
            } else {
                self::assertSame($expected['message'], $e->getMessage(), "$what: message");
            }
        }
        if (isset($expected['messageContains']) && is_string($expected['messageContains'])) {
            self::assertStringContainsString($expected['messageContains'], $e->getMessage(), $what);
        }
        if (isset($expected['messageMustNotContain']) && is_string($expected['messageMustNotContain'])) {
            self::assertStringNotContainsString($expected['messageMustNotContain'], $e->getMessage(), $what);
        }
    }

    /** @return array<string, mixed> */
    private static function arr(mixed $value, string $what): array
    {
        self::assertIsArray($value, $what);
        /** @var array<string, mixed> $value */
        return $value;
    }

    #[Test]
    public function constants(): void
    {
        $c = Vectors::section('constants');
        $defaults = self::arr($c['defaults'], 'defaults');
        self::assertSame($defaults['timeoutMs'], StreamClient::DEFAULT_TIMEOUT_MS);
        self::assertSame($defaults['retries'], StreamClient::DEFAULT_RETRIES);
        self::assertSame($defaults['toleranceSeconds'], Webhooks::DEFAULT_TOLERANCE_SECONDS);
        self::assertSame($defaults['usageSeriesDays'], StreamClient::DEFAULT_USAGE_SERIES_DAYS);
        self::assertSame($c['maxTimeoutMs'], StreamClient::MAX_TIMEOUT_MS);
        $backoff = self::arr($c['backoff'], 'backoff');
        self::assertSame($backoff['baseMs'], StreamClient::BACKOFF_BASE_MS);
        self::assertSame($backoff['factor'], StreamClient::BACKOFF_FACTOR);
        self::assertSame($backoff['jitterMaxMs'], StreamClient::BACKOFF_JITTER_MAX_MS);
        $redaction = self::arr($c['redaction'], 'redaction');
        self::assertSame($redaction['marker'], Redactor::MARKER);
        self::assertSame($redaction['longestKeyLookedFor'], Redactor::LONGEST_KEY_LOOKED_FOR);
        self::assertSame($redaction['keyNotChecked'], Redactor::KEY_NOT_CHECKED);
        $cuts = self::arr($c['messageCuts'], 'messageCuts');
        self::assertSame($cuts['errorReplyWithoutMessage'], StreamClient::CUT_ERROR_REPLY);
        self::assertSame($cuts['successThatIsNotJson'], StreamClient::CUT_NOT_JSON);
        self::assertSame($cuts['redirectTarget'], StreamClient::CUT_REDIRECT_TARGET);
        $headers = self::arr($c['headers'], 'headers');
        self::assertSame($headers['apiKey'], StreamClient::API_KEY_HEADER);
        self::assertSame($headers['webhookSignature'], Webhooks::SIGNATURE_HEADER);
        self::assertSame($c['ratesPath'], StreamClient::RATES_PATH);
        $wh = self::arr($c['webhookHeader'], 'webhookHeader');
        self::assertSame($wh['maxHeaderLength'], Webhooks::MAX_HEADER_LENGTH);
        self::assertSame('0.4.10', $c['nodeSdkVersion'], 'the vectors were regenerated from another Node SDK: review the changes');
        // The words of a 2xx that is not what the call needs (spec 3.6), as this SDK writes them.
        $shape = self::arr($c['replyShape'], 'replyShape');
        assert(is_string($shape['messagePrefix']) && is_string($shape['notAnObject']));
        $e = $this->failure(fn () => $this->client(FakeTransport::raw(200, '[]'), retries: 0)->getBalance());
        self::assertSame('GET /v1/billing/balance: ' . $shape['messagePrefix'] . $shape['notAnObject'], $e->getMessage());
    }

    #[Test]
    public function registry(): void
    {
        $r = Vectors::section('registry');
        self::assertSame($r['errorCodes'], ErrorCodes::ALL);
        self::assertSame($r['restErrorCodes'], ErrorCodes::REST);
        $raisedBySdk = [];
        foreach (self::arr($r['restErrors'], 'restErrors') as $entry) {
            $entry = self::arr($entry, 'entry');
            $code = $entry['code'];
            assert(is_string($code));
            $info = ErrorCodes::info($code);
            self::assertNotNull($info, $code);
            self::assertSame($entry['origin'], $info->origin, $code);
            self::assertSame($entry['retryable'], $info->retryable, $code);
            if ($entry['origin'] === 'server') {
                $http = $entry['http'];
                assert(is_int($http));
                $t = new FakeTransport(['status' => $http, 'body' => ['error' => $code, 'message' => 'from the registry']]);
                $e = $this->failure(fn () => $this->client($t, retries: 0)->getBalance());
                self::assertSame($code, $e->getErrorCode());
                self::assertSame($http, $e->getStatus(), $code);
                self::assertSame($entry['retryable'], $e->isRetryable(), "$code: retryable");
            } else {
                $raisedBySdk[$code] = $entry;
            }
        }
        // The SDK's own: a request that never landed, getRates with nowhere to go, a redirect refused with the key.
        $e = $this->failure(fn () => $this->client(FakeTransport::failing(), retries: 0)->getBalance());
        self::assertSame(['network', 0, true], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        $e = $this->failure(fn () => $this->client(FakeTransport::failing(), retries: 0)->getRates());
        self::assertSame(['rates-unconfigured', 0, false], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        $e = $this->failure(fn () => $this->client(new FakeTransport(['status' => 302, 'headers' => ['location' => 'https://elsewhere.test/']]), retries: 0)->getBalance());
        self::assertSame(['redirected', 302, false], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        self::assertSame(['network', 'rates-unconfigured', 'redirected'], array_keys($raisedBySdk));
        foreach ($raisedBySdk as $code => $entry) {
            if ($entry['http'] !== null) {
                self::assertSame($entry['http'], $code === 'redirected' ? null : 0);
            }
        }
    }

    #[Test]
    public function webhooks(): void
    {
        $w = Vectors::section('webhooks');
        $golden = self::arr($w['golden'], 'golden');
        assert(is_int($golden['timestamp']) && is_string($golden['body']));
        $signed = $golden['timestamp'] . '.' . $golden['body'];
        assert(is_string($golden['currentSecret']) && is_string($golden['previousSecret']));
        self::assertSame($golden['current'], hash_hmac('sha256', $signed, $golden['currentSecret']), 'the golden vector, byte for byte');
        self::assertSame($golden['previous'], hash_hmac('sha256', $signed, $golden['previousSecret']));

        foreach (self::arr($w['verify'], 'verify') as $case) {
            $case = self::arr($case, 'case');
            $name = $case['name'];
            assert(is_string($name) && is_string($case['body']) && is_array($case['secrets']));
            /** @var list<string> $secrets */
            $secrets = $case['secrets'];
            $now = $case['nowUnixSeconds'];
            assert(is_int($now));
            $options = ['now' => $now];
            $tolerance = $case['toleranceSeconds'];
            if (is_int($tolerance) || is_float($tolerance)) {
                $options['toleranceSeconds'] = $tolerance;
            }
            $header = $case['header'];
            assert(is_string($header));
            $given = count($secrets) === 1 ? $secrets[0] : $secrets;
            self::assertSame($case['expected'], Webhooks::verify($case['body'], $header, $given, $options), $name);
            if (count($secrets) === 1) {
                self::assertSame($case['expected'], Webhooks::verify($case['body'], $header, $secrets, $options), "$name (as a list)");
            }
        }

        // Whole seconds (X-2): the clock in milliseconds, given here as seconds with a fraction.
        $count = 0;
        foreach (self::arr($w['wholeSeconds'], 'wholeSeconds') as $case) {
            $case = self::arr($case, 'case');
            $name = $case['name'];
            assert(is_string($name) && is_string($case['body']) && is_string($case['header']) && is_array($case['secrets']));
            assert(is_int($case['nowUnixMillis']) && (is_int($case['toleranceSeconds']) || is_float($case['toleranceSeconds'])));
            /** @var list<string> $secrets */
            $secrets = $case['secrets'];
            $options = ['now' => $case['nowUnixMillis'] / 1000, 'toleranceSeconds' => $case['toleranceSeconds']];
            self::assertSame($case['expected'], Webhooks::verify($case['body'], $case['header'], $secrets, $options), $name);
            // The same clock as a closure.
            $options['now'] = static fn (): float => $case['nowUnixMillis'] / 1000;
            self::assertSame($case['expected'], Webhooks::verify($case['body'], $case['header'], $secrets, $options), "$name (a closure)");
            $count++;
        }
        self::assertSame(10, $count);

        // A clock that is no finite number (X-3, PHP011-3) is an argument error, never a verdict.
        $bad = self::arr($w['badNow'], 'badNow');
        assert(is_string($bad['body']) && is_string($bad['header']) && is_array($bad['secrets']));
        /** @var list<string> $secrets */
        $secrets = $bad['secrets'];
        $clocks = [];
        foreach (self::arr($bad['notFinite'], 'notFinite') as $spelt) {
            $clocks[] = [match ($spelt) {
                'NaN' => NAN,
                'Infinity' => INF,
                '-Infinity' => -INF,
                default => throw new \LogicException('unknown spelling ' . var_export($spelt, true)),
            }, \RangeException::class];
        }
        foreach (self::arr($bad['notNumbers'], 'notNumbers') as $value) {
            $clocks[] = [$value, \InvalidArgumentException::class];
        }
        self::assertCount(5, $clocks);
        foreach ($clocks as [$now, $class]) {
            foreach ([$now, static fn (): mixed => $now] as $clock) {
                $what = var_export($now, true) . ($clock instanceof \Closure ? ' (a closure)' : '');
                $thrown = null;
                try {
                    // @phpstan-ignore argument.type (a deliberately wrong clock)
                    Webhooks::verify($bad['body'], $bad['header'], $secrets, ['now' => $clock, 'toleranceSeconds' => INF]);
                } catch (\Throwable $t) {
                    $thrown = $t;
                }
                self::assertInstanceOf($class, $thrown, "$what: the language's argument error, never a verdict");
                self::assertStringStartsWith('now must be ', $thrown->getMessage(), $what);
            }
        }
        // An unset clock is still the clock: the golden delivery is years old.
        self::assertFalse(Webhooks::verify($bad['body'], $bad['header'], $secrets));

        foreach (self::arr($w['parse'], 'parse') as $case) {
            $case = self::arr($case, 'case');
            $name = $case['name'];
            $expected = self::arr($case['expected'], 'expected');
            assert(is_string($name) && is_string($case['body']) && is_string($case['header']) && is_array($case['secrets']));
            /** @var list<string> $secrets */
            $secrets = $case['secrets'];
            $options = ['toleranceSeconds' => INF];
            if (isset($expected['event'])) {
                self::assertSame($expected['event'], Webhooks::parse($case['body'], $case['header'], $secrets, $options), $name);
            } else {
                try {
                    Webhooks::parse($case['body'], $case['header'], $secrets, $options);
                    self::fail("$name: parsed");
                } catch (StreamError $e) {
                    self::assertError($e, self::arr($expected['error'], 'error'), $name);
                }
            }
        }

        foreach (self::arr($w['setupErrors'], 'setupErrors') as $case) {
            $case = self::arr($case, 'case');
            $name = $case['name'];
            assert(is_string($name) && is_array($case['secrets']) && is_string($case['header']));
            /** @var list<string> $secrets */
            $secrets = $case['secrets'];
            $options = ['now' => 1787000000];
            $tolerance = $case['toleranceSeconds'];
            if ($tolerance === 'NaN') {
                $options['toleranceSeconds'] = NAN;
            } elseif (is_int($tolerance) || is_float($tolerance)) {
                $options['toleranceSeconds'] = $tolerance;
            }
            $thrown = null;
            try {
                // @phpstan-ignore argument.type (a parsed body, not the raw string, is one of the setup errors)
                Webhooks::verify($case['body'], $case['header'], $secrets, $options);
            } catch (\Throwable $t) {
                $thrown = $t;
            }
            self::assertNotNull($thrown, "$name: not a programmer error");
            self::assertNotInstanceOf(StreamError::class, $thrown, "$name: a programmer error, never a StreamError");
            $expected = self::arr($case['expected'], 'expected');
            if ($expected['nodeClass'] === 'RangeError') {
                self::assertInstanceOf(\RangeException::class, $thrown, $name);
                self::assertStringStartsWith('toleranceSeconds must be a number of 0 or more, got ', $thrown->getMessage(), $name);
            } elseif (is_string($case['body'])) {
                self::assertInstanceOf(\InvalidArgumentException::class, $thrown, $name);
                self::assertStringContainsString('needs the signing secret', $thrown->getMessage(), $name);
            } else {
                self::assertInstanceOf(\TypeError::class, $thrown, "$name: a parsed body is refused by the type");
            }
        }
    }

    #[Test]
    public function apiKeys(): void
    {
        $keys = Vectors::section('apiKeys');
        foreach (self::arr($keys['accepted'], 'accepted') as $case) {
            $case = self::arr($case, 'case');
            assert(is_string($case['key']) && is_string($case['name']));
            $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
            $this->client($t, apiKey: $case['key'])->listRooms();
            self::assertSame($case['sentAs'], $t->last()->headers['X-API-Key'], $case['name']);
        }
        $prefixes = [
            'required' => 'apiKey is required',
            'whitespace-only' => 'apiKey is required: the one given is only whitespace',
            'header-unsafe' => 'apiKey holds a character no HTTP header can carry',
            'not-a-voltkit-key' => 'apiKey holds a character no VoltKit key has',
        ];
        foreach (self::arr($keys['refused'], 'refused') as $case) {
            $case = self::arr($case, 'case');
            assert(is_string($case['key']) && is_string($case['name']) && is_string($case['reason']));
            try {
                new StreamClient('https://x.test', $case['key'], transport: new FakeTransport());
                self::fail($case['name'] . ': accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith($prefixes[$case['reason']], $e->getMessage(), $case['name']);
                $net = trim($case['key'], " \t\r\n");
                if ($net !== '') {
                    self::assertFalse(self::holdsPartOf($e->getMessage(), $net), $case['name'] . ': the key is not quoted');
                }
            }
        }
    }

    #[Test]
    public function options(): void
    {
        $o = Vectors::section('options');
        $timeouts = self::arr($o['timeoutMs'], 'timeoutMs');
        foreach (self::arr($timeouts['refused'], 'refused') as $case) {
            $case = self::arr($case, 'case');
            $value = $case['timeoutMs'];
            $expected = self::arr($case['expected'], 'expected');
            $this->assertRefusedOption('timeoutMs', $value, $expected);
        }
        foreach (self::arr($timeouts['deadlines'], 'deadlines') as $case) {
            $case = self::arr($case, 'case');
            $value = $case['timeoutMs'];
            $name = $case['name'];
            assert(is_string($name));
            $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
            if ($value === null) {
                (new StreamClient('https://x.test', 'sk_test', transport: $t))->listRooms();
            } else {
                // "Infinity" has no int: the largest int stands in, and is held at the cap the same way.
                assert(is_int($value) || $value === 'Infinity');
                $this->client($t, timeoutMs: $value === 'Infinity' ? PHP_INT_MAX : $value)->listRooms();
            }
            self::assertSame($case['effectiveTimeoutMs'], $t->last()->timeoutMs, $name);
        }
        $retries = self::arr($o['retries'], 'retries');
        foreach (self::arr($retries['refused'], 'refused') as $case) {
            $case = self::arr($case, 'case');
            $this->assertRefusedOption('retries', $case['retries'], self::arr($case['expected'], 'expected'));
        }
        $t = new FakeTransport(['status' => 503, 'body' => ['error' => 'unavailable']]);
        $this->failure(fn () => (new StreamClient('https://x.test', 'sk_test', transport: $t, sleeper: static function (int $ms): void {
        }))->getBalance());
        self::assertSame($retries['defaultAttemptsOnRepeatable503'], $t->attempts());

        foreach (self::arr($o['urls'], 'urls') as $case) {
            $case = self::arr($case, 'case');
            $expected = self::arr($case['expected'], 'expected');
            assert(is_string($case['baseUrl']) && ($case['ratesBaseUrl'] === null || is_string($case['ratesBaseUrl'])));
            $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => [], 'success' => true, 'data' => ['version' => 3]]]);
            $c = $this->client($t, baseUrl: $case['baseUrl'], ratesBaseUrl: $case['ratesBaseUrl']);
            $c->listRooms();
            self::assertSame($expected['roomsUrl'], $t->last()->url);
            if ($expected['ratesError'] === null) {
                $c->getRates();
                self::assertSame($expected['ratesUrl'], $t->last()->url);
            } else {
                $e = $this->failure(fn () => $c->getRates());
                self::assertError($e, self::arr($expected['ratesError'], 'ratesError'), (string) $case['baseUrl']);
            }
        }
        $this->baseUrls(self::arr($o['baseUrls'], 'baseUrls'));
    }

    /**
     * What a baseUrl, and the same ratesBaseUrl, must be (D8, PHP011-4): refused when the client is made, with the
     * language's argument error, in words that quote no part of it.
     *
     * @param array<string, mixed> $b
     */
    private function baseUrls(array $b): void
    {
        $rates = new FakeTransport(['status' => 200, 'body' => ['rooms' => [], 'success' => true, 'data' => ['version' => 3]]]);
        foreach (self::arr($b['accepted'], 'accepted') as $case) {
            $case = self::arr($case, 'case');
            assert(is_string($case['baseUrl']) && is_string($case['roomsUrl']) && is_string($case['name']));
            $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
            $this->client($t, baseUrl: $case['baseUrl'])->listRooms();
            self::assertSame($case['roomsUrl'], $t->last()->url, $case['name']);
            // The same as ratesBaseUrl: the rates path goes where the rooms path went.
            $this->client($rates, ratesBaseUrl: $case['baseUrl'])->getRates();
            self::assertSame(substr($case['roomsUrl'], 0, -strlen('/v1/rooms')) . StreamClient::RATES_PATH, $rates->last()->url, $case['name']);
        }
        $count = 0;
        foreach (self::arr($b['refused'], 'refused') as $case) {
            $case = self::arr($case, 'case');
            assert(is_string($case['baseUrl']) && is_string($case['name']));
            $expected = self::arr($case['expected'], 'expected');
            assert(is_string($expected['nodeMessage']));
            foreach (['baseUrl', 'ratesBaseUrl'] as $option) {
                $e = $this->refusedUrl($option, $case['baseUrl'], $case['name']);
                // The sense of Node's words, and here the same words.
                self::assertSame(str_replace('baseUrl', $option, $expected['nodeMessage']), $e->getMessage(), "{$case['name']} as $option");
                $mustNotShow = $case['mustNotShow'] ?? null;
                foreach (is_array($mustNotShow) ? $mustNotShow : (is_string($mustNotShow) ? [$mustNotShow] : []) as $secret) {
                    assert(is_string($secret));
                    self::assertStringNotContainsString($secret, $e->getMessage(), $case['name']);
                }
            }
            $count++;
        }
        self::assertSame(38, $count);
        foreach (self::arr($b['required'], 'required') as $case) {
            $case = self::arr($case, 'case');
            assert(is_string($case['baseUrl']));
            $what = var_export($case['baseUrl'], true);
            try {
                new StreamClient($case['baseUrl'], 'sk_test', transport: new FakeTransport());
                self::fail("$what: accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('baseUrl is required', $e->getMessage(), $what);
            }
            // As ratesBaseUrl: unset.
            $e = $this->failure(fn () => $this->client(new FakeTransport(), ratesBaseUrl: $case['baseUrl'])->getRates());
            self::assertSame(['rates-unconfigured', 0], [$e->getErrorCode(), $e->getStatus()], $what);
        }
        // Not a string: PHP's own type check, before the constructor runs (this file is strict_types).
        foreach (self::arr($b['notStrings'], 'notStrings') as $case) {
            $case = self::arr($case, 'case');
            $value = $case['baseUrl'];
            foreach (['baseUrl', 'ratesBaseUrl'] as $option) {
                $thrown = null;
                try {
                    // @phpstan-ignore argument.type, argument.type (deliberately not a string)
                    $option === 'baseUrl' ? new StreamClient($value, 'sk_test') : new StreamClient('https://x.test', 'sk_test', $value);
                } catch (\Throwable $t) {
                    $thrown = $t;
                }
                self::assertInstanceOf(\TypeError::class, $thrown, var_export($value, true) . " as $option");
            }
        }
        // User info is a transport question: curl sends it (Basic), except a raw @ in it, which curl takes for the end
        // of the user info (tests/Transport/CurlTransportTest proves both). A transport of the caller's gets it as given.
        $credentials = self::arr($b['credentials'], 'credentials');
        foreach (self::arr($credentials['urls'], 'urls') as $url) {
            assert(is_string($url));
            $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
            $c = $this->client($t, baseUrl: $url);
            $c->listRooms();
            self::assertSame("$url/v1/rooms", $t->last()->url, 'a transport of the caller\'s gets it as given');
            self::assertStringNotContainsString('hunter2', (string) $c, 'and it is never shown');
            self::assertStringNotContainsString('hunter2', print_r($c, true));
            $userInfo = substr($url, strlen('https://'), (int) strrpos($url, '@') - strlen('https://'));
            if (str_contains($userInfo, '@')) {
                $e = $this->refusedUrl('baseUrl', $url, 'an @ in the user info, with curl', withCurl: true);
                self::assertStringContainsString('curl', $e->getMessage());
            } else {
                new StreamClient($url, 'sk_test', transport: new CurlTransport());
                new StreamClient($url, 'sk_test');
            }
        }
    }

    /** The \InvalidArgumentException making a client with `$url` as `$option` fails with; nothing sent. */
    private function refusedUrl(string $option, string $url, string $name, bool $withCurl = false): \InvalidArgumentException
    {
        $t = new FakeTransport();
        try {
            if ($option === 'baseUrl') {
                new StreamClient($url, 'sk_test', transport: $withCurl ? new CurlTransport() : $t);
            } else {
                new StreamClient('https://x.test', 'sk_test', $url, transport: $withCurl ? new CurlTransport() : $t);
            }
        } catch (\InvalidArgumentException $e) {
            self::assertSame(0, $t->attempts(), "$name: nothing is sent");
            self::assertNull($e->getPrevious(), "$name: no cause that could carry the URL");
            // No part of what was given: the user info, the host, the path.
            foreach (preg_split('~[:/@?#.\s]+~', $url, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (strlen($part) >= 6 && !in_array(strtolower($part), ['https', 'http'], true)) {
                    self::assertStringNotContainsString($part, $e->getMessage(), "$name: quotes $part");
                }
            }
            return $e;
        }
        self::fail("$name: $option accepted");
    }

    /** @param array<string, mixed> $expected */
    private function assertRefusedOption(string $option, mixed $value, array $expected): void
    {
        if ($value === 'NaN') {
            $value = NAN;
        }
        $what = "$option " . var_export($value, true);
        $thrown = null;
        try {
            // Called directly from this strict_types file: Reflection would coerce "5000" and true to ints.
            if ($option === 'timeoutMs') {
                // @phpstan-ignore argument.type (a deliberately wrong option)
                new StreamClient('https://x.test', 'sk_test', timeoutMs: $value);
            } else {
                // @phpstan-ignore argument.type (a deliberately wrong option)
                new StreamClient('https://x.test', 'sk_test', retries: $value);
            }
        } catch (\Throwable $t) {
            $thrown = $t;
        }
        self::assertNotNull($thrown, "$what: accepted");
        if ($thrown instanceof \RangeException) {
            self::assertSame('RangeError', $expected['nodeClass'], $what);
            self::assertSame($expected['message'], $thrown->getMessage(), $what);
        } else {
            // Non-numbers, NaN and fractions never reach the constructor: PHP's own type check refuses them.
            self::assertInstanceOf(\TypeError::class, $thrown, $what);
            self::assertFalse(is_int($value), $what);
        }
    }

    #[Test]
    public function ids(): void
    {
        $ids = Vectors::section('ids');
        $places = array_map(static function (mixed $p): array {
            assert(is_array($p));
            return [$p['method'], $p['param']];
        }, self::arr($ids['places'], 'places'));
        self::assertSame(Calls::PLACES, array_values($places), 'the 20 places an id goes into a path');
        $t = new FakeTransport(['status' => 200, 'body' => []]);
        $c = $this->client($t);
        $count = 0;
        foreach (self::arr($ids['refused'], 'refused') as $case) {
            $case = self::arr($case, 'case');
            assert(is_string($case['method']) && is_string($case['param']) && is_string($case['id']));
            $what = "{$case['method']} {$case['param']} " . var_export($case['id'], true);
            try {
                Calls::withId($c, $case['method'], $case['param'], $case['id']);
                self::fail("$what: sent");
            } catch (StreamError $e) {
                self::assertError($e, self::arr($case['expected'], 'expected'), $what);
            }
            $count++;
        }
        self::assertSame(60, $count);
        self::assertSame(0, $t->attempts(), 'nothing is sent');
        foreach (self::arr($ids['encoded'], 'encoded') as $case) {
            $case = self::arr($case, 'case');
            assert(is_string($case['id']));
            $t = new FakeTransport(['status' => 200, 'body' => ['room' => new \stdClass()]]);
            $this->client($t)->getRoom($case['id']);
            assert(is_string($case['segment']));
            self::assertSame('https://x.test/v1/rooms/' . $case['segment'], $t->last()->url, $case['id']);
        }

        // An id that is no string (D6). PHP's declared string type is this SDK's check, and it runs before the method
        // does: null, an array and an object are a TypeError at every place, from a strict file and a weak one alike,
        // and nothing is sent. A strict file (this one) refuses a boolean and a number the same way.
        $notStrings = Vectors::faithful('ids')->notStrings;
        assert($notStrings instanceof \stdClass && is_array($notStrings->cases));
        foreach ($notStrings->cases as $case) {
            assert($case instanceof \stdClass && is_string($case->name));
            foreach (Calls::PLACES as [$method, $param]) {
                $args = Calls::argsWithId($method, $param, $case->id);
                $what = "$method $param {$case->name}";
                $t = new FakeTransport();
                $thrown = null;
                try {
                    Calls::direct($this->client($t), $method, $args);
                } catch (\Throwable $e) {
                    $thrown = $e;
                }
                self::assertInstanceOf(\TypeError::class, $thrown, "$what: refused by the declared type");
                self::assertSame(0, $t->attempts(), "$what: nothing is sent");
                if (is_bool($case->id)) {
                    // A weak-mode file converts a boolean before the SDK sees it: false is "" (refused, nothing
                    // sent), true is "1", a valid id, and is sent. The language's conversion; README "Ids".
                    $weak = new FakeTransport(['status' => 200, 'body' => ['token' => 't', 'roomId' => 'r', 'room' => new \stdClass(), 'channel' => 'c', 'id' => '1', 'streamId' => 's']]);
                    if ($case->id) {
                        WeakCalls::direct($this->client($weak), $method, $args);
                        self::assertStringContainsString('/1', $weak->last()->url, "$what, weak mode: true is \"1\"");
                    } else {
                        $client = $this->client($weak);
                        $e = $this->failure(static fn () => WeakCalls::direct($client, $method, $args));
                        self::assertSame(['bad-request', 0], [$e->getErrorCode(), $e->getStatus()], "$what, weak mode");
                        self::assertSame(0, $weak->attempts(), "$what, weak mode: nothing is sent");
                    }
                    continue;
                }
                $thrown = null;
                try {
                    WeakCalls::direct($this->client($t), $method, $args);
                } catch (\Throwable $e) {
                    $thrown = $e;
                }
                self::assertInstanceOf(\TypeError::class, $thrown, "$what, weak mode: refused by the declared type");
                self::assertSame(0, $t->attempts(), "$what, weak mode: nothing is sent");
            }
        }

        // A number: a strict file cannot pass one (TypeError, nothing sent); a weak-mode file passes its digits, as
        // the vectors want, at every place.
        foreach (self::arr($ids['numbers'], 'numbers') as $case) {
            $case = self::arr($case, 'case');
            assert((is_int($case['id']) || is_float($case['id'])) && is_string($case['segment']));
            foreach (Calls::PLACES as [$method, $param]) {
                $args = Calls::argsWithId($method, $param, $case['id']);
                $t = new FakeTransport(['status' => 200, 'body' => ['token' => 't', 'roomId' => 'r', 'room' => new \stdClass(), 'channel' => 'c', 'id' => '1', 'streamId' => 's']]);
                $thrown = null;
                try {
                    Calls::direct($this->client($t), $method, $args);
                } catch (\TypeError $e) {
                    $thrown = $e;
                }
                self::assertNotNull($thrown, "$method $param {$case['id']}: strict");
                self::assertSame(0, $t->attempts());
                WeakCalls::direct($this->client($t), $method, $args);
                self::assertSame(1, $t->attempts(), "$method $param {$case['id']}: weak mode sends it");
                $path = (string) parse_url($t->last()->url, PHP_URL_PATH);
                self::assertContains($case['segment'], explode('/', $path), "$method $param: the digits as one segment");
            }
        }
    }

    #[Test]
    public function redaction(): void
    {
        $section = Vectors::section('redaction');
        foreach (self::arr($section['cases'], 'cases') as $i => $case) {
            $case = self::arr($case, 'case');
            $name = $case['name'];
            $method = $case['method'];
            assert(is_string($name) && is_string($method));
            $t = self::transportFor($case, Vectors::faithfulCase('redaction', 'cases', $i));
            $c = $this->clientFor(['retries' => 0] + $case, $t);
            $e = $this->failure(static fn () => Calls::invoke($c, $method));
            self::assertError($e, self::arr($case['expected'], 'expected'), $name);
        }

        $printing = self::arr($section['clientPrinting'], 'clientPrinting');
        assert(is_string($printing['apiKey']) && is_string($printing['baseUrl']) && is_string($printing['ratesBaseUrl']));
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => [], 'success' => true, 'data' => ['version' => 3]]]);
        $c = $this->client($t, apiKey: $printing['apiKey'], baseUrl: $printing['baseUrl'], ratesBaseUrl: $printing['ratesBaseUrl']);
        ob_start();
        var_dump($c);
        $dump = (string) ob_get_clean();
        $expected = self::arr($printing['expected'], 'expected');
        $passwords = [];
        foreach ([$printing['baseUrl'], $printing['ratesBaseUrl']] as $url) {
            $parsed = parse_url($url);
            if (is_array($parsed) && isset($parsed['pass'])) {
                $passwords[] = $parsed['pass'];
            }
        }
        foreach (['var_dump' => $dump, 'print_r' => print_r($c, true), 'json_encode' => json_encode($c, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), '__toString' => (string) $c, 'var_export' => var_export($c, true)] as $how => $text) {
            self::assertSame($expected['containsApiKey'], str_contains($text, $printing['apiKey']), $how);
            foreach ($passwords as $password) {
                self::assertSame($expected['containsAnyUrlPassword'], str_contains($text, $password), "$how: $password");
            }
            if ($how !== 'var_export') {
                assert(is_string($expected['baseUrlShownAs']) && is_string($expected['ratesBaseUrlShownAs']));
                self::assertStringContainsString($expected['baseUrlShownAs'], $text, $how);
                self::assertStringContainsString($expected['ratesBaseUrlShownAs'], $text, $how);
            }
        }
        $c->listRooms();
        self::assertSame($printing['baseUrl'] . 'v1/rooms', $t->last()->url, 'requests still go to the URL as given');
        $c->getRates();
        self::assertSame($printing['ratesBaseUrl'] . '/api/v1/billing/rtc/rates/current', $t->last()->url);
    }

    #[Test]
    public function retry(): void
    {
        $section = Vectors::section('retry');
        foreach (self::arr($section['cases'], 'cases') as $i => $case) {
            $this->runStepCase(self::arr($case, 'case'), Vectors::faithfulCase('retry', 'cases', $i));
        }
        foreach (self::arr($section['registry'], 'registry') as $entry) {
            $entry = self::arr($entry, 'entry');
            assert(is_string($entry['code']) && is_int($entry['status']));
            $t = new FakeTransport(['status' => $entry['status'], 'body' => ['error' => $entry['code'], 'message' => 'm']]);
            $e = $this->failure(fn () => $this->client($t, retries: 0)->getBalance());
            self::assertSame($entry['code'], $e->getErrorCode());
            self::assertSame($entry['status'], $e->getStatus(), $entry['code']);
            self::assertSame($entry['retryable'], $e->isRetryable(), $entry['code']);
        }
        $waits = self::arr($section['backoffWaitsMs'], 'backoffWaitsMs');
        $t = new FakeTransport(['status' => 503, 'body' => ['error' => 'unavailable']]);
        $this->failure(fn () => $this->client($t, retries: count($waits))->getBalance());
        self::assertCount(count($waits), $this->waits);
        foreach (array_values($waits) as $i => $base) {
            assert(is_int($base));
            self::assertGreaterThanOrEqual($base, $this->waits[$i], "retry $i");
            self::assertLessThanOrEqual($base + StreamClient::BACKOFF_JITTER_MAX_MS, $this->waits[$i], "retry $i");
        }
    }

    #[Test]
    public function goneIsDone(): void
    {
        $count = 0;
        foreach (self::arr(Vectors::section('goneIsDone')['cases'], 'cases') as $i => $case) {
            $this->runStepCase(self::arr($case, 'case'), Vectors::faithfulCase('goneIsDone', 'cases', $i));
            $count++;
        }
        self::assertSame(44, $count);
    }

    /**
     * A case in the step language of sections retry and goneIsDone.
     *
     * @param array<string, mixed> $case
     */
    private function runStepCase(array $case, \stdClass $twin): void
    {
        $name = $case['name'];
        $call = $case['call'];
        assert(is_string($name) && is_string($call));
        $t = self::transportFor($case, $twin);
        $c = $this->clientFor($case, $t);
        $expected = self::arr($case['expected'], 'expected');
        if (isset($expected['resolves'])) {
            Calls::invoke($c, $call);
        } else {
            $e = $this->failure(static fn () => Calls::invoke($c, $call));
            $error = self::arr($expected['error'], 'error');
            // The words after the prefix are the transport's own for a network failure; the fake uses Node's words.
            $message = $error['message'] ?? '';
            $transportWords = $error['code'] === 'network' && $error['status'] === 0
                && is_string($message) && !str_contains($message, 'timed out');
            self::assertError($e, $error, $name, prefixOnly: $transportWords);
        }
        self::assertSame($expected['attempts'], $t->attempts(), "$name: attempts");
    }

    #[Test]
    public function messages(): void
    {
        foreach (self::arr(Vectors::section('messages')['cases'], 'cases') as $i => $case) {
            $case = self::arr($case, 'case');
            $name = $case['name'];
            $method = $case['method'];
            assert(is_string($name) && is_string($method));
            $expected = self::arr($case['expected'], 'expected');
            $t = self::transportFor($case, Vectors::faithfulCase('messages', 'cases', $i));
            $c = $this->clientFor(['retries' => 0] + $case, $t);
            if (isset($expected['programmerError'])) {
                try {
                    $c->ensureRoom('show-42', 'h', ['self' => NAN]);
                    self::fail("$name: sent");
                } catch (\InvalidArgumentException $e) {
                    $prefix = $expected['messagePrefix'];
                    assert(is_string($prefix));
                    self::assertTrue(str_starts_with($e->getMessage(), $prefix), "$name: " . $e->getMessage());
                }
                self::assertSame($expected['attempts'], $t->attempts(), $name);
                continue;
            }
            // The generator calls createToken and getRoom with "show-42", and the refused-id case deleteRoom("..").
            $args = match ($method) {
                'createToken', 'getRoom' => ['roomId' => 'show-42'],
                'deleteRoom' => ($expected['code'] ?? null) === 'bad-request' ? ['roomId' => '..'] : [],
                default => [],
            };
            if (array_key_exists('resolves', $expected)) {
                self::assertSame($expected['resolves'], Calls::invoke($c, $method, $args), $name);
                continue;
            }
            $e = $this->failure(static fn () => Calls::invoke($c, $method, $args));
            self::assertError($e, $expected, $name, prefixOnly: isset($case['nodeOnly']));
        }
    }

    #[Test]
    public function requests(): void
    {
        $count = 0;
        foreach (self::arr(Vectors::section('requests')['cases'], 'cases') as $i => $case) {
            $case = self::arr($case, 'case');
            $label = $case['method'];
            assert(is_string($label));
            $args = self::arr($case['args'], 'args');
            $expected = self::arr($case['expected'], 'expected');
            $t = self::transportFor($case, Vectors::faithfulCase('requests', 'cases', $i));
            $rates = Calls::methodName($label) === 'getRates' ? 'https://api.example.com/' : null;
            $c = $this->client($t, ratesBaseUrl: $rates);
            $result = Calls::invoke($c, $label, $args);
            $r = $t->last();
            self::assertSame(1, $t->attempts(), $label);
            self::assertSame($expected['httpMethod'], $r->method, $label);
            self::assertSame($expected['url'], $r->url, $label);
            $origin = $rates === null ? 'https://x.test' : 'https://api.example.com';
            self::assertSame($expected['path'], substr($r->url, strlen($origin)), $label);
            $headers = self::arr($expected['headers'], 'headers');
            self::assertSame($headers['apiKeySent'], isset($r->headers['X-API-Key']), "$label: X-API-Key");
            self::assertSame($headers['contentType'], $r->headers['Content-Type'] ?? null, "$label: Content-Type");
            self::assertSame($expected['followsRedirects'], $r->followRedirects, "$label: redirects");
            if ($expected['body'] === null) {
                self::assertNull($r->body, "$label: no body");
            } else {
                self::assertIsString($r->body);
                self::assertEquals($expected['body'], json_decode($r->body, true, 512, JSON_THROW_ON_ERROR), "$label: body (structural)");
            }
            self::assertEquals($expected['result'], $result, "$label: result");
            if ($expected['result'] === null) {
                self::assertNull($result);
            }
            $again = new FakeTransport(['status' => 503, 'body' => ['error' => 'unavailable']]);
            $this->failure(fn () => Calls::invoke($this->client($again, ratesBaseUrl: $rates), $label, $args));
            self::assertSame($expected['attemptsOn503'], $again->attempts(), "$label: attempts on 503");
            self::assertSame($expected['safeToRepeat'], $again->attempts() > 1, "$label: safe to repeat");
            $count++;
        }
        self::assertSame(33, $count);
    }

    /**
     * What a 2xx must be, per call (X-1, PHP011-1, D4; spec 3.6): refused with http-<status>, not retried, in words
     * that quote nothing of the reply; or resolved, with the fields named.
     */
    #[Test]
    public function replies(): void
    {
        $count = 0;
        foreach (self::arr(Vectors::section('replies')['cases'], 'cases') as $i => $case) {
            $case = self::arr($case, 'case');
            $name = $case['name'];
            $method = $case['method'];
            assert(is_string($name) && is_string($method));
            $twin = Vectors::faithfulCase('replies', 'cases', $i);
            $expected = self::arr($case['expected'], 'expected');
            $t = self::transportFor($case, $twin);
            $c = $this->client($t, retries: 2);
            $args = self::arr($case['args'], 'args');
            if (isset($expected['error'])) {
                $e = $this->failure(static fn () => Calls::invoke($c, $method, $args));
                $error = self::arr($expected['error'], 'error');
                self::assertError($e, $error, $name);
                self::assertNull($e->getPrevious(), "$name: no cause");
            } else {
                $result = Calls::invoke($c, $method, $args);
                assert($twin->expected instanceof \stdClass);
                if (property_exists($twin->expected, 'holds')) {
                    self::assertIsArray($result, $name);
                    self::assertHolds($twin->expected->holds, $result, $name);
                }
                if (property_exists($twin->expected, 'list')) {
                    self::assertSame(self::plain($twin->expected->list), $result, $name);
                }
                if (isset($expected['nothing'])) {
                    self::assertNull($result, $name);
                }
            }
            self::assertSame($expected['attempts'], $t->attempts(), "$name: attempts");
            $count++;
        }
        self::assertSame(356, $count);
    }

    /**
     * Every field the object `$holds` names has that value in `$actual`, recursively, and other fields are not looked
     * at; a list is the same length and holds each element. `$holds` comes with its objects kept as objects, the only
     * way to tell {} (nothing pinned) from [] (an empty list).
     */
    private static function assertHolds(mixed $holds, mixed $actual, string $what): void
    {
        if ($holds instanceof \stdClass) {
            self::assertIsArray($actual, $what);
            foreach (get_object_vars($holds) as $key => $value) {
                self::assertArrayHasKey($key, $actual, "$what: $key");
                self::assertHolds($value, $actual[$key], "$what: $key");
            }
        } elseif (is_array($holds)) {
            self::assertIsArray($actual, $what);
            self::assertTrue(array_is_list($actual), "$what: a list");
            self::assertCount(count($holds), $actual, $what);
            foreach ($holds as $i => $value) {
                self::assertHolds($value, $actual[$i], "$what: $i");
            }
        } else {
            self::assertSame($holds, $actual, $what);
        }
    }

    #[Test]
    public function errorHelpers(): void
    {
        foreach (self::arr(Vectors::section('errorHelpers')['cases'], 'cases') as $case) {
            $case = self::arr($case, 'case');
            assert(is_string($case['code']) && is_int($case['status']));
            $e = new StreamError($case['code'], $case['status'], 'm', false);
            $actual = [
                'isNotFound' => $e->isNotFound(),
                'isOutOfMinutes' => $e->isOutOfMinutes(),
                'isQuota' => $e->isQuota(),
                'isCapacityExhausted' => $e->isCapacityExhausted(),
                'isBroadcastDisabled' => $e->isBroadcastDisabled(),
                'isBillingUnavailable' => $e->isBillingUnavailable(),
            ];
            self::assertSame($case['expected'], $actual, "{$case['code']} {$case['status']}");
        }
    }
}
