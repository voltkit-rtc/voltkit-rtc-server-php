<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 2.2 and 2.3: which keys are taken, how they are sent, and that a refusal never quotes the key. */
final class ApiKeyTest extends TestCase
{
    use MakesClients;

    /** A key the way VoltKit issues them: sk_ and 64 hex digits. Made up. */
    public const LIVE_KEY = 'sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0';

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function acceptedKeys(): iterable
    {
        yield 'a VoltKit key' => [self::LIVE_KEY, self::LIVE_KEY];
        yield 'the bootstrap key an operator chose' => ['sk_dev_local_key_change_me', 'sk_dev_local_key_change_me'];
        yield 'a base64-looking key' => ['sk_live_Zm9v+YmFyLzk/QmF6+cXV4/dGVzdF9rZXk+MTIz/NDU2Nzg==', 'sk_live_Zm9v+YmFyLzk/QmF6+cXV4/dGVzdF9rZXk+MTIz/NDU2Nzg=='];
        yield 'whitespace around it is dropped' => [" " . self::LIVE_KEY . "\r\n", self::LIVE_KEY];
        yield 'a tab before and a line feed after' => ["\t" . self::LIVE_KEY . "\n", self::LIVE_KEY];
        yield 'a tab inside is kept: a header carries one' => ["sk_a\tb_0123456789abcdef", "sk_a\tb_0123456789abcdef"];
        yield 'a space inside is kept' => ['sk_live_two words_0123456789abcdef', 'sk_live_two words_0123456789abcdef'];
    }

    #[Test]
    #[DataProvider('acceptedKeys')]
    public function anAcceptedKeyIsSentTrimmedInXApiKey(string $given, string $sentAs): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
        $this->client($t, apiKey: $given)->listRooms();
        self::assertSame($sentAs, $t->last()->headers['X-API-Key']);
    }

    #[Test]
    public function everyPrintableAsciiCharacterButTheBackslashIsAccepted(): void
    {
        $all = 'sk_';
        for ($c = 0x20; $c <= 0x7E; $c++) {
            if ($c !== 0x5C) {
                $all .= chr($c);
            }
        }
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
        $this->client($t, apiKey: $all)->listRooms();
        self::assertSame($all, $t->last()->headers['X-API-Key']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedKeys(): iterable
    {
        $required = 'apiKey is required';
        $whitespace = 'apiKey is required: the one given is only whitespace';
        $header = 'apiKey holds a character no HTTP header can carry';
        $voltkit = 'apiKey holds a character no VoltKit key has';
        yield 'empty' => ['', $required];
        yield 'only a line feed' => ["\n", $whitespace];
        yield 'only a space' => [' ', $whitespace];
        yield 'only whitespace of several kinds' => [" \t\r\n ", $whitespace];
        yield 'a line feed inside' => [self::LIVE_KEY . "\nX", $header];
        yield 'CR LF inside' => [self::LIVE_KEY . "\r\nX", $header];
        yield 'a line feed first, then text' => ["X\n" . self::LIVE_KEY, $header];
        yield 'a NUL' => [self::LIVE_KEY . "\0", $header];
        yield 'a control character' => [self::LIVE_KEY . "\x01X", $header];
        yield 'DEL' => [self::LIVE_KEY . "\x7f", $header];
        yield 'a character above U+00FF' => [self::LIVE_KEY . 'Ā', $header];
        yield 'an accented letter' => ['sk_live_café_0123456789abcdef', $voltkit];
        yield 'U+0080' => ["sk_live_\u{80}_0123456789abcdef", $voltkit];
        yield 'a no-break space' => ["sk_live\u{a0}_0123456789abcdef", $voltkit];
        yield 'U+00FF' => ['sk_live_ÿ_0123456789abcdef', $voltkit];
        yield 'a Latin-1 byte that is not UTF-8' => ["sk_live_caf\xE9_0123456789abcdef", $voltkit];
        yield 'a backslash at the end' => [self::LIVE_KEY . '\\', $voltkit];
        yield 'a backslash first' => ['\\' . self::LIVE_KEY, $voltkit];
        yield 'a backslash in the middle' => ['sk_live_a\\b' . self::LIVE_KEY, $voltkit];
        yield 'a \\n written out, from a quoted .env line' => [self::LIVE_KEY . '\\n', $voltkit];
        yield 'both a backslash and a line break: the header rule comes first' => ['\\' . self::LIVE_KEY . "\nX", $header];
    }

    #[Test]
    #[DataProvider('refusedKeys')]
    public function aRefusedKeyFailsTheConstructorWithoutBeingQuoted(string $key, string $prefix): void
    {
        try {
            new StreamClient('https://x.test', $key, transport: new FakeTransport());
            self::fail('the client was made');
        } catch (\InvalidArgumentException $e) {
            self::assertTrue(str_starts_with($e->getMessage(), $prefix), $e->getMessage());
            $text = $e->getMessage() . "\n" . $e->getTraceAsString();
            self::assertFalse(self::holdsPartOf($text, trim($key, " \t\r\n")), 'the key is not quoted');
            self::assertFalse(str_contains($text, self::LIVE_KEY), 'the key is not quoted');
        }
    }

    #[Test]
    public function theApiKeyParameterIsMarkedSensitiveSoStackTracesDoNotShowIt(): void
    {
        $parameter = (new \ReflectionMethod(StreamClient::class, '__construct'))->getParameters()[1];
        self::assertSame('apiKey', $parameter->getName());
        self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class));
    }
}
