<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Webhooks;

/** Spec 7: header grammar, raw bytes, tolerance both ways, rotation, programmer errors, parse, the golden vector. */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const GOLDEN_BODY = '{"id":"evt_golden","type":"webhook.test","tenantId":"vk_golden","createdAt":1787000000000,"data":{"message":"golden"}}';
    private const GOLDEN_CURRENT = '95abd781b3e5d318e78ed06645b74cb19f427e626b33e3c8de4cfa3429541f7a';
    private const GOLDEN_PREVIOUS = 'e2b083ef4cb14906ece2bbf046a11c62fbc83696b51f549bcc11735e80c6065c';
    public const GOLDEN_TS = 1787000000;

    private static function sign(string $body, ?int $ts = null, string $secret = self::SECRET): string
    {
        $ts ??= time();
        return "t=$ts,v1=" . hash_hmac('sha256', "$ts.$body", $secret);
    }

    #[Test]
    public function aGenuineSignatureVerifiesAndATamperedBodyDoesNot(): void
    {
        $body = '{"id":"evt_1","type":"participant.joined"}';
        $header = self::sign($body);
        self::assertTrue(Webhooks::verify($body, $header, self::SECRET));
        self::assertFalse(Webhooks::verify("$body ", $header, self::SECRET), 'one trailing space');
        self::assertFalse(Webhooks::verify($body, $header, 'whsec_other'), 'the wrong secret');
        self::assertFalse(Webhooks::verify($body, 'garbage', self::SECRET));
        self::assertFalse(Webhooks::verify($body, null, self::SECRET), 'a missing header');
        self::assertFalse(Webhooks::verify($body, '', self::SECRET));
    }

    #[Test]
    public function theGoldenVectorVerifiesByteForByte(): void
    {
        $single = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_CURRENT;
        $now = ['now' => self::GOLDEN_TS + 100];
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $single, 'whsec_golden_current', $now));
        self::assertFalse(Webhooks::verify(self::GOLDEN_BODY, $single, 'whsec_golden_previous', $now));
        // The same HMAC computed here, independently of Webhooks::verify.
        self::assertSame(self::GOLDEN_CURRENT, hash_hmac('sha256', self::GOLDEN_TS . '.' . self::GOLDEN_BODY, 'whsec_golden_current'));
        self::assertSame(self::GOLDEN_PREVIOUS, hash_hmac('sha256', self::GOLDEN_TS . '.' . self::GOLDEN_BODY, 'whsec_golden_previous'));
        // Still refused with the real clock: it is years old.
        self::assertFalse(Webhooks::verify(self::GOLDEN_BODY, $single, 'whsec_golden_current'));
    }

    #[Test]
    public function aDeliverySignedWithTwoSecretsDuringARotationVerifiesForEitherReceiver(): void
    {
        $dual = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_CURRENT . ',v1=' . self::GOLDEN_PREVIOUS;
        $o = ['now' => self::GOLDEN_TS];
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $dual, 'whsec_golden_current', $o), 'a receiver on the new secret');
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $dual, 'whsec_golden_previous', $o), 'a receiver still on the old one');
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $dual, ['whsec_golden_previous', 'whsec_golden_current'], $o));
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $dual, ['whsec_other', 'whsec_golden_previous'], $o), 'every secret is tried: the match is the last one');
        self::assertFalse(Webhooks::verify(self::GOLDEN_BODY, $dual, ['whsec_other', 'whsec_nope'], $o));
        self::assertFalse(Webhooks::verify(self::GOLDEN_BODY . ' ', $dual, 'whsec_golden_current', $o), 'a tampered body');
        $reversed = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_PREVIOUS . ',v1=' . self::GOLDEN_CURRENT;
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $reversed, 'whsec_golden_current', $o), 'order does not matter');
    }

    #[Test]
    public function aListOfSecretsVerifiesASingleSignatureFromEither(): void
    {
        $body = '{"id":"evt_2"}';
        self::assertTrue(Webhooks::verify($body, self::sign($body), ['whsec_new', self::SECRET]));
        self::assertTrue(Webhooks::verify($body, self::sign($body), [self::SECRET]));
    }

    /** @return iterable<string, array{0: string|list<string>}> */
    public static function missingSecrets(): iterable
    {
        yield 'an empty list' => [[]];
        yield 'a list of one empty string' => [['']];
        yield 'an empty string' => [''];
        yield 'only empty strings' => [['', '']];
    }

    /** @param string|list<string> $secrets */
    #[Test]
    #[DataProvider('missingSecrets')]
    public function aMissingSecretIsAProgrammerError(string|array $secrets): void
    {
        $body = '{"id":"evt_2"}';
        try {
            Webhooks::verify($body, self::sign($body), $secrets);
            self::fail('verified without a secret');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('needs the signing secret', $e->getMessage());
        }
        try {
            Webhooks::parse($body, self::sign($body), $secrets);
            self::fail('parsed without a secret');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('needs the signing secret', $e->getMessage());
        }
    }

    #[Test]
    public function theHeaderMayBeAListAsSomeFrameworksHandItOver(): void
    {
        $body = '{"id":"evt_3"}';
        self::assertTrue(Webhooks::verify($body, [self::sign($body)], self::SECRET));
        self::assertFalse(Webhooks::verify($body, [], self::SECRET));
        $ts = time();
        $sig = explode(',', self::sign($body, $ts))[1];
        self::assertTrue(Webhooks::verify($body, ["t=$ts", $sig], self::SECRET), 'joined with commas');
    }

    #[Test]
    public function theRawBodyMustBeAString(): void
    {
        $decoded = json_decode('{"id":"evt_1"}', true);
        try {
            // @phpstan-ignore argument.type
            Webhooks::verify($decoded, 't=1,v1=' . str_repeat('a', 64), self::SECRET);
            self::fail('took a parsed body');
        } catch (\TypeError $e) {
            self::assertStringContainsString('string', $e->getMessage(), 'a parsed body is a setup mistake, never a quiet false');
        }
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function headerGrammar(): iterable
    {
        $ts = self::GOLDEN_TS;
        $good = 'v1=' . self::GOLDEN_CURRENT;
        yield 'an unknown scheme beside v1 is skipped' => ["t=$ts,v0=deadbeef,$good", true];
        yield 'spaces after the commas are fine' => ["t=$ts, $good", true];
        yield 'spaces around the parts are fine' => [" t=$ts , $good ", true];
        yield 'no timestamp' => [$good, false];
        yield 'no signature' => ["t=$ts", false];
        yield 'two timestamps' => ["t=$ts,t=$ts,$good", false];
        yield 'a timestamp that is not digits' => ["t=abc,$good", false];
        yield 'a negative timestamp' => ["t=-$ts,$good", false];
        yield 'a 16-digit timestamp' => ["t=1111111111111111,$good", false];
        yield 'a signature that is not hex' => ["t=$ts,v1=XYZ", false];
        yield 'a signature of the wrong length' => ["t=$ts,v1=" . str_repeat('a', 63), false];
        yield 'a signature of 65 characters' => ["t=$ts,v1=" . str_repeat('a', 65), false];
        yield 'upper-case hex is refused' => ["t=$ts,v1=" . strtoupper(self::GOLDEN_CURRENT), false];
        yield 'a trailing comma' => ["t=$ts,$good,", false];
        yield 'a part without =' => ["t=$ts,garbage,$good", false];
        yield 'a part with an empty key' => ["t=$ts,=x,$good", false];
        yield 'a header over 4096 characters' => ["t=$ts,$good," . str_repeat('v0=x,', 900) . $good, false];
        yield 'a header of exactly 4096 characters' => [str_pad("t=$ts,$good,", 4096 - 3, 'v0=x,') . 'v0=', strlen(str_pad("t=$ts,$good,", 4096 - 3, 'v0=x,') . 'v0=') <= 4096];
    }

    #[Test]
    #[DataProvider('headerGrammar')]
    public function theHeaderGrammarIsExact(string $header, bool $expected): void
    {
        self::assertSame($expected, Webhooks::verify(self::GOLDEN_BODY, $header, 'whsec_golden_current', ['now' => self::GOLDEN_TS]), $header);
    }

    #[Test]
    public function theToleranceIsInclusiveBothWaysAndDefaultsTo300(): void
    {
        $header = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_CURRENT;
        $verify = static fn (int|float $now, int|float|null $tolerance = null): bool => Webhooks::verify(
            self::GOLDEN_BODY,
            $header,
            'whsec_golden_current',
            $tolerance === null ? ['now' => $now] : ['now' => $now, 'toleranceSeconds' => $tolerance],
        );
        self::assertTrue($verify(self::GOLDEN_TS + 300), '300 s old is still accepted');
        self::assertFalse($verify(self::GOLDEN_TS + 301), '301 s old is refused');
        self::assertTrue($verify(self::GOLDEN_TS - 300), '300 s in the future is accepted');
        self::assertFalse($verify(self::GOLDEN_TS - 301), '301 s in the future is refused');
        self::assertFalse($verify(self::GOLDEN_TS + 300.5), 'fractions count');
        self::assertTrue($verify(self::GOLDEN_TS, 0), 'tolerance 0 accepts the exact second');
        self::assertFalse($verify(self::GOLDEN_TS + 1, 0), 'and refuses one second later');
        self::assertTrue($verify(self::GOLDEN_TS + 3600, 7200));
        self::assertFalse($verify(self::GOLDEN_TS + 3600, 300));
        self::assertTrue($verify(2102360000, 3153600000), 'a very large tolerance lifts the check');
        self::assertTrue($verify(2102360000, INF), 'as does INF');
        self::assertTrue($verify(self::GOLDEN_TS + 100, 300.0), 'a float is fine');
        self::assertSame(300, Webhooks::DEFAULT_TOLERANCE_SECONDS);
    }

    /** @return iterable<string, array{0: mixed, 1: class-string<\Throwable>}> */
    public static function badTolerances(): iterable
    {
        yield 'negative' => [-1, \RangeException::class];
        yield 'negative infinity' => [-INF, \RangeException::class];
        yield 'NaN' => [NAN, \RangeException::class];
        yield 'a numeric string is not coerced' => ['300', \InvalidArgumentException::class];
        yield 'an empty string, from an unset variable' => ['', \InvalidArgumentException::class];
        yield 'a word' => ['soon', \InvalidArgumentException::class];
        yield 'a boolean' => [true, \InvalidArgumentException::class];
        yield 'an array' => [[300], \InvalidArgumentException::class];
    }

    /** @param class-string<\Throwable> $class */
    #[Test]
    #[DataProvider('badTolerances')]
    public function aToleranceThatIsNotANumberOfZeroOrMoreIsAProgrammerError(mixed $tolerance, string $class): void
    {
        $header = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_CURRENT;
        $options = ['toleranceSeconds' => $tolerance, 'now' => self::GOLDEN_TS];
        foreach (['verify', 'parse'] as $fn) {
            try {
                if ($fn === 'verify') {
                    // @phpstan-ignore argument.type (a deliberately wrong tolerance)
                    Webhooks::verify(self::GOLDEN_BODY, $header, 'whsec_golden_current', $options);
                } else {
                    // @phpstan-ignore argument.type (a deliberately wrong tolerance)
                    Webhooks::parse(self::GOLDEN_BODY, $header, 'whsec_golden_current', $options);
                }
                self::fail("$fn accepted the tolerance");
            } catch (\RangeException | \InvalidArgumentException $e) {
                self::assertInstanceOf($class, $e, $fn);
                self::assertStringStartsWith('toleranceSeconds must be a number of 0 or more, got ', $e->getMessage());
            }
        }
    }

    #[Test]
    public function theToleranceIsCheckedBeforeAnyHmac(): void
    {
        // Even with a header that could never verify, a bad tolerance is reported: it is the setup that is wrong.
        $this->expectException(\RangeException::class);
        Webhooks::verify(self::GOLDEN_BODY, 'garbage', 'whsec_golden_current', ['toleranceSeconds' => -1]);
    }

    #[Test]
    public function nowMayBeANumberOrACallable(): void
    {
        $header = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_CURRENT;
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $header, 'whsec_golden_current', ['now' => static fn (): int => self::GOLDEN_TS]));
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $header, 'whsec_golden_current', ['now' => self::GOLDEN_TS + 0.5]));
        try {
            // @phpstan-ignore argument.type (a deliberately wrong clock)
            Webhooks::verify(self::GOLDEN_BODY, $header, 'whsec_golden_current', ['now' => 'yesterday']);
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('now', $e->getMessage());
        }
    }

    #[Test]
    public function nowMayNotBeTheNameOfAFunction(): void
    {
        // Review 2026-10-23, finding 8: is_callable() is true for any function name, so ['now' => 'time'] was
        // accepted, and ['now' => 'system'] called system(). A clock is a number or a closure.
        $header = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_CURRENT;
        foreach (['time', 'system', 'strlen'] as $name) {
            try {
                // @phpstan-ignore argument.type (a function name is not a clock)
                Webhooks::verify(self::GOLDEN_BODY, $header, 'whsec_golden_current', ['now' => $name]);
                self::fail("accepted now: $name");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('now', $e->getMessage());
            }
        }
        $clock = new class {
            public function __invoke(): int
            {
                return WebhooksTest::GOLDEN_TS;
            }
        };
        self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, $header, 'whsec_golden_current', ['now' => $clock(...)]), 'an invokable object, as a closure');
    }

    #[Test]
    public function headerPartsAreTrimmedOfTheWhitespaceTheNodeSdkTrims(): void
    {
        // Review 2026-10-23, finding 9: PHP's trim() strips NUL and not form feed; JavaScript's trim() the reverse.
        $header = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_CURRENT;
        foreach (["\f", "\x0B", " ", "\t", "\r\n"] as $space) {
            self::assertTrue(Webhooks::verify(self::GOLDEN_BODY, "$space$header$space", 'whsec_golden_current', ['now' => self::GOLDEN_TS]), var_export($space, true));
        }
        self::assertFalse(Webhooks::verify(self::GOLDEN_BODY, "$header\0", 'whsec_golden_current', ['now' => self::GOLDEN_TS]), 'a NUL is not whitespace');
        self::assertFalse(Webhooks::verify(self::GOLDEN_BODY, str_replace(',', "\0,", $header), 'whsec_golden_current', ['now' => self::GOLDEN_TS]));
    }

    #[Test]
    public function parseReturnsTheEventOrFailsWithBadSignature(): void
    {
        $header = 't=' . self::GOLDEN_TS . ',v1=' . self::GOLDEN_CURRENT;
        $event = Webhooks::parse(self::GOLDEN_BODY, $header, 'whsec_golden_current', ['now' => self::GOLDEN_TS]);
        self::assertSame(['id' => 'evt_golden', 'type' => 'webhook.test', 'tenantId' => 'vk_golden', 'createdAt' => 1787000000000, 'data' => ['message' => 'golden']], $event);

        foreach ([[$header, 'whsec_nope'], ['garbage', 'whsec_golden_current'], [$header . 'x', 'whsec_golden_current']] as [$h, $s]) {
            try {
                Webhooks::parse(self::GOLDEN_BODY, $h, $s, ['now' => self::GOLDEN_TS]);
                self::fail('parsed');
            } catch (StreamError $e) {
                self::assertSame('bad-signature', $e->getErrorCode());
                self::assertSame(401, $e->getStatus());
                self::assertFalse($e->isRetryable());
                self::assertSame('webhook signature did not verify', $e->getMessage());
            }
        }
        // Too old is bad-signature too: the handler answers 401 and VoltKit retries.
        try {
            Webhooks::parse(self::GOLDEN_BODY, $header, 'whsec_golden_current');
            self::fail('parsed a years-old delivery');
        } catch (StreamError $e) {
            self::assertSame('bad-signature', $e->getErrorCode());
        }
    }

    #[Test]
    public function parseOfAVerifiedBodyThatIsNotAJsonObjectIsLoud(): void
    {
        $body = 'not json';
        $ts = time();
        $thrown = null;
        try {
            Webhooks::parse($body, self::sign($body, $ts), self::SECRET);
        } catch (\JsonException $e) {
            $thrown = $e;
        }
        self::assertInstanceOf(\JsonException::class, $thrown, 'a verified body that is not JSON');
        $body = '[1,2]';
        $thrown = null;
        try {
            Webhooks::parse($body, self::sign($body, $ts), self::SECRET);
        } catch (\UnexpectedValueException $e) {
            $thrown = $e;
        }
        self::assertInstanceOf(\UnexpectedValueException::class, $thrown, 'a list is not an event');
    }

    #[Test]
    public function theSignatureCoversTheRawUtf8Bytes(): void
    {
        $body = '{"id":"evt_ü","text":"房间 🎉"}';
        $header = self::sign($body);
        self::assertTrue(Webhooks::verify($body, $header, self::SECRET));
        self::assertFalse(Webhooks::verify(str_replace('ü', 'u', $body), $header, self::SECRET));
    }

    #[Test]
    public function theSecretsParameterIsMarkedSensitive(): void
    {
        foreach (['verify', 'parse'] as $fn) {
            $parameter = (new \ReflectionMethod(Webhooks::class, $fn))->getParameters()[2];
            self::assertSame('secrets', $parameter->getName());
            self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class), $fn);
        }
    }

    #[Test]
    public function verifyUsesAConstantTimeComparison(): void
    {
        // The implementation must go through hash_equals; the source is the only place that shows it.
        $source = file_get_contents(__DIR__ . '/../../src/Webhooks.php');
        self::assertIsString($source);
        self::assertStringContainsString('hash_equals(', $source);
        self::assertDoesNotMatchRegularExpression('/===\s*\$(expected|signature|sig|mac)\b/', $source, 'no plain string comparison of a digest');
    }
}
