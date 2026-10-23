<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Internal;

/**
 * The checks on the API key when a client is made (spec 2.2, 2.3). None of the messages quotes
 * the key or any part of it.
 *
 * @internal
 */
final class ApiKey
{
    /** Spaces, tabs and line breaks at either end of a header value: dropped before the key is sent. */
    public const WHITESPACE_AROUND = " \t\r\n";

    private function __construct()
    {
    }

    /**
     * The net key: the key as given with whitespace at both ends removed, or an \InvalidArgumentException.
     *
     * Refused: an empty or whitespace-only key (a bare line from a file: every call would fail with 401 far
     * from the mistake); a key holding a character no HTTP header can carry (a line break or a NUL inside,
     * another control character, DEL, anything above U+00FF: curl would refuse it on every call, with an
     * error quoting it); and a key holding a character no VoltKit key has (a backslash, which JSON doubles
     * and would let a reply of backslashes defeat the redaction, or a Latin-1 character, which a server
     * echoes as U+FFFD, a ?, nothing or mojibake that no redaction can list). The controller issues `sk_`
     * and hex digits; the alphabet taken is tab plus printable ASCII except the backslash.
     *
     * @throws \InvalidArgumentException
     */
    public static function net(#[\SensitiveParameter] string $apiKey): string
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $net = trim($apiKey, self::WHITESPACE_AROUND);
        if ($net === '') {
            throw new \InvalidArgumentException('apiKey is required: the one given is only whitespace');
        }
        $codePoints = self::codePoints($net);
        foreach ($codePoints as $cp) {
            if ($cp !== 0x09 && ($cp < 0x20 || $cp === 0x7F || $cp > 0xFF)) {
                throw new \InvalidArgumentException(
                    'apiKey holds a character no HTTP header can carry: a line break, a NUL, another control character,'
                    . ' or one above U+00FF. Check how the key is loaded (it is not shown here)',
                );
            }
        }
        foreach ($codePoints as $cp) {
            if ($cp === 0x5C || ($cp >= 0x80 && $cp <= 0xFF)) {
                throw new \InvalidArgumentException(
                    'apiKey holds a character no VoltKit key has: a backslash, or one outside ASCII such as an accented'
                    . ' letter. Check how the key is loaded (it is not shown here)',
                );
            }
        }
        return $net;
    }

    /**
     * The key's code points: decoded as UTF-8 when it is valid UTF-8, else each byte as a Latin-1 character,
     * which is what a key read from a Latin-1 file is.
     *
     * @return list<int>
     */
    private static function codePoints(string $text): array
    {
        $out = [];
        if (preg_match('//u', $text) === 1) {
            foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
                $out[] = mb_ord($ch, 'UTF-8');
            }
            return $out;
        }
        foreach (str_split($text) as $byte) {
            $out[] = ord($byte);
        }
        return $out;
    }
}
