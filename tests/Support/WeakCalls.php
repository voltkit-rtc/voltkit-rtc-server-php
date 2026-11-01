<?php

declare(strict_types=0);

namespace VoltKit\Rtc\Tests\Support;

use VoltKit\Rtc\StreamClient;

/**
 * Calls made from a file without strict types, as many applications' files are. There PHP converts a scalar argument
 * to the declared type before the SDK runs: 42 becomes "42", 1.5 "1.5", true "1" and false "". It still refuses null,
 * an array and an object with a TypeError. (phpcs.xml.dist lets this one file go without strict_types=1.)
 */
final class WeakCalls
{
    /** @param array<string, mixed> $args named arguments */
    public static function direct(StreamClient $c, string $method, array $args): mixed
    {
        return $c->$method(...$args);
    }
}
