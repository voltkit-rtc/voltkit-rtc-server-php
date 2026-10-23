<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

/**
 * The shared vectors, docs/server-sdk-vectors.json, generated from the Node SDK
 * (scripts/test/server-sdk-vectors.mjs). Read from the repository: the
 * published package does not ship them (spec 13.12).
 */
final class Vectors
{
    public const PATH = __DIR__ . '/../../../docs/server-sdk-vectors.json';

    /** @var array<string, mixed>|null */
    private static ?array $all = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$all === null) {
            $text = file_get_contents(self::PATH);
            if ($text === false) {
                throw new \RuntimeException('cannot read ' . self::PATH);
            }
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            assert(is_array($decoded));
            /** @var array<string, mixed> $decoded */
            self::$all = $decoded;
        }
        return self::$all;
    }

    /** @return array<string, mixed> */
    public static function section(string $name): array
    {
        $section = self::all()[$name] ?? null;
        if (!is_array($section)) {
            throw new \RuntimeException("no section $name in the vectors");
        }
        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * The cases of a section, as a data provider: keyed by name where the cases have one.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(string $section, string $key = 'cases'): array
    {
        $cases = self::section($section)[$key] ?? null;
        if (!is_array($cases)) {
            throw new \RuntimeException("no $key in section $section of the vectors");
        }
        $out = [];
        foreach ($cases as $i => $case) {
            assert(is_array($case));
            /** @var array<string, mixed> $case */
            $name = $case['name'] ?? $case['method'] ?? $case['call'] ?? $case['code'] ?? null;
            $label = is_string($name) ? "$i: $name" : (string) $i;
            $out[$label] = [$case];
        }
        return $out;
    }
}
