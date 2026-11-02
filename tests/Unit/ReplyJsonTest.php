<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\Internal\ReplyJson;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Webhooks;

/**
 * PHP012-1 (0.1.3, D13): text the API sends that PHP alone refused. A chat viewer's message holding a lone UTF-16
 * surrogate escape, or nested past json_decode()'s default depth of 512, made listMessages fail with "the reply is
 * not JSON"; a participant's metadata did the same to getRoom and listParticipants; and a correctly signed
 * chat.message delivery made Webhooks::parse throw a raw \JsonException past the example receiver's catch. Node and
 * Go read all of it, so this SDK does now: a lone surrogate as U+FFFD, nesting up to PHP's own parser limit (about
 * 5,000 levels of `[`, 2,500 of `{"a":`; 4 KB of metadata holds at most 2,048), and everything else exactly as before.
 */
final class ReplyJsonTest extends TestCase
{
    use MakesClients;

    private const SECRET = 'whsec_test';
    private const T = 1787000000;

    /** A room with the given metadata, as GET /v1/rooms/{id} answers it. */
    private static function roomWith(string $metadata): string
    {
        return '{"room":{"roomId":"rm","tenantId":"t","originNode":"n1","state":"live","hostIdentity":"h",'
            . '"createdAt":1787000000000,"nodes":["n1"],"metadata":' . $metadata . '}}';
    }

    private static function nested(int $levels): string
    {
        return str_repeat('[', $levels) . str_repeat(']', $levels);
    }

    /** @return array<mixed> an empty array $levels arrays deep */
    private static function nestedValue(int $levels): array
    {
        $value = [];
        for ($i = 1; $i < $levels; $i++) {
            $value = [$value];
        }
        return $value;
    }

    private static function signed(string $body): string
    {
        return 't=' . self::T . ',v1=' . hash_hmac('sha256', self::T . ".$body", self::SECRET);
    }

    // --- the SDK's calls --------------------------------------------------------------------------------------------

    #[Test]
    public function listMessagesReadsAMessageWithALoneSurrogate(): void
    {
        $t = FakeTransport::raw(200, '{"messages":[{"id":"1793-0","identity":"viewer","text":"hi \ud83d","createdAt":1}]}');
        $messages = $this->client($t, retries: 0)->listMessages('ch-surrogate');
        self::assertSame("hi \u{FFFD}", $messages[0]['text'] ?? null);
        self::assertSame(1, $t->attempts());
    }

    #[Test]
    public function listMessagesReadsAMessageNestedPastJsonDecodesDefaultDepth(): void
    {
        $t = FakeTransport::raw(200, '{"messages":[{"id":"1793-0","identity":"viewer","text":"deep","metadata":' . self::nested(600) . '}]}');
        $messages = $this->client($t, retries: 0)->listMessages('ch-deep600');
        self::assertSame(self::nestedValue(600), $messages[0]['metadata'] ?? null);
    }

    #[Test]
    public function getRoomAndListParticipantsReadMetadataNestedUpTo4000Levels(): void
    {
        foreach ([509, 999, 4000] as $levels) {
            $room = $this->client(FakeTransport::raw(200, self::roomWith(self::nested($levels))), retries: 0)->getRoom('rm-deep');
            self::assertSame(self::nestedValue($levels), $room['room']['metadata'] ?? null, "a room with metadata $levels deep");
            $reply = '{"participants":[{"identity":"v","role":"viewer","metadata":' . self::nested($levels) . '}]}';
            $people = $this->client(FakeTransport::raw(200, $reply), retries: 0)->listParticipants('rm-deep');
            self::assertSame(self::nestedValue($levels), $people[0]['metadata'] ?? null, "a participant with metadata $levels deep");
        }
    }

    #[Test]
    public function aLoneSurrogateInAKeyBecomesAKeyEndingInUFffdNeverAnotherKey(): void
    {
        $reply = '{"messages":[{"id":"1","text":"x","metadata":{"token\ud83d":"evil","token":"good","\udc00":1,"\ud800":2}}]}';
        $metadata = $this->client(FakeTransport::raw(200, $reply), retries: 0)->listMessages('ch')[0]['metadata'] ?? null;
        self::assertSame(
            ["token\u{FFFD}" => 'evil', 'token' => 'good', "\u{FFFD}" => 2],
            $metadata,
            'the key with the surrogate is its own key; two lone surrogates are the same key, the later value winning',
        );
    }

    #[Test]
    public function aReplyNestedPastWhatPhpCanParseIsStillACleanNotJson(): void
    {
        // 99,990 levels: under MAX_REPLY_CONTAINERS, so json_decode sees it, and PHP's parser refuses it on its own.
        $t = FakeTransport::raw(200, self::roomWith(self::nested(99_990)));
        $e = $this->failure(fn () => $this->client($t, retries: 2)->getRoom('rm-deep'));
        self::assertSame(['http-200', 200, false], [$e->getErrorCode(), $e->getStatus(), $e->isRetryable()]);
        self::assertStringStartsWith('GET /v1/rooms/rm-deep: the reply is not JSON: {"room":{"roomId"', $e->getMessage());
        self::assertSame(1, $t->attempts());
    }

    // --- webhooks ---------------------------------------------------------------------------------------------------

    #[Test]
    public function parseReadsASignedDeliveryWithALoneSurrogateAndStillChecksTheSignatureOnTheRawBytes(): void
    {
        $body = '{"id":"evt_1","type":"chat.message","tenantId":"t","createdAt":1787000000000,'
            . '"data":{"text":"\ud83d","metadata":' . self::nested(600) . '}}';
        $header = self::signed($body);
        $event = Webhooks::parse($body, $header, self::SECRET, ['now' => self::T]);
        $data = $event['data'] ?? [];
        self::assertSame("\u{FFFD}", $data['text'] ?? null);
        self::assertSame(self::nestedValue(600), $data['metadata'] ?? null);
        // The same event written with U+FFFD itself decodes to the same value, but it is not the body that was signed.
        $rewritten = str_replace('\ud83d', '\ufffd', $body);
        self::assertSame($event, json_decode($rewritten, true, 5000, JSON_THROW_ON_ERROR));
        $e = $this->failure(static fn () => Webhooks::parse($rewritten, $header, self::SECRET, ['now' => self::T]));
        self::assertSame(['bad-signature', 401], [$e->getErrorCode(), $e->getStatus()]);
    }

    #[Test]
    public function parseStillThrowsJsonExceptionOnlyForTextPhpCannotRead(): void
    {
        // Past PHP's own parser limit; bytes that are not UTF-8; cut short; empty. Never from VoltKit.
        foreach (['{"a":' . self::nested(99_990) . '}', "{\"a\":\"\xff\"}", '{"a":"\ud83d"', ''] as $body) {
            try {
                Webhooks::parse($body, self::signed($body), self::SECRET, ['now' => self::T]);
                self::fail(bin2hex(substr($body, 0, 12)) . ' parsed');
            } catch (\JsonException $e) {
                self::assertNotSame(JSON_ERROR_UTF16, $e->getCode(), 'a lone surrogate alone is never the reason');
            }
        }
    }

    // --- ReplyJson itself -------------------------------------------------------------------------------------------

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function escapes(): iterable
    {
        yield 'a lone high surrogate in a value' => ['{"a":"x\ud83dy"}', ['a' => "x\u{FFFD}y"]];
        yield 'a lone low surrogate in a value' => ['{"a":"\udc00"}', ['a' => "\u{FFFD}"]];
        yield 'a lone surrogate in a key' => ['{"k\uD83D":1}', ["k\u{FFFD}" => 1]];
        yield 'a pair is kept' => ['{"a":"\ud83d\ude00"}', ['a' => "\u{1F600}"]];
        yield 'a lone high before a pair' => ['{"a":"\ud83d\ud83d\ude00"}', ['a' => "\u{FFFD}\u{1F600}"]];
        yield 'a lone low after a pair' => ['{"a":"\ud83d\ude00\ude00"}', ['a' => "\u{1F600}\u{FFFD}"]];
        yield 'a high followed by another escape' => ['{"a":"\ud83d\u0041"}', ['a' => "\u{FFFD}A"]];
        yield 'an escaped backslash before uD83D is text' => ['{"a":"\\\\ud83d","b":"\udbff"}', ['a' => '\ud83d', 'b' => "\u{FFFD}"]];
        yield 'an escaped quote before a surrogate' => ['{"a":"\\"\ud800"}', ['a' => "\"\u{FFFD}"]];
        yield 'upper-case hex' => ['{"a":"\uDBFF\uDFFF","b":"\uDBFF"}', ['a' => "\u{10FFFF}", 'b' => "\u{FFFD}"]];
        yield 'U+FFFD itself and a BMP escape stay' => ['{"a":"\ufffd\u00e9\ud800"}', ['a' => "\u{FFFD}é\u{FFFD}"]];
        yield 'in a list and in nested objects' => ['["\ud800",{"\udfff":{"x":"\ud800"}}]', ["\u{FFFD}", ["\u{FFFD}" => ['x' => "\u{FFFD}"]]]];
    }

    #[Test]
    #[DataProvider('escapes')]
    public function decodeReadsALoneSurrogateAsUFffd(string $text, mixed $expected): void
    {
        self::assertSame($expected, ReplyJson::decode($text, true));
        // Decoding with objects kept as objects, as the shape check does, reads the same text.
        $asObjects = json_encode(ReplyJson::decode($text, false), JSON_THROW_ON_ERROR);
        self::assertSame($expected, json_decode($asObjects, true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function decodeReadsNestingUpToPhpsOwnLimitAndRefusesTheRestCleanly(): void
    {
        self::assertSame(5000, ReplyJson::MAX_DEPTH);
        self::assertSame(self::nestedValue(4000), ReplyJson::decode(self::nested(4000), true));
        self::assertSame(600, self::depthOf(ReplyJson::decode('{"a":' . self::nested(599) . '}', true)));
        foreach ([5001, 99_990] as $levels) {
            try {
                ReplyJson::decode(self::nested($levels), true);
                self::fail("$levels levels read");
            } catch (\JsonException $e) {
                self::assertContains($e->getCode(), [JSON_ERROR_DEPTH, JSON_ERROR_SYNTAX]);
            }
        }
    }

    #[Test]
    public function aTextPhpRefusesForAnotherReasonIsRefusedUnchanged(): void
    {
        foreach (['{"a":NaN}', '{"a":"\ud83d"} x', "{\"a\":\"\xff\ud83d\"}", '{"a":"\u12"}', '{'] as $text) {
            try {
                ReplyJson::decode($text, true);
                self::fail("$text read");
            } catch (\JsonException $e) {
                self::assertNotSame(JSON_ERROR_UTF16, $e->getCode(), $text);
            }
        }
    }

    #[Test]
    public function validTextIsNeverChangedAndOnlyWhatPhpRefusedForASurrogateIsRewritten(): void
    {
        // A randomized corpus of escapes of every kind, as values and as keys: a text json_decode reads must come back
        // byte for byte from the rewrite and read the same; one it refuses for a lone surrogate must read as an
        // independent scan rewrites it; one it refuses for anything else must be refused for the same reason. Seeded,
        // so a failure is reproducible.
        mt_srand(20261101);
        $pieces = [
            '\ud83d', '\ude00', '\uD83D\uDE00', '\udbff', '\udfff', '\\\\', '\\"', '\\n', '\\/', '\u0041', '\u00e9',
            '\ufffd', 'a', 'é', ' ', '"', '{', '}', '[', ']', ':', ',', '\\u', '\\', '\uDC00\uD800',
        ];
        $run = static function () use ($pieces): string {
            $n = mt_rand(0, 6);
            $s = '';
            for ($j = 0; $j < $n; $j++) {
                $s .= $pieces[mt_rand(0, count($pieces) - 1)];
            }
            return $s;
        };
        $valid = 0;
        $rewritten = 0;
        for ($i = 0; $i < 4000; $i++) {
            $text = match (mt_rand(0, 3)) {
                0 => '"' . $run() . '"',
                1 => '{"' . $run() . '":"' . $run() . '"}',
                2 => '["' . $run() . '","' . $run() . '"]',
                default => '{"' . $run() . '":{"' . $run() . '":["' . $run() . '"]}}',
            };
            try {
                $strict = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $expected = $e->getCode() === JSON_ERROR_UTF16 ? self::loneSurrogatesReplacedByScan($text) : null;
                if ($expected === null) {
                    try {
                        ReplyJson::decode($text, true);
                        self::fail("read, where json_decode refuses it for reason {$e->getCode()}: $text");
                    } catch (\JsonException $again) {
                        self::assertSame($e->getCode(), $again->getCode(), $text);
                    }
                    continue;
                }
                $rewritten++;
                try {
                    $value = json_decode($expected, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e2) {
                    try {
                        ReplyJson::decode($text, true);
                        self::fail("read, where the rewritten text is not JSON either: $text");
                    } catch (\JsonException $again) {
                        self::assertSame($e2->getCode(), $again->getCode(), $text);
                    }
                    continue;
                }
                self::assertSame($value, ReplyJson::decode($text, true), $text);
                continue;
            }
            $valid++;
            self::assertSame($text, ReplyJson::withoutLoneSurrogates($text), 'a valid text is changed: ' . $text);
            self::assertSame($strict, ReplyJson::decode($text, true), $text);
        }
        self::assertGreaterThan(200, $valid);
        self::assertGreaterThan(200, $rewritten);
    }

    /** The text with each lone surrogate escape as \ufffd, by a plain scan with explicit ranges: the oracle. */
    private static function loneSurrogatesReplacedByScan(string $text): string
    {
        $out = '';
        $i = 0;
        $n = strlen($text);
        while ($i < $n) {
            if ($text[$i] !== '\\') {
                $out .= $text[$i];
                $i++;
                continue;
            }
            $hex = substr($text, $i + 2, 4);
            if (($text[$i + 1] ?? '') === 'u' && strlen($hex) === 4 && ctype_xdigit($hex)) {
                $unit = (int) hexdec($hex);
                if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                    $next = substr($text, $i + 6, 6);
                    if (strlen($next) === 6 && substr($next, 0, 2) === '\\u' && ctype_xdigit(substr($next, 2))) {
                        $low = (int) hexdec(substr($next, 2));
                        if ($low >= 0xDC00 && $low <= 0xDFFF) {
                            $out .= substr($text, $i, 12);
                            $i += 12;
                            continue;
                        }
                    }
                    $out .= '\\ufffd';
                    $i += 6;
                    continue;
                }
                if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                    $out .= '\\ufffd';
                    $i += 6;
                    continue;
                }
                $out .= substr($text, $i, 6);
                $i += 6;
                continue;
            }
            $out .= substr($text, $i, 2);
            $i += 2;
        }
        return $out;
    }

    private static function depthOf(mixed $value): int
    {
        $depth = 0;
        while (is_array($value)) {
            $depth++;
            $value = $value === [] ? null : reset($value);
        }
        return $depth;
    }
}
