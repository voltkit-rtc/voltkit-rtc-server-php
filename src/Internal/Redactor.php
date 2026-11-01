<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Internal;

/**
 * Takes the API key, in any encoding someone else's words can hold, and the credentials of
 * any URL out of a text before it reaches an error message (spec 5.5).
 *
 * The Node SDK compiles one regular expression that matches the key character by character
 * in every form at once. PHP's PCRE2 is built with a 2-byte link size and refuses a pattern
 * past about 30 KB, which such a pattern reaches for an ordinary key, so this class is that
 * pattern as a small matcher instead: each character of the key has a list of the
 * forms one escaper gives it (itself, %XX in either hex case, \uXXXX, &#NN; and &#xHH; with
 * any number of leading zeros, its HTML5 names, + for a space, \" and \/ ...), and each of
 * those run once more through every standard escaper (%252F, \\\/, \&quot;, &amp;quot;,
 * &amp;#x005F; ...). A match is the whole key in order,
 * one form per character, found by a depth-first search in the same order the Node pattern
 * tries its alternatives, with failed (position, offset) pairs remembered so the time stays
 * linear in the text. A form never holds whitespace when the key holds none, and no form is
 * shorter than the character it stands for, so every form of the key is at least as long as
 * the key: that is what the fail-closed word rule below relies on.
 *
 * Fail closed (N046-1, N047-1): a key longer than LONGEST_KEY_LOOKED_FOR is not looked for;
 * instead every word (a run of non-whitespace) at least as long as the key is replaced by
 * KEY_NOT_CHECKED and shorter words, such as error codes, are kept. If such a key holds
 * whitespace a form could be split across words, so the whole text goes, unless it is
 * shorter than the key.
 *
 * The key itself, and the match tables that spell it out, live in closures: var_export()
 * prints a closure as an empty object, so a client dumped that way shows nothing of either.
 *
 * @internal
 */
final class Redactor
{
    public const MARKER = '[redacted]';
    public const LONGEST_KEY_LOOKED_FOR = 256;
    public const KEY_NOT_CHECKED = '[not shown: it could not be checked for the API key]';
    /**
     * Failed (character, offset) pairs the matcher remembers for one text, at most: an optimisation that keeps the
     * time linear, cleared when it grows past this so a reply built to defeat the matcher (the key's first
     * character a million times) costs a few megabytes rather than hundreds (review 2026-10-23).
     */
    private const MEMO_LIMIT = 65536;

    /**
     * A URL's credentials: `scheme://` and the user info after it. A password may hold an @, a /, a ? or
     * a # (N049-3, D7), so the user info is either anything with no whitespace, /, ? or # up to the last @
     * before those; or `name:` and then something that is not a port (digits, then a /, ?, #, whitespace or
     * the end), then anything with no whitespace, up to the last @. https://host/a@b and
     * https://host:8080/a@b hold none; https://user:123/x@h cannot be told from a port and a path, and is
     * shown. A scheme starts at the first letter of a run of scheme characters: the run is anchored at a
     * character that is not one of them, and its leading digits, + . - are kept (group 1) before the
     * scheme (group 2). Run per word, up to the word's last @ (redactUrlCredentials()).
     */
    private const URL_CREDENTIALS = '/(?<![a-z0-9+.\-])([0-9+.\-]*)([a-z][a-z0-9+.\-]*:\/\/)'
        . '(?:[^\s\/?#]*@|[^\s\/?#:]*:(?!\d*(?:[\/?#\s]|$))\S*@)/i';

    /** The characters one-layer escapes are made of, other than letters and digits: all a second escaper changes. */
    private const ESCAPE_CHARACTERS = '%\\"&#;/+';

    /** The HTML5 named references of the characters a key may hold, and of the backslash (which escapes hold). */
    private const HTML_NAMES = [
        "\t" => ['Tab'], '!' => ['excl'], '"' => ['quot', 'QUOT'], '#' => ['num'], '$' => ['dollar'],
        '%' => ['percnt'], '&' => ['amp', 'AMP'], "'" => ['apos'], '(' => ['lpar'], ')' => ['rpar'],
        '*' => ['ast', 'midast'], '+' => ['plus'], ',' => ['comma'], '.' => ['period'], '/' => ['sol'],
        ':' => ['colon'], ';' => ['semi'], '<' => ['lt', 'LT'], '=' => ['equals'], '>' => ['gt', 'GT'],
        '?' => ['quest'], '@' => ['commat'], '[' => ['lsqb', 'lbrack'], '\\' => ['bsol'], ']' => ['rsqb', 'rbrack'],
        '^' => ['Hat'], '_' => ['lowbar', 'UnderBar'], '`' => ['grave', 'DiacriticalGrave'], '{' => ['lcub', 'lbrace'],
        '|' => ['verbar', 'vert', 'VerticalLine'], '}' => ['rcub', 'rbrace'],
    ];

    /** @var \Closure(): string the net key */
    private readonly \Closure $key;
    private readonly int $keyLength;
    private readonly bool $lookedFor;
    private readonly bool $keyHasWhitespace;
    /** @var ?\Closure(string): string the matcher, built for the first text: most clients never see an error */
    private ?\Closure $replace = null;

    /** @param string $netKey the key as sent: whitespace at both ends already removed, checked by ApiKey::net() */
    public function __construct(#[\SensitiveParameter] string $netKey)
    {
        $this->key = static fn (): string => $netKey;
        $this->keyLength = strlen($netKey);
        $this->lookedFor = $this->keyLength <= self::LONGEST_KEY_LOOKED_FOR;
        $this->keyHasWhitespace = preg_match('/\s/', $netKey) === 1;
    }

    /** `$text` with the key in any form, then any URL's credentials, replaced by [redacted]. */
    public function redact(#[\SensitiveParameter] string $text): string
    {
        return self::redactUrlCredentials($this->redactKey($text));
    }

    /**
     * `$text` with every form of the key replaced by [redacted]; the word rule when the key is too long to look for.
     * Lengths are compared in bytes: Node compares UTF-16 units, and a UTF-8 byte count is never smaller than
     * that, so this SDK withholds every word Node withholds (a word of astral characters, measured in characters,
     * was kept here and withheld there; review 2026-10-23).
     */
    public function redactKey(#[\SensitiveParameter] string $text): string
    {
        if ($this->lookedFor) {
            $this->replace ??= $this->matcher();
            return ($this->replace)($text);
        }
        if ($this->keyHasWhitespace) {
            return strlen($text) < $this->keyLength ? $text : self::KEY_NOT_CHECKED;
        }
        $keyLength = $this->keyLength;
        return preg_replace_callback(
            '/\S+/',
            static fn (array $m): string => strlen($m[0]) < $keyLength ? $m[0] : self::KEY_NOT_CHECKED,
            $text,
        ) ?? self::KEY_NOT_CHECKED;
    }

    /**
     * `scheme://user:pass@host` becomes `scheme://[redacted]@host`; mailto:me@host and /a@b in a path are untouched.
     * A match lies inside one word (a run of non-whitespace) and ends at an @, so the pattern runs over each word only
     * up to its last @, and a word with no @ is passed over: run over a whole page, `\S*@` scans to the end of a word
     * and back from every `name:` after a `://`, which on a long word takes minutes (spec 5.5).
     */
    public static function redactUrlCredentials(#[\SensitiveParameter] string $text): string
    {
        // Each word that holds an @, whole: tried only where a word starts (the lookbehind), with possessive runs that
        // never backtrack, so a long word costs one scan, @ or not. A failure of the engine (a backtrack limit on a
        // huge text) must not let credentials through.
        return preg_replace_callback(
            '/(?<!\S)[^\s@]*+@\S*+/',
            static function (#[\SensitiveParameter] array $m): string {
                $word = $m[0];
                $end = (int) strrpos($word, '@') + 1;
                $head = preg_replace(self::URL_CREDENTIALS, '$1$2' . self::MARKER . '@', substr($word, 0, $end));
                return $head === null ? self::KEY_NOT_CHECKED : $head . substr($word, $end);
            },
            $text,
        ) ?? self::KEY_NOT_CHECKED;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['key' => self::MARKER];
    }

    // --- the matcher ------------------------------------------------------------------------------------------------

    /**
     * The replace function for this key: its tables are captured by the closure, not held in properties. Each
     * character's alternatives are grouped by their first byte, so at a given offset only the alternatives that
     * can start there are tried: on a text built to defeat the matcher (the key's first character a million
     * times) that is one lookup per byte rather than every form of the second character (review 2026-10-23).
     *
     * @return \Closure(string): string
     */
    private function matcher(): \Closure
    {
        $key = ($this->key)();
        $keyLength = $this->keyLength;
        /** @var array<string, array<string, list<array{0: string, 1: ?string, 2: int}>>> $byChar */
        $byChar = [];
        /** @var list<array<string, list<array{0: string, 1: ?string, 2: int}>>> $alternatives for each character of the key, its alternatives by first byte, in order */
        $alternatives = [];
        $escapers = self::escapers();
        foreach (str_split($key) as $ch) {
            $byChar[$ch] ??= self::byFirstByte(self::alternativesOf($ch, $escapers));
            $alternatives[] = $byChar[$ch];
        }
        $first = $alternatives[0];
        return static function (#[\SensitiveParameter] string $text) use ($alternatives, $first, $keyLength): string {
            $len = strlen($text);
            $out = '';
            $last = 0;
            $pos = 0;
            /** @var array<int, true> $failed */
            $failed = [];
            while ($pos < $len) {
                if (isset($first[$text[$pos]])) {
                    $end = self::matchFrom($text, $len, $alternatives, $keyLength, 0, $pos, $failed);
                    if ($end > $pos) {
                        $out .= substr($text, $last, $pos - $last) . self::MARKER;
                        $pos = $end;
                        $last = $end;
                        continue;
                    }
                    if (count($failed) > self::MEMO_LIMIT) {
                        $failed = [];
                    }
                }
                $pos++;
            }
            return $last === 0 ? $text : $out . substr($text, $last);
        };
    }

    /**
     * `$list` grouped by the first byte of each alternative, the order within a group kept. A first byte is
     * exact (the character itself, %, &, \, +), never a hex digit; were one ever either-case, it is filed under
     * both cases.
     *
     * @param list<array{0: string, 1: ?string, 2: int}> $list
     * @return array<string, list<array{0: string, 1: ?string, 2: int}>>
     */
    private static function byFirstByte(array $list): array
    {
        $grouped = [];
        foreach ($list as $alternative) {
            $byte = $alternative[0][0];
            $grouped[$byte][] = $alternative;
            if ($alternative[1] !== null && $alternative[1][0] === '1' && strtoupper($byte) !== $byte) {
                $grouped[strtoupper($byte)][] = $alternative;
            }
        }
        return $grouped;
    }

    /**
     * The end offset of a match of the key from character `$i` at `$pos`, or -1. Depth-first in the order the
     * alternatives are listed, remembering every (i, pos) that failed so each is tried once per text.
     *
     * @param list<array<string, list<array{0: string, 1: ?string, 2: int}>>> $alternatives
     * @param array<int, true> $failed
     */
    private static function matchFrom(
        #[\SensitiveParameter] string $text,
        int $len,
        array $alternatives,
        int $keyLength,
        int $i,
        int $pos,
        array &$failed,
    ): int {
        if ($i === $keyLength) {
            return $pos;
        }
        if ($pos >= $len) {
            return -1;
        }
        $memo = $i * ($len + 1) + $pos;
        if (isset($failed[$memo])) {
            return -1;
        }
        foreach ($alternatives[$i][$text[$pos]] ?? [] as [$exact, $mask, $flen]) {
            if ($pos + $flen > $len) {
                continue;
            }
            if ($mask === null) {
                if (substr_compare($text, $exact, $pos, $flen) !== 0) {
                    continue;
                }
                $next = $pos + $flen;
            } else {
                $next = self::matchesWithMask($text, $len, $pos, $exact, $mask);
                if ($next < 0) {
                    continue;
                }
            }
            $end = self::matchFrom($text, $len, $alternatives, $keyLength, $i + 1, $next, $failed);
            if ($end >= 0) {
                return $end;
            }
        }
        $failed[$memo] = true;
        return -1;
    }

    /**
     * Where `$exact` ends when it is at `$pos`, or -1. `$mask` says, per character: 0 exact; 1 either case (a hex digit,
     * the x of &#x), `$exact` holding it lower-cased; 2 a run of zeros, none or any number (N049-5: a reference's
     * leading zeros), taken whole. A run of zeros is always followed by a digit that is not one (the code of a
     * character a key holds), so taking it whole is the only way it can match.
     */
    private static function matchesWithMask(
        #[\SensitiveParameter] string $text,
        int $len,
        int $pos,
        string $exact,
        string $mask,
    ): int {
        $p = $pos;
        $n = strlen($exact);
        for ($j = 0; $j < $n; $j++) {
            if ($mask[$j] === '2') {
                $p += strspn($text, '0', $p);
                continue;
            }
            if ($p >= $len) {
                return -1;
            }
            $c = $text[$p];
            if ($mask[$j] === '1' ? strtolower($c) !== $exact[$j] : $c !== $exact[$j]) {
                return -1;
            }
            $p++;
        }
        return $p;
    }

    // --- the forms ----------------------------------------------------------------------------------------------------
    //
    // A piece is one character of a form: [the character, how it matches]. EXACT is the character itself; EITHER_CASE
    // the hex digits of %XX, \uXXXX and &#xHH;, and the x of &#x; ZEROS any number of zeros, none included, the
    // leading zeros of a numeric HTML reference (&#x005F;, &#0095;: N049-5). No escaper changes a zero, so the zeros of
    // a reference escaped once more (&amp;#x005F;, %26%23x005F%3B) are matched the same way.

    private const EXACT = 0;
    private const EITHER_CASE = 1;
    private const ZEROS = 2;

    /** @return list<array{0: string, 1: int}> */
    private static function pieces(string $text): array
    {
        $out = [];
        foreach (str_split($text) as $ch) {
            $out[] = [$ch, self::EXACT];
        }
        return $out;
    }

    /** @return list<array{0: string, 1: int}> */
    private static function hexPieces(int $code, int $width): array
    {
        $out = [];
        foreach (str_split(str_pad(strtoupper(dechex($code)), $width, '0', STR_PAD_LEFT)) as $digit) {
            $out[] = [$digit, ctype_alpha($digit) ? self::EITHER_CASE : self::EXACT];
        }
        return $out;
    }

    /** @return list<array{0: string, 1: int}> */
    private static function percentOf(int $code): array
    {
        return [...self::pieces('%'), ...self::hexPieces($code, 2)];
    }

    /**
     * &#x, any zeros, the hex digits in either case, and ;
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function hexReferenceOf(int $code): array
    {
        return [
            ...self::pieces('&#'),
            ['x', self::EITHER_CASE],
            ['0', self::ZEROS],
            ...self::hexPieces($code, 1),
            ...self::pieces(';'),
        ];
    }

    /**
     * &#, any zeros, the decimal digits, and ;
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function decimalReferenceOf(int $code): array
    {
        return [...self::pieces('&#'), ['0', self::ZEROS], ...self::pieces("$code;")];
    }

    /**
     * Each of `$chars` mapped to what `$encode` makes of it.
     *
     * @param callable(string): list<array{0: string, 1: int}> $encode
     * @return array<string, list<array{0: string, 1: int}>>
     */
    private static function escaping(string $chars, callable $encode): array
    {
        $out = [];
        foreach (str_split($chars) as $ch) {
            $out[$ch] = $encode($ch);
        }
        return $out;
    }

    /**
     * The first HTML5 name of an escape character, as &name;
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function namedReferenceOf(string $ch): array
    {
        $names = self::HTML_NAMES[$ch] ?? [];
        return self::pieces('&' . ($names[0] ?? $ch) . ';');
    }

    /**
     * The standard escapers, each as what it makes of the escape characters: a second layer is one run over a first.
     * URL: encodeURIComponent, URLSearchParams and Python's quote(safe='') encode each; Python's quote() and encodeURI
     * keep /. JSON: JSON.stringify and json.dumps write \ as \\ and " as \"; Go's json.Marshal writes & as & too,
     * PHP's json_encode writes / as \/. HTML: html.escape, htmlspecialchars and lodash write &quot;, Go &#34;; PHP's
     * htmlentities with ENT_HTML5 names each character, OWASP's encoders write &#x..;, others &#..;.
     *
     * @return list<array<string, list<array{0: string, 1: int}>>>
     */
    private static function escapers(): array
    {
        $percent = static fn (string $ch): array => self::percentOf(ord($ch));
        return [
            self::escaping(self::ESCAPE_CHARACTERS, $percent),
            self::escaping('%\\"&#;+', $percent),
            ['\\' => self::pieces('\\\\'), '"' => self::pieces('\\"'), '&' => self::pieces('\\u0026')],
            ['\\' => self::pieces('\\\\'), '"' => self::pieces('\\"'), '/' => self::pieces('\\/')],
            ['&' => self::pieces('&amp;'), '"' => self::pieces('&quot;')],
            ['&' => self::pieces('&amp;'), '"' => self::pieces('&#34;')],
            self::escaping(self::ESCAPE_CHARACTERS, self::namedReferenceOf(...)),
            self::escaping(self::ESCAPE_CHARACTERS, static fn (string $ch): array => self::hexReferenceOf(ord($ch))),
            self::escaping(
                self::ESCAPE_CHARACTERS,
                static fn (string $ch): array => self::decimalReferenceOf(ord($ch)),
            ),
        ];
    }

    /**
     * Every form one escaper gives `$ch`: itself; its byte as %XX in either hex case; \uXXXX; &#NN; with any number of
     * leading zeros (PHP's &#039;, &#0095;); &#xHH; with x and digits in either case and any number of leading zeros;
     * each of its HTML5 names; + for a space; its JSON escape (\" and \t); and PHP's \/ for a slash.
     *
     * @return list<list<array{0: string, 1: int}>>
     */
    private static function oneLayerFormsOf(string $ch): array
    {
        $code = ord($ch);
        $forms = [
            self::pieces($ch),
            self::percentOf($code),
            [...self::pieces('\\u'), ...self::hexPieces($code, 4)],
            self::decimalReferenceOf($code),
            self::hexReferenceOf($code),
        ];
        foreach (self::HTML_NAMES[$ch] ?? [] as $name) {
            $forms[] = self::pieces("&$name;");
        }
        if ($ch === ' ') {
            $forms[] = self::pieces('+');
        }
        $json = json_encode($ch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escaped = substr($json, 1, -1);
        if ($escaped !== $ch) {
            $forms[] = self::pieces($escaped);
        }
        if ($ch === '/') {
            $forms[] = self::pieces('\\/');
        }
        return $forms;
    }

    /**
     * Every alternative for one character of the key: each one-layer form, as it is and run through each escaper,
     * compiled to [the form with either-case pieces lower-cased, the mask (matchesWithMask()) or null when every piece
     * is exact, the least length it matches], in the order the Node pattern lists them, without duplicates.
     *
     * @param list<array<string, list<array{0: string, 1: int}>>> $escapers
     * @return list<array{0: string, 1: ?string, 2: int}>
     */
    private static function alternativesOf(string $ch, array $escapers): array
    {
        $seen = [];
        $list = [];
        foreach (self::oneLayerFormsOf($ch) as $form) {
            foreach ([[], ...$escapers] as $escaper) {
                $pieces = [];
                foreach ($form as $piece) {
                    if ($piece[1] === self::EXACT && isset($escaper[$piece[0]])) {
                        foreach ($escaper[$piece[0]] as $replacement) {
                            $pieces[] = $replacement;
                        }
                    } else {
                        $pieces[] = $piece;
                    }
                }
                $exact = '';
                $mask = '';
                $least = 0;
                foreach ($pieces as [$c, $how]) {
                    $exact .= $how === self::EITHER_CASE ? strtolower($c) : $c;
                    $mask .= (string) $how;
                    $least += $how === self::ZEROS ? 0 : 1;
                }
                $id = $exact . '|' . $mask;
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $list[] = [$exact, $least === strlen($mask) && !str_contains($mask, '1') ? null : $mask, $least];
                }
            }
        }
        return $list;
    }
}
