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

    private static ?\stdClass $faithful = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$all === null) {
            $decoded = json_decode(self::text(), true, 512, JSON_THROW_ON_ERROR);
            assert(is_array($decoded));
            /** @var array<string, mixed> $decoded */
            self::$all = $decoded;
        }
        return self::$all;
    }

    /**
     * A section with JSON objects kept as objects. all() and section() decode to arrays, where {} and [] are the same
     * empty array and {"0":1} reads as a list, so a reply a fake transport sends is built from this one (0.1.2: the
     * client tells an object from a list).
     */
    public static function faithful(string $name): \stdClass
    {
        if (self::$faithful === null) {
            $decoded = json_decode(self::text(), false, 512, JSON_THROW_ON_ERROR);
            assert($decoded instanceof \stdClass);
            self::$faithful = $decoded;
        }
        $section = self::$faithful->$name ?? null;
        if (!$section instanceof \stdClass) {
            throw new \RuntimeException("no section $name in the vectors");
        }
        return $section;
    }

    /**
     * Case `$i` of a section's list `$key`, objects kept as objects (see faithful()).
     */
    public static function faithfulCase(string $section, string $key, int|string $i): \stdClass
    {
        $cases = self::faithful($section)->$key ?? null;
        $case = is_array($cases) ? ($cases[$i] ?? null) : null;
        if (!$case instanceof \stdClass) {
            throw new \RuntimeException("no case $i in $section.$key of the vectors");
        }
        return $case;
    }

    private static function text(): string
    {
        $text = file_get_contents(self::PATH);
        if ($text === false) {
            throw new \RuntimeException('cannot read ' . self::PATH);
        }
        return $text;
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
