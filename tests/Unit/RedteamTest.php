<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/**
 * SDK red team, 2026-10-28 (the Music add-on round, 0.1.1): getRates against a hostile rates host -- the addons
 * array in every wrong shape, numbers JSON cannot keep, deep nesting, duplicate keys, a 10 MB reply -- and the
 * secrets (the API key, URL credentials) after those paths, the trace's arguments included.
 */
final class RedteamTest extends TestCase
{
    use MakesClients;

    private const KEY = 'sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0';
    private const RATES = 'https://api.example.com';
    private const MUSIC = '{"id":"music","label":"Music add-on","price":"10.00","cuPerParticipantSecond":"1000",'
        . '"unit":"credits per 1,000 participant-minutes while music plays","appliesTo":["audio_std","audio_hd"],'
        . '"includedIn":["video_30","video_480_60","video_720_60","video_1080_60"]}';

    private static function rates(string $addons): string
    {
        return '{"success":true,"data":{"version":4,"kind":"standard","effectiveAt":null,"tiers":[],"surcharges":[],'
            . '"addons":' . $addons . ',"idleTier":"audio_std","modes":[],"notes":[]}}';
    }

    private static function musicWith(string $old, string $new): string
    {
        self::assertStringContainsString($old, self::MUSIC);
        return '{"music":' . str_replace($old, $new, self::MUSIC) . '}';
    }

    /** @return array<string, array{0: string}> */
    public static function hostileAddons(): array
    {
        $cases = [
            'addons a string' => '"music"',
            'addons a number' => '10',
            'addons true' => 'true',
            'addons null' => 'null',
            'addons an empty list' => '[]',
            'addons a list of the add-on' => '[' . self::MUSIC . ']',
            'addons empty' => '{}',
            'music a string' => '{"music":"10.00"}',
            'music a number' => '{"music":1000}',
            'music null' => '{"music":null}',
            'music a list' => '{"music":[' . self::MUSIC . ']}',
            'music empty' => '{"music":{}}',
            'price a number' => self::musicWith('"price":"10.00"', '"price":10.1'),
            'price 1e309' => self::musicWith('"price":"10.00"', '"price":1e309'),
            'price -0' => self::musicWith('"price":"10.00"', '"price":-0'),
            'price a string past a double' => self::musicWith('"price":"10.00"', '"price":"1e309"'),
            'price an object' => self::musicWith('"price":"10.00"', '"price":{"amount":"10.00"}'),
            'price true' => self::musicWith('"price":"10.00"', '"price":true'),
            'cuPerParticipantSecond a huge integer' => self::musicWith(
                '"cuPerParticipantSecond":"1000"',
                '"cuPerParticipantSecond":123456789012345678901234567890',
            ),
            'cuPerParticipantSecond a float' => self::musicWith('"cuPerParticipantSecond":"1000"', '"cuPerParticipantSecond":1000.5'),
            'unit a number' => self::musicWith('"unit":"credits per 1,000 participant-minutes while music plays"', '"unit":5'),
            'appliesTo a string' => self::musicWith('"appliesTo":["audio_std","audio_hd"]', '"appliesTo":"audio_std,audio_hd"'),
            'appliesTo mixed types' => self::musicWith(
                '"appliesTo":["audio_std","audio_hd"]',
                '"appliesTo":["audio_std",1,null,{},["audio_hd"],true]',
            ),
            'appliesTo an object with list keys' => self::musicWith(
                '"appliesTo":["audio_std","audio_hd"]',
                '"appliesTo":{"0":"audio_std","1":"audio_hd"}',
            ),
            'includedIn null' => self::musicWith(
                '"includedIn":["video_30","video_480_60","video_720_60","video_1080_60"]',
                '"includedIn":null',
            ),
            'an unknown add-on beside music' => '{"music":' . self::MUSIC . ',"karaoke":{"price":"99.00"}}',
            'numeric and empty add-on ids' => '{"0":{"price":"1.00"},"":{"price":"2.00"},"music":' . self::MUSIC . '}',
            'proto and constructor keys' => '{"__proto__":{"price":"0.00"},"constructor":{"price":"0.00"},"music":'
                . self::MUSIC . '}',
            'nested 64 deep' => '{"music":' . str_repeat('{"a":', 64) . '1' . str_repeat('}', 64) . '}',
            'duplicate music keys' => '{"music":' . self::MUSIC . ',"music":{"price":"99.00"}}',
            'duplicate price keys' => self::musicWith('"price":"10.00"', '"price":"10.00","price":"0.00"'),
            'unicode escapes in the id' => self::musicWith('"id":"music"', '"id":"music\u0000"'),
        ];
        $out = [];
        foreach ($cases as $name => $addons) {
            $out[$name] = [$addons];
        }
        return $out;
    }

    /** The rates as json_decode reads them: what getRates must hand back, nothing coerced, split or dropped. */
    private static function decoded(string $text): mixed
    {
        $all = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($all);
        return $all['data'];
    }

    #[Test]
    #[DataProvider('hostileAddons')]
    public function aHostileAddonsShapeDecodesExactlyAsJsonDecodeReadsIt(string $addons): void
    {
        $text = self::rates($addons);
        $t = FakeTransport::raw(200, $text);
        $got = $this->client($t, retries: 0, ratesBaseUrl: self::RATES)->getRates();
        // PHP hands back the API's array (a phpstan type is no check at run time).
        self::assertSame(self::decoded($text), $got);
        self::assertSame(1, $t->attempts());
        self::assertArrayNotHasKey('X-API-Key', $t->last()->headers);
    }

    /** @return array<string, array{0: string}> */
    public static function notJson(): array
    {
        return [
            'NaN literal' => [self::rates(self::musicWith('"price":"10.00"', '"price":NaN'))],
            'Infinity literal' => [self::rates(self::musicWith('"price":"10.00"', '"price":Infinity'))],
            'nested 600 deep (past json_decode\'s 512)' => [
                self::rates('{"music":' . str_repeat('[', 600) . str_repeat(']', 600) . '}'),
            ],
            'nested 100,000 deep' => [self::rates('{"music":' . str_repeat('[', 100000) . str_repeat(']', 100000) . '}')],
            'truncated' => [substr(self::rates(self::MUSIC), 0, -40)],
            'trailing garbage' => [self::rates(self::MUSIC) . ' xx'],
            'invalid UTF-8 in a label' => [self::rates(self::musicWith('"label":"Music add-on"', "\"label\":\"Mu\xffsic\""))],
            'a lone surrogate escape' => [self::rates(self::musicWith('"label":"Music add-on"', '"label":"\ud800"'))],
        ];
    }

    #[Test]
    #[DataProvider('notJson')]
    public function aReplyThatIsNotJsonIsTheSdksErrorNotRetried(string $text): void
    {
        $t = FakeTransport::raw(200, $text);
        try {
            $got = $this->client($t, retries: 2, ratesBaseUrl: self::RATES)->getRates();
        } catch (StreamError $e) {
            self::assertSame('http-200', $e->getErrorCode());
            self::assertFalse($e->isRetryable());
            self::assertSame(1, $t->attempts(), 'the same reply would come back');
            return;
        }
        // json_decode accepted it: then it must be exactly what json_decode reads.
        self::assertSame(self::decoded($text), $got);
    }

    /**
     * Pinned by the red team as "a 10 MB label decodes"; the repair round's decision 5 (music-addon/SDK_REPAIR_ROUND.md,
     * R-SV) caps a reply at StreamClient::MAX_REPLY_BYTES (4 MiB), so the 10 MB reply is now refused with the SDK's
     * error, at once and not retried, and a label that fills a reply to exactly the cap still decodes, in time.
     */
    #[Test]
    public function aTenMegabyteLabelIsRefusedAtTheCapAndOneFillingTheCapDecodes(): void
    {
        $text = self::rates(self::musicWith('"label":"Music add-on"', '"label":"' . str_repeat('m', 10 * 1024 * 1024) . '"'));
        $t = FakeTransport::raw(200, $text);
        $started = microtime(true);
        $e = $this->failure(fn () => $this->client($t, retries: 2, ratesBaseUrl: self::RATES)->getRates());
        self::assertSame('http-200', $e->getErrorCode());
        self::assertFalse($e->isRetryable());
        self::assertSame(1, $t->attempts());
        self::assertStringContainsString('the reply is too large', $e->getMessage());
        self::assertLessThan(10.0, microtime(true) - $started);

        $fill = StreamClient::MAX_REPLY_BYTES - strlen(self::rates(self::musicWith('"label":"Music add-on"', '"label":""')));
        $text = self::rates(self::musicWith('"label":"Music add-on"', '"label":"' . str_repeat('m', $fill) . '"'));
        self::assertSame(StreamClient::MAX_REPLY_BYTES, strlen($text));
        $t = FakeTransport::raw(200, $text);
        $started = microtime(true);
        $got = $this->client($t, retries: 0, ratesBaseUrl: self::RATES)->getRates();
        $addons = $got['addons'] ?? null;
        self::assertIsArray($addons);
        $music = $addons['music'] ?? null;
        self::assertIsArray($music);
        self::assertSame(str_repeat('m', $fill), $music['label'] ?? null);
        self::assertLessThan(10.0, microtime(true) - $started);
    }

    /**
     * A 10 MB reply of small elements: json_decode makes a 16-byte zval of each 2-byte element (and doubles the
     * array as it grows), so 10 MB of `0,` is over 128 MB of arrays -- past the default memory_limit, which is a
     * fatal error no catch can handle: the worker dies. In its own process, so only this test sees it.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function aTenMegabyteReplyOfSmallElementsIsDecodedOrRefusedNeverAFatalError(): void
    {
        ini_set('memory_limit', '128M');
        $text = self::rates(self::musicWith(
            '"appliesTo":["audio_std","audio_hd"]',
            '"appliesTo":[' . str_repeat('0,', 5 * 1024 * 1024) . '0]',
        ));
        self::assertGreaterThanOrEqual(10 * 1024 * 1024, strlen($text));
        $t = FakeTransport::raw(200, $text);
        unset($text);
        try {
            $got = $this->client($t, retries: 0, ratesBaseUrl: self::RATES)->getRates();
            self::assertIsArray($got['addons'] ?? null);
        } catch (StreamError $e) {
            self::assertSame('http-200', $e->getErrorCode());
        }
    }

    #[Test]
    public function aTenMegabyteErrorPageIsRedactedAndCut(): void
    {
        $page = '<html>' . str_repeat('x', 10 * 1024 * 1024) . ' ' . self::KEY . ' https://web:s3cret@api.example.com/</html>';
        $t = FakeTransport::raw(502, $page);
        $started = microtime(true);
        $e = $this->failure(fn () => $this->client($t, retries: 2, apiKey: self::KEY, ratesBaseUrl: self::RATES)->getRates());
        self::assertSame(3, $t->attempts());
        self::assertSame('http-502', $e->getErrorCode());
        self::assertLessThan(400, strlen($e->getMessage()));
        self::assertLessThan(30.0, microtime(true) - $started, 'three 10 MB pages redacted');
    }

    // --- secrets after the hostile paths ----------------------------------------------------------------------------

    /**
     * The SDK's own frames of a trace (the runner's frames hold the runner's objects).
     *
     * @return list<array<string, mixed>>
     */
    private static function sdkFrames(\Throwable $e): array
    {
        $frames = [];
        foreach ($e->getTrace() as $frame) {
            $class = (string) ($frame['class'] ?? '');
            if (str_starts_with($class, 'VoltKit\\Rtc\\') && !str_starts_with($class, 'VoltKit\\Rtc\\Tests\\')) {
                $frames[] = $frame;
            }
        }
        return $frames;
    }

    /** Every string in the arguments of the SDK's own frames, objects walked by their properties. */
    private static function sdkFrameText(\Throwable $e): string
    {
        $out = [];
        $seen = [];
        $walk = static function (mixed $v, int $depth) use (&$walk, &$out, &$seen): void {
            if ($depth > 8) {
                return;
            }
            if (is_string($v)) {
                $out[] = $v;
            } elseif (is_array($v)) {
                foreach ($v as $x) {
                    $walk($x, $depth + 1);
                }
            } elseif (is_object($v) && !$v instanceof \Closure && !isset($seen[spl_object_id($v)])) {
                $seen[spl_object_id($v)] = true;
                $out[] = $v::class;
                foreach ((array) $v as $x) {
                    $walk($x, $depth + 1);
                }
            }
        };
        foreach (self::sdkFrames($e) as $frame) {
            $walk($frame['args'] ?? [], 0);
        }
        return implode("\n", $out);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function hostileSteps(): array
    {
        $quoted = self::KEY . ' ' . rawurlencode(self::KEY) . ' https://web:s3cret@api.example.com/x https://svc:hunter2@x.test/v1';
        return [
            '200 not JSON quoting both' => [['status' => 200, 'raw' => '<pre>' . $quoted . '</pre>']],
            '200 truncated JSON with the key' => [['status' => 200, 'raw' => self::rates('{"music":{"price":"' . self::KEY)]],
            '200 JSON without data' => [['status' => 200, 'raw' => '{"success":true,"note":"' . $quoted . '"}']],
            '500 code and message quoting both' => [[
                'status' => 500,
                'body' => ['success' => false, 'error' => ['code' => self::KEY, 'message' => $quoted]],
            ]],
            '503 page quoting both' => [['status' => 503, 'raw' => '<h1>down</h1>' . $quoted]],
            'network error quoting the URL and the key' => [[
                'network' => true,
                'message' => 'Could not resolve https://web:s3cret@api.example.com/api/v1/billing/rtc/rates/current ' . self::KEY,
            ]],
        ];
    }

    /** @param array<string, mixed> $step */
    #[Test]
    #[DataProvider('hostileSteps')]
    public function afterAHostileRatesReplyNothingShowsTheKeyOrAUrlPassword(array $step): void
    {
        $ignore = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $t = new FakeTransport($step);
            $client = $this->client(
                $t,
                retries: 0,
                apiKey: self::KEY,
                baseUrl: 'https://svc:hunter2@x.test',
                ratesBaseUrl: 'https://web:s3cret@api.example.com',
            );
            $e = $this->failure(fn () => $client->getRates());
            $shown = [
                $e->getMessage(),
                $e->getErrorCode(),
                (string) $e,
                $e->getTraceAsString(),
                self::sdkFrameText($e),
                serialize(self::sdkFrames($e)),
                (string) $client,
                print_r($client, true),
                (string) json_encode($client),
            ];
            for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
                $shown[] = (string) $p;
                $shown[] = self::sdkFrameText($p);
            }
            foreach ($shown as $i => $text) {
                self::assertFalse(self::holdsPartOf($text, self::KEY), "view $i shows part of the key: " . substr($text, 0, 300));
                self::assertStringNotContainsString('s3cret', $text, "view $i");
                self::assertStringNotContainsString('hunter2', $text, "view $i");
            }
            foreach ($t->calls as $request) {
                self::assertArrayNotHasKey('X-API-Key', $request->headers, 'the rates host never gets the key');
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignore);
        }
    }
}
