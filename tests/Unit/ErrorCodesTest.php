<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\ErrorCodes;
use VoltKit\Rtc\ErrorInfo;

/** Spec 5.3: the registries are a copy of docs/sdk-contract.json, in its order, with its flags. */
final class ErrorCodesTest extends TestCase
{
    /** @return list<array{code: string, origin: string, surfaces: list<string>, http: ?int, terminal: bool, retryable: bool}> */
    private static function contract(): array
    {
        $text = file_get_contents(__DIR__ . '/../../../docs/sdk-contract.json');
        self::assertIsString($text);
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['errors']);
        /** @var list<array{code: string, origin: string, surfaces: list<string>, http: ?int, terminal: bool, retryable: bool}> */
        return $decoded['errors'];
    }

    #[Test]
    public function allIsEveryCodeInTheContractsOrder(): void
    {
        self::assertSame(array_map(static fn ($e) => $e['code'], self::contract()), ErrorCodes::ALL);
        self::assertCount(105, ErrorCodes::ALL);
    }

    #[Test]
    public function restIsTheCodesWhoseSurfacesIncludeRestInOrder(): void
    {
        $rest = array_values(array_filter(self::contract(), static fn ($e) => in_array('rest', $e['surfaces'], true)));
        self::assertSame(array_map(static fn ($e) => $e['code'], $rest), ErrorCodes::REST);
        self::assertCount(29, ErrorCodes::REST);
        foreach (ErrorCodes::REST as $code) {
            self::assertContains($code, ErrorCodes::ALL);
        }
    }

    #[Test]
    public function infoCarriesTheContractsFlags(): void
    {
        foreach (self::contract() as $entry) {
            $info = ErrorCodes::info($entry['code']);
            self::assertInstanceOf(ErrorInfo::class, $info, $entry['code']);
            self::assertSame($entry['origin'], $info->origin, $entry['code']);
            self::assertSame($entry['http'], $info->http, $entry['code']);
            self::assertSame($entry['terminal'], $info->terminal, $entry['code']);
            self::assertSame($entry['retryable'], $info->retryable, $entry['code']);
            self::assertSame(in_array('rest', $entry['surfaces'], true), $info->rest, $entry['code']);
            self::assertSame($entry['code'], $info->code);
        }
    }

    #[Test]
    public function anUnknownCodeHasNoInfo(): void
    {
        self::assertNull(ErrorCodes::info('http-502'));
        self::assertNull(ErrorCodes::info('RATE_LIMITED'));
        self::assertNull(ErrorCodes::info(''));
        self::assertTrue(ErrorCodes::isKnown('no-room'));
        self::assertFalse(ErrorCodes::isKnown('panic'));
    }

    #[Test]
    public function noCodeIsListedTwice(): void
    {
        self::assertSame(count(ErrorCodes::ALL), count(array_unique(ErrorCodes::ALL)));
        self::assertSame(count(ErrorCodes::REST), count(array_unique(ErrorCodes::REST)));
    }
}
