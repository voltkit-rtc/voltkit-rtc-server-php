<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use VoltKit\Rtc\Internal\Redactor;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\FakePsr17;
use VoltKit\Rtc\Tests\Support\FakePsr18Client;
use VoltKit\Rtc\Tests\Support\FakeResponse;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\Psr18Transport;
use VoltKit\Rtc\Webhooks;

/**
 * Independent adversarial verification of the 2026-10-31 fixes (PHP 0.1.2): decisions D4 to D9 and spec 3.5, tried
 * with inputs the round's own tests do not use. Written by a verifier who did not make the fixes; nothing here changes
 * product code.
 */
final class Verify1031Test extends TestCase
{
    use MakesClients;

    private const KEY = 'sk_verify_1031_3e9c7a1f5b2d8e4c6a0f9b3d7e1c5a8f2b6d0e4c';
    private const SECRET = 'whsec_golden_current';
    private const GOLDEN = '{"id":"evt_golden","type":"webhook.test","tenantId":"vk_golden","createdAt":1787000000000,"data":{"message":"golden"}}';
    private const V1 = '95abd781b3e5d318e78ed06645b74cb19f427e626b33e3c8de4cfa3429541f7a';
    private const GOLDEN_HEADER = 't=1787000000,v1=' . self::V1;
    private const T = 1787000000;

    private static function signed(string $body, string $secret, int $t = self::T): string
    {
        return "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret);
    }

    // --- spec 3.5 for the PSR-18 adapter: a refused redirect's body is never read -------------------------------

    #[Test]
    public function aRefusedRedirectWhoseBodyStallsIsRedirectedAtOnceThroughThePsr18AdapterToo(): void
    {
        // A body that is never there: every read blocks for ever in real life, so here it throws and records the try.
        $stream = new class () implements StreamInterface {
            public int $reads = 0;

            public function __toString(): string
            {
                $this->reads++;
                throw new \RuntimeException('the body stalled');
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
            }

            public function rewind(): void
            {
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $this->reads++;
                throw new \RuntimeException('the body stalled');
            }

            public function getContents(): string
            {
                $this->reads++;
                throw new \RuntimeException('the body stalled');
            }

            public function getMetadata(?string $key = null): mixed
            {
                return null;
            }
        };
        $reply = (new FakeResponse(302, ['Location' => 'https://elsewhere.test/v1/rooms'], ''))->withBody($stream);
        $psr = new FakePsr18Client([$reply]);
        $factory = new FakePsr17();
        $c = new StreamClient('https://x.test', self::KEY, retries: 2, transport: new Psr18Transport($psr, $factory, $factory), sleeper: static function (): void {
        });
        $e = $this->failure(static fn () => $c->listRooms());
        self::assertSame('redirected', $e->errorCode, $e->getMessage());
        self::assertSame(302, $e->status);
        self::assertFalse($e->retryable);
        self::assertStringContainsString('to https://elsewhere.test/v1/rooms;', $e->getMessage());
        self::assertCount(1, $psr->requests, 'a refused redirect is never retried');
        self::assertSame(0, $stream->reads, 'the refused reply\'s body is not read (spec 3.5)');
    }

    // --- D4: the two decodes and the memory they take -----------------------------------------------------------------

    #[Test]
    public function theCostliestRepliesUnderTheCapsDecodeTwiceUnder128M(): void
    {
        $probe = <<<'PHP'
        <?php
        declare(strict_types=1);
        require $argv[1];
        use VoltKit\Rtc\StreamClient;
        use VoltKit\Rtc\Transport\Request;
        use VoltKit\Rtc\Transport\Response;
        use VoltKit\Rtc\Transport\TransportInterface;
        final class Canned implements TransportInterface {
            public function __construct(private string $body) {}
            public function send(Request $request): Response { return new Response(200, ['content-type' => 'application/json'], $this->body); }
        }
        $pad = str_repeat('x', 6);
        $pad30 = str_repeat('x', 30);
        $shapes = [
            'participants: 99,998 empty arrays' => '{"participants":[' . implode(',', array_fill(0, 99998, '[]')) . ']}',
            'participants: 99,998 objects of 5 keys' => '{"participants":[' . implode(',', array_fill(0, 99998, '{"a":1,"b":2,"c":3,"d":4,"e":"' . $pad . '"}')) . ']}',
            'usage: one object of 300,000 keys' => '{"' . implode('":1,"k', range(1, 300000)) . '":1}',
            'participants: 99,998 arrays of one long string' => '{"participants":[' . implode(',', array_fill(0, 99998, '["' . $pad30 . '"]')) . ']}',
        ];
        foreach ($shapes as $name => $body) {
            if (strlen($body) > StreamClient::MAX_REPLY_BYTES) { fwrite(STDERR, "$name is over the byte cap: " . strlen($body) . "\n"); exit(2); }
            $client = new StreamClient('https://x.test', 'sk_test', transport: new Canned($body), retries: 0);
            $before = memory_get_peak_usage(true);
            $value = str_starts_with($name, 'usage') ? $client->getUsage() : $client->listParticipants('r');
            $peak = memory_get_peak_usage(true);
            printf("%s: %d bytes of JSON, peak %d MiB (+%d MiB), %d items\n", $name, strlen($body), intdiv($peak, 1 << 20), intdiv($peak - $before, 1 << 20), count($value));
            unset($value, $client, $body);
        }
        echo "OK\n";
        PHP;
        $file = tempnam(sys_get_temp_dir(), 'verify1031');
        self::assertNotFalse($file);
        file_put_contents($file, $probe);
        try {
            $command = sprintf(
                '%s -d memory_limit=128M -d display_errors=stderr %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($file),
                escapeshellarg(__DIR__ . '/../../vendor/autoload.php'),
            );
            exec($command, $lines, $exit);
            $output = implode("\n", $lines);
            self::assertSame(0, $exit, $output);
            self::assertStringEndsWith('OK', $output, $output);
            self::assertStringNotContainsString('Allowed memory size', $output);
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function oddTwoHundredBodies(): void
    {
        $cases = [
            ['{"token":null}', null, 'the reply is not the expected shape: "token" is not a non-empty string'],
            ['{"token":" "}', ' ', null],
            ['{"token":"\u0000"}', "\0", null],
            ['{"token":"t","Token":""}', 't', null],
            ['{"Token":"","token":"t"}', 't', null],
            ['{"Token":"t"}', null, 'the reply is not the expected shape: "token" is not a non-empty string'],
            ['{"token":"","token":"t"}', 't', null],
            ['{"token":"t","token":""}', null, 'the reply is not the expected shape: "token" is not a non-empty string'],
            ["  \n", null, "the reply is not JSON:   \n"],
            ['{"token":"t"} x', null, 'the reply is not JSON: {"token":"t"} x'],
            ['{"token":"t"}{"token":"t"}', null, 'the reply is not JSON: {"token":"t"}{"token":"t"}'],
            ['{"token":NaN}', null, 'the reply is not JSON: {"token":NaN}'],
            ['[{"token":"t"}]', null, 'the reply is not the expected shape: it is not an object'],
            [str_repeat('[', 20000), null, 'the reply is not JSON: ' . str_repeat('[', 100)],
            // fetch's text() strips a BOM and Node decodes this; json_decode does not: not JSON. Recorded.
            ["\xEF\xBB\xBF" . '{"token":"t"}', null, "the reply is not JSON: \xEF\xBB\xBF" . '{"token":"t"}'],
            // The NUL-led key trick must not change a value: a token that starts with a NUL is a non-empty string.
            ['{"\u0000x":1,"token":"\u0000t"}', "\0t", null],
            ['{"token":"\u0000"}', "\0", null],
        ];
        foreach ($cases as [$raw, $token, $what]) {
            $t = FakeTransport::raw(200, $raw);
            $c = $this->client($t);
            if ($token !== null) {
                self::assertSame($token, $c->createToken('r', 'u', 'viewer')['token'], $raw);
                continue;
            }
            $e = $this->failure(static fn () => $c->createToken('r', 'u', 'viewer'));
            self::assertSame(['http-200', 200, false, 1], [$e->errorCode, $e->status, $e->retryable, $t->attempts()], $raw);
            self::assertSame('POST /v1/rooms/r/tokens: ' . $what, $e->getMessage(), $raw);
            self::assertNull($e->getPrevious());
        }
    }

    #[Test]
    public function existedListsAndObjectFields(): void
    {
        self::assertFalse($this->client(FakeTransport::raw(201, '{"roomId":"r","hostToken":"t","existed":true}'))->ensureRoom('r', 'h')['existed']);
        self::assertTrue($this->client(FakeTransport::raw(200, '{"roomId":"r","hostToken":"t","existed":"nope"}'))->ensureRoom('r', 'h')['existed']);
        $c = $this->client(FakeTransport::raw(200, '{"existed":true,"token":"t"}'));
        self::assertSame('PUT /v1/rooms/r: the reply is not the expected shape: "roomId" is not a non-empty string', $this->failure(static fn () => $c->ensureRoom('r', 'h'))->getMessage());
        foreach (
            [
            ['{"participants":{"0":{}}}', 'listParticipants', 'GET /v1/rooms/r/participants: the reply is not the expected shape: "participants" is not an array'],
            ['{"participants":null}', 'listParticipants', 'GET /v1/rooms/r/participants: the reply is not the expected shape: "participants" is not an array'],
            ['{"participants":"[]"}', 'listParticipants', 'GET /v1/rooms/r/participants: the reply is not the expected shape: "participants" is not an array'],
            ['{"room":[]}', 'getRoom', 'GET /v1/rooms/r: the reply is not the expected shape: "room" is not an object'],
            ['{"room":null}', 'getRoom', 'GET /v1/rooms/r: the reply is not the expected shape: "room" is not an object'],
            ['{"room":[{"roomId":"r"}]}', 'getRoom', 'GET /v1/rooms/r: the reply is not the expected shape: "room" is not an object'],
            ['{"rooms":{}}', 'listRooms', 'GET /v1/rooms: the reply is not the expected shape: "rooms" is not an array'],
            ['{"rooms":{"0":{}}}', 'listRooms', 'GET /v1/rooms: the reply is not the expected shape: "rooms" is not an array'],
            ['[]', 'listRooms', 'GET /v1/rooms: the reply is not the expected shape: it is not an object'],
            ['{"0":{}}', 'listRooms', 'GET /v1/rooms: the reply is not the expected shape: "rooms" is not an array'],
            ] as [$raw, $method, $message]
        ) {
            $t = FakeTransport::raw(200, $raw);
            $c = $this->client($t);
            $e = $this->failure(static fn () => $method === 'listRooms' ? $c->listRooms() : $c->$method('r'));
            self::assertSame($message, $e->getMessage(), $raw);
            self::assertSame(1, $t->attempts(), $raw);
        }
        // An object whose keys are digits is still an object, and an array in it is still an array.
        self::assertSame([['identity' => 'u']], $this->client(FakeTransport::raw(200, '{"0":1,"participants":[{"identity":"u"}]}'))->listParticipants('r'));
        self::assertSame([], $this->client(FakeTransport::raw(200, '{"Participants":[1]}'))->listParticipants('r'));
        $c = $this->client(FakeTransport::raw(200, '{"data":{"0":1}}'), ratesBaseUrl: 'https://api.example.com');
        self::assertSame([1], $c->getRates());
        // A list `data` ({"data":[1]}) resolves here as in Node (typeof [] is 'object'); Python, Go and Java refuse it. Recorded.
    }

    // --- D5: webhooks at the edges --------------------------------------------------------------------------------

    #[Test]
    public function theClockAndToleranceAtTheEdges(): void
    {
        /** @param array<string, mixed> $options */
        $verify = static function (array $options): bool {
            // @phpstan-ignore argument.type (deliberately wrong options are the point)
            return Webhooks::verify(self::GOLDEN, self::GOLDEN_HEADER, self::SECRET, $options);
        };
        self::assertTrue($verify(['toleranceSeconds' => 0, 'now' => self::T + 0.999999]));
        self::assertFalse($verify(['toleranceSeconds' => 0, 'now' => self::T - 0.000001]));
        self::assertTrue($verify(['toleranceSeconds' => 1, 'now' => self::T - 0.000001]));
        self::assertTrue($verify(['toleranceSeconds' => 0.5, 'now' => self::T]));
        self::assertFalse($verify(['toleranceSeconds' => 0.5, 'now' => self::T + 1]));
        self::assertTrue($verify(['toleranceSeconds' => 1.5, 'now' => self::T + 1]));
        self::assertFalse($verify(['toleranceSeconds' => 1.5, 'now' => self::T + 2]));
        self::assertFalse($verify(['now' => 1e308]));
        self::assertFalse($verify(['now' => -1e308]));
        self::assertFalse($verify(['now' => -1]));
        self::assertFalse($verify(['now' => PHP_INT_MAX]));
        self::assertFalse($verify(['now' => PHP_INT_MIN]));
        self::assertTrue($verify(['toleranceSeconds' => INF, 'now' => 0]));
        self::assertTrue($verify(['toleranceSeconds' => PHP_INT_MAX, 'now' => 0]));
        self::assertTrue($verify(['toleranceSeconds' => -0.0, 'now' => self::T]));
        self::assertTrue($verify(['now' => static fn (): int => self::T]));
        self::assertTrue($verify(['now' => static fn (): float => self::T + 0.5]));
        foreach (['1787000000', '', true, false, [self::T], static fn (): string => '1787000000', static fn (): bool => true, new \DateTimeImmutable('@1787000000')] as $now) {
            try {
                $verify(['now' => $now]);
                self::fail('now ' . get_debug_type($now) . ' was taken');
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('now must be', $e->getMessage());
            }
        }
        foreach ([NAN, INF, -INF, static fn (): float => NAN, static fn (): float => INF] as $now) {
            try {
                $verify(['now' => $now]);
                self::fail('a non-finite now was taken');
            } catch (\RangeException $e) {
                self::assertStringStartsWith('now must be a finite', $e->getMessage());
            }
        }
        foreach (['300', true, [300], '', null] as $tolerance) {
            if ($tolerance === null) {
                self::assertTrue($verify(['toleranceSeconds' => null, 'now' => self::T]), 'null is the default');
                continue;
            }
            try {
                $verify(['toleranceSeconds' => $tolerance, 'now' => self::T]);
                self::fail('toleranceSeconds ' . get_debug_type($tolerance) . ' was taken');
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('toleranceSeconds must be', $e->getMessage());
            }
        }
        foreach ([NAN, -1, -0.5, -INF] as $tolerance) {
            try {
                $verify(['toleranceSeconds' => $tolerance, 'now' => self::T]);
                self::fail('a bad tolerance was taken');
            } catch (\RangeException $e) {
                self::assertStringStartsWith('toleranceSeconds must be', $e->getMessage());
            }
        }
        try {
            $verify(['tolerance' => 300, 'now' => self::T]);
            self::fail('a misspelt option was taken');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('unknown option', $e->getMessage());
        }
    }

    #[Test]
    public function blankSecretsAreExactlyJavaScriptsTrimSet(): void
    {
        $at = ['now' => self::T];
        $blanks = [' ', "\t", "\n", "\r", "\f", "\v", "\u{a0}", "\u{1680}", "\u{2003}", "\u{2028}", "\u{2029}", "\u{202f}", "\u{205f}", "\u{3000}", "\u{feff}", " \u{a0}\u{feff}\t"];
        foreach ($blanks as $blank) {
            $header = self::signed(self::GOLDEN, $blank);
            $shown = bin2hex($blank);
            self::assertFalse(Webhooks::verify(self::GOLDEN, $header, [$blank, self::SECRET], $at), "signed with $shown");
            self::assertTrue(Webhooks::verify(self::GOLDEN, self::GOLDEN_HEADER, [$blank, self::SECRET], $at), "$shown beside the real secret");
            foreach ([$blank, [$blank], [$blank, '', $blank . $blank]] as $only) {
                try {
                    Webhooks::verify(self::GOLDEN, $header, $only, $at);
                    self::fail("$shown alone was a secret");
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('needs the signing secret', $e->getMessage());
                }
            }
        }
        // Not in JavaScript's set: U+001C (a secret in Node too), U+0085, a Latin-1 NBSP byte (not UTF-8 text).
        foreach (["\x1c", "\u{85}", "\xA0", "\xFF\xFE"] as $real) {
            self::assertTrue(Webhooks::verify(self::GOLDEN, self::signed(self::GOLDEN, $real), $real, $at), bin2hex($real));
        }
        $padded = ' ' . self::SECRET . "\n";
        self::assertFalse(Webhooks::verify(self::GOLDEN, self::GOLDEN_HEADER, $padded, $at));
        self::assertTrue(Webhooks::verify(self::GOLDEN, self::signed(self::GOLDEN, $padded), $padded, $at));
        // A list with a non-string entry: the entry is skipped, the strings count.
        // @phpstan-ignore argument.type (a list with entries that are no strings)
        self::assertTrue(Webhooks::verify(self::GOLDEN, self::GOLDEN_HEADER, [null, 5, self::SECRET], $at));
    }

    /** @param string|list<string>|null $header */
    private static function verifiesAt(string|array|null $header): bool
    {
        return Webhooks::verify(self::GOLDEN, $header, self::SECRET, ['now' => self::T]);
    }

    #[Test]
    public function theHeaderGrammarAtTheEdges(): void
    {
        self::assertTrue(self::verifiesAt(' t=1787000000 , v1=' . self::V1 . ' '));
        self::assertTrue(self::verifiesAt('v1=' . self::V1 . ',t=1787000000'));
        self::assertTrue(self::verifiesAt('t=1787000000,v0=zzz,v1=' . self::V1));
        self::assertTrue(self::verifiesAt('t=1787000000,v1=' . str_repeat('0', 64) . ',v1=' . self::V1));
        self::assertTrue(self::verifiesAt(['t=1787000000', 'v1=' . self::V1]));
        self::assertTrue(self::verifiesAt("\x0Bt=1787000000,v1=" . self::V1 . "\f"));
        self::assertFalse(self::verifiesAt('t=1787000000,v1=' . str_repeat('Z', 64) . ',v1=' . self::V1));
        self::assertFalse(self::verifiesAt('t=1787000000,t=1787000000,v1=' . self::V1));
        self::assertFalse(self::verifiesAt('t=1787000000,v1=' . self::V1 . ','));
        self::assertFalse(self::verifiesAt('t=1787000000,v1=' . self::V1 . ',=x'));
        self::assertFalse(self::verifiesAt('t=1787000000,v1=' . strtoupper(self::V1)));
        self::assertFalse(self::verifiesAt('t=01787000000,v1=' . self::V1));
        self::assertFalse(self::verifiesAt('t=+1787000000,v1=' . self::V1));
        self::assertFalse(self::verifiesAt('t=1787000000;v1=' . self::V1));
        self::assertFalse(self::verifiesAt('t=1787000000,v1=' . self::V1 . str_repeat(',', 5000)));
        self::assertFalse(self::verifiesAt(''));
        self::assertFalse(self::verifiesAt(null));
        self::assertFalse(self::verifiesAt("t=1787000000\0,v1=" . self::V1));
        // Padding a part with U+FEFF or NBSP: JavaScript's trim() takes them, PHP's ASCII trim here does not. Stricter
        // than Node on a header no sender writes; recorded.
        self::assertFalse(self::verifiesAt("\u{feff}t=1787000000,v1=" . self::V1 . "\u{a0}"));
    }

    #[Test]
    public function parseTellsBodiesApartByTheirJson(): void
    {
        $at = ['now' => self::T];
        $parse = static fn (string $raw): array => Webhooks::parse($raw, self::signed($raw, self::SECRET), self::SECRET, $at);
        self::assertSame([1], $parse('{"0":1}'));
        self::assertSame([], $parse(' {} '));
        self::assertSame(['a' => []], $parse("\t{\"a\":[]}"));
        foreach (['[]', 'null', '5', '"s"', '[{"id":"x"}]', ' []'] as $raw) {
            try {
                $parse($raw);
                self::fail("$raw was an event");
            } catch (\UnexpectedValueException $e) {
                self::assertStringContainsString('not a JSON object', $e->getMessage());
            }
        }
        foreach (["\xEF\xBB\xBF{}", '{', '', 'nul'] as $raw) {
            try {
                $parse($raw);
                self::fail(bin2hex($raw) . ' was an event');
            } catch (\JsonException) {
                // a verified body that is not JSON: never from VoltKit
            }
        }
        $e = $this->failure(static fn () => Webhooks::parse('[]', self::signed('[]', self::SECRET, self::T - 301), self::SECRET, $at));
        self::assertSame(['bad-signature', 401, false], [$e->errorCode, $e->status, $e->retryable]);
    }

    // --- D7: URL credentials in every shape, and the clock -------------------------------------------------------------

    /** @return list<array{0: string, 1: string}> */
    private static function credentialCases(): array
    {
        return [
            ['https://u:p@[::1]:8/x', 'https://[redacted]@[::1]:8/x'],
            ['HTTPS://User:Pass@H.test/', 'HTTPS://[redacted]@H.test/'],
            ['scheme://@host/x', 'scheme://[redacted]@host/x'],
            ['https://user@host/x', 'https://[redacted]@host/x'],
            ['user@host', 'user@host'],
            ['https://h/p?next=https://u:p@ss@evil/x', 'https://h/p?next=https://[redacted]@evil/x'],
            ['https://h?e=a@b', 'https://h?e=a@b'],
            ['https://user:p@ssword@h', 'https://[redacted]@h'],
            ['https://user:hunter2/x@h', 'https://[redacted]@h'],
            ['https://user:pa?ss@h', 'https://[redacted]@h'],
            ['https://user:pa#ss@h', 'https://[redacted]@h'],
            ['https://user:123/x@h', 'https://user:123/x@h'],
            ['https://user:123@h', 'https://[redacted]@h'],
            ['https://user:@h', 'https://[redacted]@h'],
            ['https://:pw@h', 'https://[redacted]@h'],
            ['mailto:me@host', 'mailto:me@host'],
            ['1https://u:p@h', '1https://[redacted]@h'],
            ['x1https://u:p@h', 'x1https://[redacted]@h'],
            ['+a://u:p@h', '+a://[redacted]@h'],
            ['https://u:p@h https://v:q@i', 'https://[redacted]@h https://[redacted]@i'],
            ["https://u:p@h\u{a0}https://v:q@i", "https://[redacted]@h\u{a0}https://[redacted]@i"],
            ['see https://u:p@h.', 'see https://[redacted]@h.'],
            ['(https://u:p@h)', '(https://[redacted]@h)'],
            ['https://u:p@h@i', 'https://[redacted]@i'],
            ['https://a@b:c@d', 'https://[redacted]@d'],
            ['https://u:p/q@h@i', 'https://[redacted]@i'],
            ['https://u:p/q@h/@i', 'https://[redacted]@i'],
            ['https://u:p@h/x?y=1#z@w', 'https://[redacted]@h/x?y=1#z@w'],
            ['https://h/x@y:z@w', 'https://h/x@y:z@w'],
            ['https://user:p%40ss@h', 'https://[redacted]@h'],
            ["https://u:p@h\nhttps://v:q@i", "https://[redacted]@h\nhttps://[redacted]@i"],
            ['https://@', 'https://[redacted]@'],
            ['@https://u:p@h', '@https://[redacted]@h'],
            ['https://u:p@h@', 'https://[redacted]@'],
            ['a://b://c@', 'a://b://[redacted]@'],
            ["https://u:p@h\u{85}https://v:q@i", "https://[redacted]@h\u{85}https://[redacted]@i"],
            ['https://u:12/x@h https://v:p@i', 'https://u:12/x@h https://[redacted]@i'],
            ["https://u:p@h/\u{3000}https://v:q@i", "https://[redacted]@h/\u{3000}https://[redacted]@i"],
            // Not UTF-8: the per-word pattern must still not let a password through.
            ["https://u:p\xFF@h", 'https://[redacted]@h'],
        ];
    }

    #[Test]
    public function credentialShapesInTheRedactorAndInAMessage(): void
    {
        foreach (self::credentialCases() as [$text, $want]) {
            $got = Redactor::redactUrlCredentials($text);
            self::assertTrue($got === $want || ($got === Redactor::KEY_NOT_CHECKED && !mb_check_encoding($text, 'UTF-8')), bin2hex($text) . ' -> ' . bin2hex($got));
            if (!mb_check_encoding($text, 'UTF-8')) {
                continue;
            }
            $c = $this->client(FakeTransport::answering(500, ['error' => 'x', 'message' => $text]), retries: 0);
            $e = $this->failure(static fn () => $c->listRooms());
            self::assertSame('GET /v1/rooms: ' . $want, $e->getMessage(), $text);
            self::assertSame($e->getMessage(), (string) $e->getMessage());
            self::assertStringContainsString($want, (string) $e);
        }
    }

    #[Test]
    public function adversarialPagesStayFast(): void
    {
        $key = self::KEY;
        $pages = [
            'a://b:c repeated, one @' => str_repeat('a://b:c', 43000) . '@',
            'a://b:c@ repeated' => str_repeat('a://b:c@', 37000),
            'only @' => str_repeat('@', 300000),
            'a: repeated then ://x@' => str_repeat('a:', 150000) . '://x@',
            'a://b:1/ repeated, one @' => str_repeat('a://b:1/', 37000) . '@',
            'a:// repeated, one @' => str_repeat('a://', 75000) . '@',
            '://: repeated' => str_repeat('://:', 75000) . '@',
            'a long scheme-like run then @' => str_repeat('a', 300000) . '://u:p@h',
            'zeros in a reference' => '&#' . str_repeat('0', 300000) . '95;',
            "the key's first character" => str_repeat($key[0], 300000),
            'near misses of the key' => str_repeat(substr($key, 0, -1) . ' ', 5000),
            '%73 repeated' => str_repeat('%73', 100000),
            '@ and : alternating' => str_repeat('@:', 150000),
            'words with an @ each' => str_repeat('u:p@h ', 50000),
            'digits after every colon' => str_repeat('a://b:1', 43000) . '@',
            'many schemes in one word, no @' => str_repeat('a://', 75000),
            'the key 1,000 times in a word' => str_repeat($key, 1000),
        ];
        foreach ($pages as $name => $page) {
            $c = $this->client(FakeTransport::answering(500, ['error' => 'x', 'message' => $page]), apiKey: $key, retries: 0);
            $started = hrtime(true);
            $message = $this->failure(static fn () => $c->listRooms())->getMessage();
            $ms = (hrtime(true) - $started) / 1_000_000;
            self::assertLessThan(1000, $ms, "$name: $ms ms");
            self::assertStringNotContainsString($key, $message, $name);
            if ($name !== 'near misses of the key') {
                self::assertFalse(self::holdsPartOf($message, $key), $name);
            }
        }
    }

    #[Test]
    public function aTinyBacktrackLimitNeverLetsCredentialsOrTheKeyThrough(): void
    {
        $limit = (string) ini_get('pcre.backtrack_limit');
        self::assertNotFalse(ini_set('pcre.backtrack_limit', '50'));
        try {
            foreach (
                [
                'https://deploy:hunter2@x.test/' . str_repeat('x', 5000),
                str_repeat('a://b:c', 2000) . 'https://deploy:hunter2@x.test',
                'https://deploy:hunter2@x.test ' . str_repeat('y ', 5000),
                ] as $page
            ) {
                $out = Redactor::redactUrlCredentials($page);
                self::assertStringNotContainsString('hunter2', $out, 'credentials survived a failing engine: ' . substr($out, 0, 80));
                $c = $this->client(FakeTransport::answering(500, ['error' => 'x', 'message' => $page . ' key=' . self::KEY]), apiKey: self::KEY, retries: 0);
                $message = $this->failure(static fn () => $c->listRooms())->getMessage();
                self::assertStringNotContainsString('hunter2', $message);
                self::assertFalse(self::holdsPartOf($message, self::KEY), $message);
            }
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    // --- D9: trace arguments that are objects -------------------------------------------------------------------------

    /** @return iterable<string> every string reachable from `$value`: arrays, objects' properties (private ones too) */
    private static function texts(mixed $value, int $depth = 0): iterable
    {
        if ($depth > 8) {
            return;
        }
        if (is_string($value)) {
            yield $value;
        } elseif (is_array($value)) {
            foreach ($value as $k => $item) {
                yield (string) $k;
                yield from self::texts($item, $depth + 1);
            }
        } elseif ($value instanceof \SensitiveParameterValue) {
            yield '[SensitiveParameterValue]';
        } elseif ($value instanceof \Closure) {
            yield '[Closure]';
        } elseif (is_object($value)) {
            yield $value::class;
            foreach ((array) $value as $k => $item) {
                yield from self::texts($item, $depth + 1);
            }
        }
    }

    #[Test]
    public function noTraceArgumentHoldsTheKeyOrAPasswordThroughAnObjectEither(): void
    {
        $ignoreArgs = (string) ini_get('zend.exception_ignore_args');
        $maxLen = (string) ini_get('zend.exception_string_param_max_len');
        self::assertNotFalse(ini_set('zend.exception_ignore_args', '0'));
        self::assertNotFalse(ini_set('zend.exception_string_param_max_len', '15'));
        try {
            $factory = new FakePsr17();
            $failures = [
                'a PSR-18 network failure' => static function () use ($factory): void {
                    $psr = new FakePsr18Client([null]);
                    (new StreamClient('https://deploy:hunter2@x.test', self::KEY, retries: 0, transport: new Psr18Transport($psr, $factory, $factory)))->getUsage();
                },
                'a PSR-18 reply echoing the key' => static function () use ($factory): void {
                    $psr = new FakePsr18Client([new FakeResponse(401, [], '{"error":"Invalid API key: ' . self::KEY . '","message":"at https://deploy:hunter2@x.test"}')]);
                    (new StreamClient('https://deploy:hunter2@x.test', self::KEY, retries: 0, transport: new Psr18Transport($psr, $factory, $factory)))->getUsage();
                },
                'a PSR-18 redirect whose location holds the key' => static function () use ($factory): void {
                    $psr = new FakePsr18Client([new FakeResponse(302, ['Location' => 'https://elsewhere.test/?k=' . self::KEY], '')]);
                    (new StreamClient('https://deploy:hunter2@x.test', self::KEY, retries: 0, transport: new Psr18Transport($psr, $factory, $factory)))->getUsage();
                },
                'a fake transport throwing with the request in its words' => static function (): void {
                    (new StreamClient('https://deploy:hunter2@x.test', self::KEY, retries: 0, transport: FakeTransport::failing('boom https://deploy:hunter2@x.test key ' . self::KEY)))->getUsage();
                },
                'a refused URL' => static function (): void {
                    new StreamClient('https://deploy:hunter2@x.test/?q', self::KEY);
                },
                'a refused rates URL' => static function (): void {
                    new StreamClient('https://x.test', self::KEY, 'ftp://deploy:hunter2@x.test');
                },
                'a webhook that does not verify' => static function (): void {
                    Webhooks::parse('{}', 't=1787000000,v1=' . str_repeat('0', 64), ['whsec_hunter2_secret'], ['now' => self::T]);
                },
                'a webhook with no usable secret' => static function (): void {
                    Webhooks::verify('{}', 't=1787000000,v1=' . str_repeat('0', 64), [' ', "\t"], ['now' => self::T]);
                },
            ];
            foreach ($failures as $name => $failure) {
                try {
                    $failure();
                    self::fail("$name did not fail");
                } catch (\Throwable $e) {
                    for ($chain = $e; $chain !== null; $chain = $chain->getPrevious()) {
                        $seen = [];
                        foreach ($chain->getTrace() as $frame) {
                            foreach (self::texts($frame['args'] ?? []) as $text) {
                                $seen[] = $text;
                            }
                        }
                        $joined = implode("\n", $seen);
                        self::assertStringNotContainsString('hunter2', $joined, "$name: " . $chain::class);
                        self::assertFalse(self::holdsPartOf($joined, self::KEY), "$name: " . $chain::class);
                        $shown = (string) $chain;
                        self::assertStringNotContainsString('hunter2', $shown, $name);
                        self::assertFalse(self::holdsPartOf($shown, self::KEY), $name);
                    }
                    if ($e instanceof StreamError) {
                        self::assertNull($e->getPrevious(), "$name: a StreamError chains nothing");
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
            ini_set('zend.exception_string_param_max_len', $maxLen);
        }
    }

    // --- D8: URLs at the edges, as both options ----------------------------------------------------------------------

    #[Test]
    public function urlsRefusedAsEitherOptionQuoteNothing(): void
    {
        $refused = [
            'https://x.test:', 'https://x.test:1 /', 'https://x. test', "https://x.test:\u{664}\u{664}\u{663}", 'https://x.test:+443', 'https://x.test:-1',
            'https://x.test:443:443', 'https://[::1]:', 'https://[]', 'https://[::1]x', 'https://u:p@', 'https://u:p@:443', 'https://u:p@/x',
            "https://x.test/\x7f", "https://x.test\u{2028}", 'https:\\\\x.test', 'https://x.test/?', 'https://x.test/#', "https://user:pa\u{2100}ss@h.test",
            'https://user:hunter2?ss@x.test', 'https://user:hunter2#ss@x.test', 'http://user:hunter2/with/slash@127.0.0.1:9', 'ftp://user:hunter2@x.test',
            'https://user:hunter2@', 'https://[::1::2]', 'https://[1.2.3.4]', 'https://x,y.test', 'https://"x.test"', 'https://x.test%41',
        ];
        foreach ($refused as $url) {
            foreach (['baseUrl', 'ratesBaseUrl'] as $option) {
                try {
                    $option === 'baseUrl'
                        ? new StreamClient($url, 'sk_test', transport: new FakeTransport())
                        : new StreamClient('https://x.test', 'sk_test', $url, transport: new FakeTransport());
                    self::fail("$url accepted as $option");
                } catch (\InvalidArgumentException $e) {
                    $text = $e->getMessage() . ' ' . $e;
                    self::assertStringStartsWith($option . ' ', $e->getMessage(), $url);
                    self::assertStringNotContainsString('hunter2', $text, $url);
                    self::assertStringNotContainsString('[::1', $text, $url);
                    self::assertStringNotContainsString('x.test', str_replace('https://api.voltkit.in', '', $e->getMessage()), $url);
                    self::assertNull($e->getPrevious(), $url);
                }
            }
        }
        $accepted = [
            'https://@h.test' => 'https://@h.test', 'http://user@h.test' => 'http://user@h.test', 'https://u:p@[::1]:8/' => 'https://u:p@[::1]:8',
            'HTTPS://U:P@H.TEST/' => 'HTTPS://U:P@H.TEST', 'https://x.test//' => 'https://x.test/', 'https://x.test:0443' => 'https://x.test:0443',
            'https://x_y.test' => 'https://x_y.test', 'https://x.test/a@b/c@d' => 'https://x.test/a@b/c@d', 'https://x.test/%zz' => 'https://x.test/%zz',
            'https://u:p%40w@h.test' => 'https://u:p%40w@h.test',
        ];
        foreach ($accepted as $url => $origin) {
            $t = FakeTransport::raw(200, '{"rooms":[]}');
            $c = new StreamClient($url, 'sk_test', transport: $t);
            $c->listRooms();
            self::assertSame($origin . '/v1/rooms', $t->last()->url, $url);
            self::assertStringNotContainsString('u:p', (string) $c, $url);
            self::assertStringNotContainsString('U:P', (string) $c, $url);
        }
    }
}
