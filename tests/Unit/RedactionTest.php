<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 5.5: every form of the key is redacted, near misses are kept, the cap fails closed, URL credentials go. */
final class RedactionTest extends TestCase
{
    use MakesClients;

    public const LIVE_KEY = 'sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0';
    public const BASE64_KEY = 'sk_live_Zm9v+YmFyLzk/QmF6+cXV4/dGVzdF9rZXk+MTIz/NDU2Nzg==';
    public const NOT_CHECKED = '[not shown: it could not be checked for the API key]';

    /** Every printable ASCII character but the backslash: each one a key may hold. */
    public static function asciiKey(): string
    {
        $key = 'sk_';
        for ($c = 0x20; $c <= 0x7E; $c++) {
            if ($c !== 0x5C) {
                $key .= chr($c);
            }
        }
        return $key;
    }

    /** What the SDK shows for the server's words `$message`, with `$apiKey` in use. */
    private function shown(string $apiKey, string $message, int $status = 401): string
    {
        $t = new FakeTransport(['status' => $status, 'body' => ['error' => 'unauthorized', 'message' => $message]]);
        $e = $this->failure(fn () => $this->client($t, apiKey: $apiKey, retries: 0)->listRooms());
        self::assertStringStartsWith('GET /v1/rooms: ', $e->getMessage());
        return substr($e->getMessage(), strlen('GET /v1/rooms: '));
    }

    // --- the encoders a server, a proxy or a framework writes text with ---------------------------------------------

    /** @param callable(string): ?string $as */
    private static function eachChar(string $s, callable $as): string
    {
        $out = '';
        foreach (str_split($s) as $ch) {
            $form = $as($ch);
            $out .= $form ?? $ch;
        }
        return $out;
    }

    private const HTML5_NAMES = [
        "\t" => '&Tab;', '!' => '&excl;', '"' => '&quot;', '#' => '&num;', '$' => '&dollar;', '%' => '&percnt;', '&' => '&amp;',
        "'" => '&apos;', '(' => '&lpar;', ')' => '&rpar;', '*' => '&ast;', '+' => '&plus;', ',' => '&comma;', '.' => '&period;',
        '/' => '&sol;', ':' => '&colon;', ';' => '&semi;', '<' => '&lt;', '=' => '&equals;', '>' => '&gt;', '?' => '&quest;',
        '@' => '&commat;', '[' => '&lsqb;', '\\' => '&bsol;', ']' => '&rsqb;', '^' => '&Hat;', '_' => '&lowbar;', '`' => '&grave;',
        '{' => '&lcub;', '|' => '&verbar;', '}' => '&rcub;',
    ];
    private const HTML5_OTHER_NAMES = [
        '"' => '&QUOT;', '&' => '&AMP;', '*' => '&midast;', '<' => '&LT;', '>' => '&GT;', '[' => '&lbrack;', ']' => '&rbrack;',
        '_' => '&UnderBar;', '`' => '&DiacriticalGrave;', '{' => '&lbrace;', '|' => '&VerticalLine;', '}' => '&rbrace;',
    ];

    /** @return array<string, callable(string): string> one layer: the ways text is written into a reply */
    public static function oneLayer(): array
    {
        $isAlnum = static fn (string $ch): bool => ctype_alnum($ch);
        $hex = static fn (string $ch, int $width): string => str_pad(dechex(ord($ch)), $width, '0', STR_PAD_LEFT);
        $json = static fn (string $s): string => substr(json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
        return [
            'as is' => static fn (string $s): string => $s,
            'encodeURIComponent' => static fn (string $s): string => strtr(rawurlencode($s), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']),
            'lower-case hex' => static fn (string $s): string => preg_replace_callback('/%[0-9A-F]{2}/', static fn ($m) => strtolower($m[0]), rawurlencode($s)) ?? '',
            "Python's quote, keeping /" => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => preg_match('/[A-Za-z0-9_.~\/-]/', $ch) === 1 ? null : '%' . strtoupper(bin2hex($ch))),
            'form-encoded' => static fn (string $s): string => urlencode($s),
            'JSON' => $json,
            "PHP's \\/" => static fn (string $s): string => substr(json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 1, -1),
            '\\u for every character' => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => '\\u' . $hex($ch, 4)),
            'HTML, &quot; and &#39;' => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;'][$ch] ?? null),
            "PHP's &#039;" => static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML401),
            '&#x for every symbol' => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => $isAlnum($ch) ? null : '&#x' . $hex($ch, 2) . ';'),
            '&#X, upper case' => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => $isAlnum($ch) ? null : '&#X' . strtoupper($hex($ch, 2)) . ';'),
            '&# for every character' => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => '&#' . ord($ch) . ';'),
            'HTML5 names' => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => self::HTML5_NAMES[$ch] ?? null),
            'HTML5 names, other spellings' => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => self::HTML5_OTHER_NAMES[$ch] ?? null),
        ];
    }

    /** @return array<string, callable(string): string> a second layer: what the standard escapers make of the first one's escapes */
    public static function secondLayer(): array
    {
        $one = self::oneLayer();
        $isAlnum = static fn (string $ch): bool => ctype_alnum($ch);
        $hex = static fn (string $ch, int $width): string => str_pad(dechex(ord($ch)), $width, '0', STR_PAD_LEFT);
        return [
            'URL-encoded again' => $one['encodeURIComponent'],
            "Python's quote" => $one["Python's quote, keeping /"],
            'form-encoded' => $one['form-encoded'],
            'JSON' => $one['JSON'],
            "PHP's json_encode" => $one["PHP's \\/"],
            "Go's JSON, & as \\u0026" => static fn (string $s): string => strtr($one['JSON']($s), ['&' => '\\u0026', '<' => '\\u003c', '>' => '\\u003e']),
            'HTML' => $one['HTML, &quot; and &#39;'],
            "Go's HTML, &#34;" => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&#34;', "'" => '&#39;'][$ch] ?? null),
            '&#x for every symbol' => $one['&#x for every symbol'],
            '&# for every symbol' => static fn (string $s): string => self::eachChar($s, static fn (string $ch) => $isAlnum($ch) ? null : '&#' . ord($ch) . ';'),
            'HTML5 names' => $one['HTML5 names'],
        ];
    }

    #[Test]
    public function theReportedFormsAreRedacted(): void
    {
        $quoted = 'sk_live_say_"hi"_0123456789abcdef';
        $spaced = 'sk_live_two words_0123456789abcdef';
        $one = self::oneLayer();
        $reported = [
            ['%252F: URL-encoded twice', self::BASE64_KEY, rawurlencode(rawurlencode(self::BASE64_KEY))],
            ["\\\\\\/: PHP's json_encode twice", self::BASE64_KEY, $one["PHP's \\/"]($one["PHP's \\/"](self::BASE64_KEY))],
            ['&#x2F;: an HTML reference', self::BASE64_KEY, str_replace('/', '&#x2F;', self::BASE64_KEY)],
            ['&#X5F;: an upper-case X', self::LIVE_KEY, str_replace('_', '&#X5F;', self::LIVE_KEY)],
            ['\\&quot;: JSON, then HTML', $quoted, $one['HTML, &quot; and &#39;']($one['JSON']($quoted))],
            ['%2B: form-encoded, then URL-encoded', $spaced, rawurlencode(urlencode($spaced))],
            ['&amp;quot;: HTML twice', $quoted, $one['HTML, &quot; and &#39;']($one['HTML, &quot; and &#39;']($quoted))],
            ['mixed-case hex', self::BASE64_KEY, (static function (): string {
                $flip = false;
                return preg_replace_callback('/%[0-9A-F]{2}/', static function ($m) use (&$flip) {
                    $flip = !$flip;
                    return $flip ? strtolower($m[0]) : $m[0];
                }, rawurlencode(self::BASE64_KEY)) ?? '';
            })()],
        ];
        foreach ($reported as [$what, $key, $echo]) {
            self::assertNotSame($key, $echo, "$what: differs from the key, or this proves nothing");
            self::assertSame('bad key [redacted]', $this->shown($key, "bad key $echo"), $what);
        }
    }

    #[Test]
    public function everyOneLayerFormAndEverySecondLayerOfAKeyHoldingEveryCharacterIsRedacted(): void
    {
        $key = self::asciiKey();
        $missed = [];
        foreach (self::oneLayer() as $oneName => $encode) {
            $forms = ["$oneName" => $encode($key)];
            foreach (self::secondLayer() as $twoName => $again) {
                $forms["$oneName, then $twoName"] = $again($encode($key));
            }
            foreach ($forms as $what => $form) {
                if ($this->shown($key, "bad key $form") !== 'bad key [redacted]') {
                    $missed[] = $what;
                }
            }
        }
        self::assertSame([], $missed);
    }

    #[Test]
    public function everyKeyVoltKitIssuesAndAnyPrintableAsciiKeyIsRedactedInTheOneLayerForms(): void
    {
        $adversarial = 'sk_' . str_repeat('%25&#x2F;&amp;+ "/_x;', 12);
        foreach ([self::LIVE_KEY, 'sk_dev_local_key_change_me', self::BASE64_KEY, self::asciiKey(), $adversarial, "sk_a\tb_0123456789abcdef", ' ' . self::LIVE_KEY . "\r\n"] as $key) {
            $sent = trim($key, " \t\r\n");
            foreach (array_slice(self::oneLayer(), 0, 8, true) as $what => $encode) {
                self::assertSame('bad key [redacted]', $this->shown($key, 'bad key ' . $encode($sent)), substr($sent, 0, 12) . ": $what");
            }
        }
    }

    #[Test]
    public function wordsWithoutTheKeyAreKeptExactlyWhateverTheyEncode(): void
    {
        $words = [
            'room not found',
            'Invalid API key',
            'GET https://voltkit.in/docs?a=1&b=%2F%2b%252F#top failed',
            '<p>Tom &amp; Jerry &#x2F; &#47; &#X5F; &quot;hi&quot; &lt;b&gt; &sol; &lowbar; &amp;amp;</p>',
            'a \\" b \\/ c \\u0026 d \\\\ e \\\\\\/ f \\&quot; g',
            'für ü 房间',
        ];
        $adversarial = 'sk_' . str_repeat('%25&#x2F;&amp;+ "/_x;', 12);
        foreach ([self::LIVE_KEY, self::BASE64_KEY, self::asciiKey(), $adversarial] as $key) {
            $near = [];
            foreach (self::oneLayer() as $encode) {
                $near[] = $encode(substr($key, 0, -1));
                $near[] = $encode(substr($key, 1));
            }
            foreach ([...$words, ...$near] as $message) {
                self::assertSame($message, $this->shown($key, $message), substr($key, 0, 12));
            }
        }
    }

    #[Test]
    public function theKeyIsRedactedInTheCodeFieldToo(): void
    {
        $t = new FakeTransport(['status' => 401, 'body' => ['error' => 'Invalid API key: ' . self::BASE64_KEY]]);
        $e = $this->failure(fn () => $this->client($t, apiKey: self::BASE64_KEY, retries: 0)->listRooms());
        self::assertSame('Invalid API key: [redacted]', $e->getErrorCode());
        self::assertFalse(self::holdsPartOf($e->getErrorCode() . $e->getMessage(), self::BASE64_KEY));
    }

    #[Test]
    public function redactionHappensBeforeAnyCut(): void
    {
        $t = new FakeTransport(['status' => 502, 'raw' => '<pre>' . str_repeat('.', 280) . self::LIVE_KEY . '</pre>']);
        $e = $this->failure(fn () => $this->client($t, apiKey: self::LIVE_KEY, retries: 0)->listStreams());
        self::assertSame('GET /v1/streams: <pre>' . str_repeat('.', 280) . '[redacted]</pre', $e->getMessage());

        $t = new FakeTransport(['status' => 200, 'raw' => str_repeat('.', 85) . self::LIVE_KEY]);
        $e = $this->failure(fn () => $this->client($t, apiKey: self::LIVE_KEY, retries: 0)->listRooms());
        self::assertSame('GET /v1/rooms: the reply is not JSON: ' . str_repeat('.', 85) . '[redacted]', $e->getMessage());
    }

    #[Test]
    public function theTransportsOwnWordsAreRedactedCredentialsAndKeyAlike(): void
    {
        $t = FakeTransport::failing('request to https://deploy:hunter2@x.test/v1/billing/balance failed, reason: connect ECONNREFUSED');
        $e = $this->failure(fn () => $this->client($t, baseUrl: 'https://deploy:hunter2@x.test', retries: 0)->getBalance());
        self::assertSame('GET /v1/billing/balance: request to https://[redacted]@x.test/v1/billing/balance failed, reason: connect ECONNREFUSED', $e->getMessage());

        $t = FakeTransport::failing('Headers.append: "' . self::LIVE_KEY . '" is an invalid header value.');
        $e = $this->failure(fn () => $this->client($t, apiKey: self::LIVE_KEY, retries: 0)->listRooms());
        self::assertSame('GET /v1/rooms: Headers.append: "[redacted]" is an invalid header value.', $e->getMessage());
    }

    #[Test]
    public function aKeyOfExactly256CharactersIsLookedForAndALongerOneIsNot(): void
    {
        $atCap = 'sk_live_' . str_repeat('Ab+/=', 49) . 'xyz';
        self::assertSame(256, strlen($atCap));
        $over = $atCap . '!';
        self::assertSame('bad key [redacted]', $this->shown($atCap, 'bad key ' . rawurlencode($atCap)));
        self::assertSame('bad key ' . self::NOT_CHECKED, $this->shown($over, 'bad key ' . rawurlencode($over)));
        self::assertSame('room not found', $this->shown($over, 'room not found'), 'short words are kept');

        $t = new FakeTransport(['status' => 503, 'body' => ['error' => 'billing-unavailable', 'message' => 'later']]);
        $e = $this->failure(fn () => $this->client($t, apiKey: $over, retries: 0)->listRooms());
        self::assertSame('billing-unavailable', $e->getErrorCode(), 'decisions on the code are unchanged');
        self::assertFalse($e->isRetryable());
    }

    #[Test]
    public function withALongKeyOnlyTheWordsAtLeastAsLongAsItAreWithheld(): void
    {
        $over = 'sk_' . str_repeat('ab12', 80);
        $long = implode(' ', array_fill(0, 12, 'room not found: please check the identifier and try again'));
        self::assertGreaterThan(strlen($over), strlen($long));
        self::assertSame($long, $this->shown($over, $long), 'short words are kept, however long the message');
        self::assertSame('bad key ' . self::NOT_CHECKED . ' given', $this->shown($over, "bad key $over given"));
        self::assertSame('bad key ' . self::NOT_CHECKED, $this->shown($over, 'bad key "' . rawurlencode("k=$over") . '"'));
    }

    #[Test]
    public function aLongKeyHoldingATabWithholdsTheWholeMessageUnlessItIsShorterThanTheKey(): void
    {
        $half = str_repeat('ab12', 40);
        $tabbed = "sk_$half\t$half";
        self::assertSame(self::NOT_CHECKED, $this->shown($tabbed, "bad key sk_$half $half given"));
        self::assertSame('room not found', $this->shown($tabbed, 'room not found'));
        self::assertSame(self::NOT_CHECKED, $this->shown($tabbed, str_repeat('word ', 80)), 'a long message of short words goes too');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function urlCredentials(): iterable
    {
        yield 'user and password' => ['see https://user:pass@host.test/path', 'see https://[redacted]@host.test/path'];
        yield 'upper case, ws, wss' => ['HTTP://U:P@H and ws://a@b and wss://x:y@z/q?k=1', 'HTTP://[redacted]@H and ws://[redacted]@b and wss://[redacted]@z/q?k=1'];
        yield 'schemes with + . -' => ['git+ssh://me@host and custom.v1-x://u@h', 'git+ssh://[redacted]@host and custom.v1-x://[redacted]@h'];
        yield 'mailto is not a URL with credentials' => ['mailto:me@host.test is not a URL with credentials', 'mailto:me@host.test is not a URL with credentials'];
        yield 'an @ in the path' => ['https://host.test/a@b in the path', 'https://host.test/a@b in the path'];
        yield 'a scheme starts at the first letter of its run' => ['xhttps://u@h 1https://u@h a1http://u@h -9Cd://u@h', 'xhttps://[redacted]@h 1https://[redacted]@h a1http://[redacted]@h -9Cd://[redacted]@h'];
        yield 'two URLs' => ['two://a@b three://c:d@e', 'two://[redacted]@b three://[redacted]@e'];
        yield 'no scheme' => ['no scheme user@host', 'no scheme user@host'];
        yield 'empty user info, and two @' => ['http://@empty and https://u@h@i/x', 'http://[redacted]@empty and https://[redacted]@i/x'];
        yield 'a password with an @' => ['https://admin:hunter2@pw@127.0.0.1:9/x', 'https://[redacted]@127.0.0.1:9/x'];
        yield 'credentials in the key message too' => ['bad key sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0 at https://a:b@c', 'bad key [redacted] at https://[redacted]@c'];
    }

    #[Test]
    #[DataProvider('urlCredentials')]
    public function urlCredentialsBecomeRedacted(string $text, string $expected): void
    {
        self::assertSame($expected, $this->shown(self::LIVE_KEY, $text));
    }

    #[Test]
    public function aLargeReplyIsRedactedQuickly(): void
    {
        foreach ([self::LIVE_KEY, self::asciiKey(), 'sk_' . str_repeat('%25&#x2F;&amp;+ "/_x;', 12)] as $key) {
            $near = [];
            foreach (self::oneLayer() as $one) {
                foreach (self::secondLayer() as $two) {
                    $near[] = substr($two($one($key)), 0, -1);
                }
            }
            $filler = implode(' ', $near);
            $body = substr(str_repeat($filler, (int) ceil(200_000 / strlen($filler))), 0, 200_000) . ' ' . rawurlencode($key);
            $started = hrtime(true);
            $shown = $this->shown($key, $body);
            $tookMs = (hrtime(true) - $started) / 1e6;
            self::assertStringEndsWith(' [redacted]', $shown);
            self::assertLessThan(strlen($body), strlen($shown));
            self::assertLessThan(4000, $tookMs, substr($key, 0, 12) . ": a 200 KB reply took {$tookMs} ms");
        }

        $page = str_repeat('abcdefghij', 10_000);
        $started = hrtime(true);
        $t = new FakeTransport(['status' => 500, 'raw' => $page]);
        $e = $this->failure(fn () => $this->client($t, apiKey: self::LIVE_KEY, retries: 0)->listRooms());
        self::assertSame(500, $e->getStatus());
        self::assertLessThan(1000, (hrtime(true) - $started) / 1e6, 'a 100 KB page of letters is linear, not quadratic');
    }

    #[Test]
    public function multibyteTextAroundTheKeyIsKept(): void
    {
        self::assertSame('bad key [redacted] für ü 房间', $this->shown(self::LIVE_KEY, 'bad key ' . self::LIVE_KEY . ' für ü 房间'));
        self::assertSame('ü[redacted]房', $this->shown(self::BASE64_KEY, 'ü' . rawurlencode(self::BASE64_KEY) . '房'));
    }

    #[Test]
    public function aMultiMegabyteReplyBuiltToDefeatTheMatcherIsRedactedInBoundedTimeAndMemory(): void
    {
        // Review 2026-10-23, finding 4: a reply of the key's first character repeated (or of near misses) made the
        // matcher try every form of the second character at every byte and remember every failure, taking seconds
        // and hundreds of megabytes per megabyte: a fatal "allowed memory size exhausted" in a web request.
        $megabyte = 1 << 20;
        $nearMiss = substr(self::LIVE_KEY, 0, -1) . ' ';
        $bodies = [
            'the first character repeated' => str_repeat(self::LIVE_KEY[0], $megabyte),
            'near misses back to back' => str_repeat($nearMiss, intdiv($megabyte, strlen($nearMiss))),
            'the key back to back' => str_repeat(self::LIVE_KEY, intdiv($megabyte, strlen(self::LIVE_KEY))),
        ];
        foreach ($bodies as $what => $body) {
            $t = new FakeTransport(['status' => 500, 'body' => ['error' => 'internal', 'message' => $body]]);
            $c = $this->client($t, apiKey: self::LIVE_KEY, retries: 0);
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_peak_usage();
            $started = hrtime(true);
            $e = $this->failure(static fn () => $c->listRooms());
            $tookMs = (hrtime(true) - $started) / 1e6;
            $peakMb = (memory_get_peak_usage() - $before) / $megabyte;
            self::assertStringNotContainsString(self::LIVE_KEY, $e->getMessage(), $what);
            self::assertLessThan(2000, $tookMs, "$what: a 1 MB reply took {$tookMs} ms");
            self::assertLessThan(24, $peakMb, "$what: a 1 MB reply took {$peakMb} MB over the reply itself");
        }
    }

    #[Test]
    public function theWordRuleNeverKeepsAWordTheNodeSdkWithholds(): void
    {
        // Review 2026-10-23, finding 6: Node measures a word in UTF-16 units, this SDK measured it in characters, so
        // a word of astral characters (two units each) could be kept here and withheld there. Bytes are never fewer
        // than UTF-16 units, so measuring in bytes keeps the rule closed.
        $long = 'sk_' . str_repeat('ab12', 64);                       // 259 characters: not looked for
        $emoji = str_repeat("\u{1F389}", 130);                         // 130 characters, 260 UTF-16 units, 520 bytes
        self::assertSame('bad key ' . self::NOT_CHECKED, $this->shown($long, "bad key $emoji"));
        $tabbed = 'sk_' . str_repeat('ab12', 32) . "\t" . str_repeat('ab12', 32);   // 260 characters, holds a tab
        self::assertSame(self::NOT_CHECKED, $this->shown($tabbed, $emoji), 'the whole-message rule measures the same way');
        self::assertSame('room not found', $this->shown($tabbed, 'room not found'), 'a message shorter than the key is still kept');
    }
}
