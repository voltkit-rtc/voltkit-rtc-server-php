<?php

declare(strict_types=1);

namespace VoltKit\Rtc;

/**
 * Webhook signature verification (spec 7). VoltKit signs every delivery with
 * `X-Stream-Signature: t=<unix seconds>,v1=<hex>[,v1=<hex>]`, the signature being HMAC-SHA256 over
 * `<t>.<raw body>` with the whole signing secret (`whsec_...`) as the key.
 *
 * Pass the RAW request body as received: `file_get_contents('php://input')` in plain PHP,
 * `$request->getContent()` in Laravel or Symfony. A body that has been decoded and encoded again
 * can never verify. The header comes from `$_SERVER['HTTP_X_STREAM_SIGNATURE']` or a PSR-7
 * `getHeaderLine('X-Stream-Signature')`.
 *
 * Mistakes in the setup are loud: no secret, a tolerance that is not a number of 0 or more, or
 * a body that is not a string fail with \InvalidArgumentException, \RangeException or PHP's own
 * \TypeError, never with a quiet false that would look like an attack.
 */
final class Webhooks
{
    public const SIGNATURE_HEADER = 'X-Stream-Signature';
    /** How far out of date a timestamp may be, in seconds, when no toleranceSeconds is given. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;
    /** A header longer than this is invalid. */
    public const MAX_HEADER_LENGTH = 4096;

    private function __construct()
    {
    }

    /**
     * Whether `$rawBody` was signed by VoltKit with one of `$secrets`, within the freshness window.
     *
     * @param string $rawBody the request body, byte for byte as received
     * @param string|list<string>|null $header the X-Stream-Signature value; a list (as some frameworks hand headers over) is joined with commas; null when the header is missing
     * @param string|list<string> $secrets the signing secret, or the current and the previous one during a rotation; every secret is tried against every signature, in constant time, with no early return
     * @param array{toleranceSeconds?: int|float|null, now?: int|float|\Closure|null} $options toleranceSeconds: a number of 0 or more (default 300; 0 is allowed and strict; INF lifts the check); now: the clock, as unix seconds or a closure returning them, for tests
     * @throws \InvalidArgumentException when no secret is given, or an option is not what it must be
     * @throws \RangeException when toleranceSeconds is negative or NaN
     */
    public static function verify(
        string $rawBody,
        string|array|null $header,
        #[\SensitiveParameter] string|array $secrets,
        array $options = [],
    ): bool {
        $list = self::secretList($secrets);
        $tolerance = self::tolerance($options);
        $now = self::now($options);
        $parsed = self::parseHeader($header);
        if ($parsed === null) {
            return false;
        }
        // Both directions, inclusive at the boundary: a timestamp 300 s old or 300 s ahead passes with the default.
        if (abs($now - (float) $parsed['ts']) > $tolerance) {
            return false;
        }
        // Every secret against every signature, each compared in constant time. Both lists are short (two at most
        // in practice), and nothing returns early on a partial match.
        $ok = false;
        foreach ($list as $secret) {
            $expected = hash_hmac('sha256', $parsed['ts'] . '.' . $rawBody, $secret);
            foreach ($parsed['signatures'] as $signature) {
                if (hash_equals($expected, $signature)) {
                    $ok = true;
                }
            }
        }
        return $ok;
    }

    /**
     * Verify and decode in one step: the event as an array, or a StreamError with code `bad-signature`
     * (status 401) when the delivery does not verify, so a handler cannot forget to check.
     *
     * @param string $rawBody the request body, byte for byte as received
     * @param string|list<string>|null $header the X-Stream-Signature value
     * @param string|list<string> $secrets the signing secret, or several during a rotation
     * @param array{toleranceSeconds?: int|float|null, now?: int|float|\Closure|null} $options as for verify()
     * @return array{id: string, type: string, tenantId: string, roomId?: string, createdAt: int, data?: array<string, mixed>, ...}
     * @throws StreamError bad-signature, 401, when the signature or the timestamp does not verify
     * @throws \JsonException when the verified body is not JSON (never from VoltKit)
     * @throws \UnexpectedValueException when the verified body is JSON but not an object
     */
    public static function parse(
        string $rawBody,
        string|array|null $header,
        #[\SensitiveParameter] string|array $secrets,
        array $options = [],
    ): array {
        if (!self::verify($rawBody, $header, $secrets, $options)) {
            throw new StreamError('bad-signature', 401, 'webhook signature did not verify', false);
        }
        $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event) || ($event !== [] && array_is_list($event))) {
            throw new \UnexpectedValueException('the webhook body verified but is not a JSON object');
        }
        /** @var array{id: string, type: string, tenantId: string, roomId?: string, createdAt: int, data?: array<string, mixed>, ...} $event */
        return $event;
    }

    /**
     * Split `t=<ts>,v1=<hex>[,v1=<hex>...]` into its timestamp and signatures, or null when malformed.
     *
     * At most 4096 characters; split on commas; each part trimmed; each must be key=value with a non-empty key;
     * `t` must be 1 to 15 digits and appear exactly once; each `v1` exactly 64 lower-case hex characters;
     * parts with other keys (v0=..., a future scheme) are skipped; anything else makes the header invalid.
     *
     * @param string|list<string>|null $header
     * @return ?array{ts: string, signatures: list<string>}
     */
    private static function parseHeader(string|array|null $header): ?array
    {
        $value = is_array($header) ? implode(',', $header) : ($header ?? '');
        if (strlen($value) > self::MAX_HEADER_LENGTH) {
            return null;
        }
        $ts = null;
        $signatures = [];
        foreach (explode(',', $value) as $part) {
            // The whitespace JavaScript's trim() takes off an ASCII string: PHP's default set would also take a NUL
            // and leave a form feed (review 2026-10-23).
            $item = trim($part, " \t\n\r\x0B\f");
            $eq = strpos($item, '=');
            if ($eq === false || $eq === 0) {
                return null;
            }
            $key = substr($item, 0, $eq);
            $val = substr($item, $eq + 1);
            if ($key === 't') {
                if ($ts !== null || preg_match('/^[0-9]{1,15}\z/', $val) !== 1) {
                    return null;
                }
                $ts = $val;
            } elseif ($key === 'v1') {
                if (preg_match('/^[0-9a-f]{64}\z/', $val) !== 1) {
                    return null;
                }
                $signatures[] = $val;
            }
        }
        if ($ts === null || $signatures === []) {
            return null;
        }
        return ['ts' => $ts, 'signatures' => $signatures];
    }

    /**
     * @param string|array<int|string, mixed> $secrets
     * @return list<string>
     */
    private static function secretList(#[\SensitiveParameter] string|array $secrets): array
    {
        $list = [];
        foreach (is_string($secrets) ? [$secrets] : $secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $list[] = $secret;
            }
        }
        if ($list === []) {
            throw new \InvalidArgumentException(
                'Webhooks::verify needs the signing secret: the whsec_ value from the dashboard, or a list of the'
                . ' current and the previous one during a rotation',
            );
        }
        return $list;
    }

    /** @param array<string, mixed> $options */
    private static function tolerance(array $options): float
    {
        foreach (array_keys($options) as $name) {
            if ($name !== 'toleranceSeconds' && $name !== 'now') {
                throw new \InvalidArgumentException(
                    "unknown option \"$name\": the options are toleranceSeconds and now",
                );
            }
        }
        $tolerance = $options['toleranceSeconds'] ?? null;
        if ($tolerance === null) {
            return (float) self::DEFAULT_TOLERANCE_SECONDS;
        }
        if (!is_int($tolerance) && !is_float($tolerance)) {
            // A string is not coerced: "" from an unset variable would be a window of nothing (Appendix A.7).
            throw new \InvalidArgumentException(
                'toleranceSeconds must be a number of 0 or more, got ' . self::describe($tolerance),
            );
        }
        if (is_nan((float) $tolerance) || $tolerance < 0) {
            // NaN once switched the check off: every comparison with it is false (NODE-041-3).
            throw new \RangeException(
                'toleranceSeconds must be a number of 0 or more, got ' . self::describe($tolerance),
            );
        }
        return (float) $tolerance;
    }

    /** @param array<string, mixed> $options */
    private static function now(array $options): float
    {
        $now = $options['now'] ?? null;
        if ($now === null) {
            return microtime(true);
        }
        if (is_int($now) || is_float($now)) {
            return (float) $now;
        }
        // A closure, not a function name: is_callable() is true for any function's name, so ['now' => 'system']
        // once called system() (review 2026-10-23). An invokable object is passed as $clock(...).
        if ($now instanceof \Closure) {
            $value = $now();
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
        }
        throw new \InvalidArgumentException(
            'now must be a unix time in seconds (int or float) or a closure returning one',
        );
    }

    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Infinity' : '-Infinity';
            }
        }
        if (is_scalar($value)) {
            return var_export($value, true);
        }
        return get_debug_type($value);
    }
}
