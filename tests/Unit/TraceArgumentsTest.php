<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\CurlTransport;

/**
 * Review 2026-10-23, finding 3 (spec 5.5): with zend.exception_ignore_args off (php.ini-development, and many
 * containers), a StreamError's trace carried the unredacted Response and the transport's own exception as call
 * arguments, so serialize($e) or an error reporter that renders arguments showed what the message had redacted.
 * The parameters that carry someone else's words are #[\SensitiveParameter] now.
 */
final class TraceArgumentsTest extends TestCase
{
    use MakesClients;

    private const KEY = 'sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0';

    private string $ignoreArgs = '1';
    private string $stringParamMaxLen = '0';

    protected function setUp(): void
    {
        $this->ignoreArgs = (string) ini_get('zend.exception_ignore_args');
        self::assertNotFalse(ini_set('zend.exception_ignore_args', '0'), 'the setting is INI_ALL');
        // PHP's own default without a php.ini (the official images): 15 characters of each string argument in (string) $e.
        $this->stringParamMaxLen = (string) ini_get('zend.exception_string_param_max_len');
        self::assertNotFalse(ini_set('zend.exception_string_param_max_len', '15'));
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        ini_set('zend.exception_string_param_max_len', $this->stringParamMaxLen);
    }

    /**
     * The SDK's own frames of the trace, with their arguments: what serialize($e) or an error reporter that renders
     * arguments would show for them. The test runner's frames are left out: their objects are the runner's own.
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

    /**
     * Every string in the arguments of the SDK's own frames, objects walked by their properties (what a serializer
     * or an error reporter that renders arguments sees).
     */
    private static function traceText(\Throwable $e): string
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

    /**
     * The SDK's frames, serialized as a job store or a session would: possible, and free of `$secret`. PHP refuses to
     * serialize a #[\SensitiveParameter] value, which stands for what was passed and holds nothing else: it is
     * written here as its class name.
     */
    private static function assertSerializesWithout(string $secret, \Throwable $e): void
    {
        $frames = self::sdkFrames($e);
        self::assertNotEmpty($frames);
        self::assertTrue(array_key_exists('args', $frames[0]), 'the trace was recorded with arguments');
        array_walk_recursive($frames, static function (mixed &$v): void {
            if ($v instanceof \SensitiveParameterValue) {
                $v = \SensitiveParameterValue::class;
            }
        });
        self::assertStringNotContainsString($secret, serialize($frames));
    }

    #[Test]
    public function theServersWordsDoNotRideAlongAsTraceArguments(): void
    {
        $t = new FakeTransport(['status' => 401, 'body' => ['error' => 'unauthorized', 'message' => 'Invalid API key: ' . self::KEY]]);
        $e = $this->failure(fn () => $this->client($t, apiKey: self::KEY, retries: 0)->listRooms());
        self::assertStringNotContainsString(self::KEY, $e->getMessage());
        self::assertStringNotContainsString(self::KEY, self::traceText($e), 'the Response object in the trace holds the raw reply');
        self::assertSerializesWithout(self::KEY, $e);
    }

    #[Test]
    public function aSuccessThatIsNotJsonDoesNotRideAlongEither(): void
    {
        $t = FakeTransport::raw(200, str_repeat('.', 85) . self::KEY);
        $e = $this->failure(fn () => $this->client($t, apiKey: self::KEY, retries: 0)->listRooms());
        self::assertSame('http-200', $e->getErrorCode());
        self::assertStringNotContainsString(self::KEY, self::traceText($e));
        self::assertSerializesWithout(self::KEY, $e);
    }

    #[Test]
    public function theTransportsOwnExceptionDoesNotRideAlongAsATraceArgument(): void
    {
        $t = FakeTransport::failing('request to https://deploy:hunter2@x.test/v1/billing/balance failed, reason: connect ECONNREFUSED');
        $e = $this->failure(fn () => $this->client($t, baseUrl: 'https://deploy:hunter2@x.test', retries: 0)->getBalance());
        self::assertSame('network', $e->getErrorCode());
        self::assertStringNotContainsString('hunter2', $e->getMessage());
        self::assertStringNotContainsString('hunter2', self::traceText($e), 'the TransportException in the trace holds the raw words');
        self::assertSerializesWithout('hunter2', $e);
        self::assertNull($e->getPrevious(), 'and it is not attached as the previous exception');
    }

    /**
     * PHP011-2: `new StreamClient('deploy:hunter2@x.test', ...)` showed the URL in (string) $e and in the trace's
     * arguments, the password with it: $baseUrl, $ratesBaseUrl and the URL check's own parameter were not marked
     * #[\SensitiveParameter]. Every frame on the way to the refusal holds the URL as a sensitive value now. (A loop,
     * not a data provider: this test's own arguments would be in the trace too.)
     */
    #[Test]
    public function aRefusedUrlIsNeitherQuotedNorATraceArgument(): void
    {
        $cases = [
            'the report\'s baseUrl: no scheme' => ['deploy:hunter2@x.test', null],
            'the same as ratesBaseUrl' => ['https://x.test', 'deploy:hunter2@x.test'],
            'a line feed inside' => ["https://deploy:hunter2@x.test\n/v1", null],
            'a query' => ['https://deploy:hunter2@x.test?region=eu', null],
            'a bad port' => ['https://deploy:hunter2@x.test:0', null],
            'a password holding a /' => ['https://deploy:hunter2/x@x.test', null],
            'a rates URL with a bad port' => ['https://x.test', 'https://deploy:hunter2@x.test:99999'],
            'an @ in the password, which curl cannot send' => ['https://deploy:p@hunter2@x.test', null],
        ];
        foreach ($cases as $name => [$baseUrl, $ratesBaseUrl]) {
            foreach ([null, new CurlTransport()] as $transport) {
                try {
                    // No transport given is the curl one too.
                    new StreamClient($baseUrl, self::KEY, $ratesBaseUrl, transport: $transport);
                    self::fail("$name: accepted");
                } catch (\InvalidArgumentException $e) {
                    self::assertStringNotContainsString('hunter2', $e->getMessage(), $name);
                    self::assertStringNotContainsString('hunter2', (string) $e, "$name: (string) \$e, as a log line shows it");
                    self::assertStringNotContainsString('hunter2', self::traceText($e), $name);
                    self::assertSerializesWithout('hunter2', $e);
                    self::assertSerializesWithout(self::KEY, $e);
                    self::assertNull($e->getPrevious(), $name);
                }
            }
        }
    }

    #[Test]
    public function theRatesOriginsCredentialsDoNotRideAlongEither(): void
    {
        // The rates origin used to be passed down as an argument, so https://web:s3cret@api.example.com was in the trace.
        $t = new FakeTransport(['status' => 500, 'body' => ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'oops']]]);
        $e = $this->failure(fn () => $this->client($t, retries: 0, ratesBaseUrl: 'https://web:s3cret@api.example.com')->getRates());
        self::assertSame('INTERNAL_ERROR', $e->getErrorCode());
        self::assertStringNotContainsString('s3cret', self::traceText($e));
        self::assertSerializesWithout('s3cret', $e);
        self::assertSame('https://web:s3cret@api.example.com/api/v1/billing/rtc/rates/current', $t->last()->url, 'the request still goes where it was told');
    }
}
