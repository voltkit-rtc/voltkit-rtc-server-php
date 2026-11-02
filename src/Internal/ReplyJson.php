<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Internal;

/**
 * json_decode() for text the API sent: a reply body, or a webhook body that verified (PHP012-1, 0.1.3).
 *
 * Two things a chat viewer can put in a message, or a participant in its metadata, made such text "not JSON" to PHP
 * alone, where Node and Go read it: a lone UTF-16 surrogate escape (`"\ud83d"`, which PHP refuses with
 * JSON_ERROR_UTF16 and JavaScript and Go read as U+FFFD) and nesting past json_decode()'s default depth of 512 (the
 * controller keeps metadata up to 4 KB, which holds up to about 2,000 levels). This reads with a depth of MAX_DEPTH,
 * and rewrites each lone surrogate escape to `�` only after PHP has refused the text for exactly that reason:
 * valid text is never changed and costs nothing more, and a key with a lone surrogate becomes a key ending in U+FFFD,
 * never another key. PHP's parser has a limit of its own, whatever depth is asked, at about 5,000 levels of `[` and
 * 2,500 of `{"a":`: text past it is a \JsonException ("Syntax error"), like any other text PHP cannot read.
 *
 * @internal
 */
final class ReplyJson
{
    /** The nesting json_decode() is allowed. PHP's own parser gives up a little before it, cleanly. */
    public const MAX_DEPTH = 5000;

    /**
     * Every escape in the text, in order, so that an escaped backslash (`\\`) is consumed before the `u` after it
     * could look like one: a surrogate pair (kept), a lone high or low surrogate (group 1: rewritten), any other
     * `\uXXXX` and any other escape (kept). Byte-wise, so bytes that are not UTF-8 cannot make the match fail.
     */
    private const ESCAPES = '/\\\\u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}'
        . '|\\\\u([dD][89a-fA-F][0-9a-fA-F]{2})'
        . '|\\\\u[0-9a-fA-F]{4}'
        . '|\\\\./s';

    private function __construct()
    {
    }

    /**
     * The text decoded, to arrays or (`$assoc` false) to \stdClass objects and lists, as json_decode() reads it.
     *
     * @throws \JsonException when PHP cannot read the text (with its lone surrogates rewritten, if that was the reason)
     */
    public static function decode(#[\SensitiveParameter] string $text, bool $assoc): mixed
    {
        try {
            return json_decode($text, $assoc, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($e->getCode() !== JSON_ERROR_UTF16) {
                throw $e;
            }
        }
        return json_decode(self::withoutLoneSurrogates($text), $assoc, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    }

    /** The text with each lone surrogate escape as `�`; a pair, every other escape and all other text as they are. */
    public static function withoutLoneSurrogates(#[\SensitiveParameter] string $text): string
    {
        $rewritten = preg_replace_callback(
            self::ESCAPES,
            static fn (array $m): string => ($m[1] ?? null) !== null ? '\\ufffd' : $m[0],
            $text,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );
        // preg failing (it cannot on this pattern: no backtracking, no /u) leaves the text as it was, and json_decode
        // then says what is wrong with it.
        return $rewritten ?? $text;
    }
}
