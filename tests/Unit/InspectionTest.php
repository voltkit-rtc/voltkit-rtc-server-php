<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\Request;

/**
 * Review 2026-10-23, finding 2 (spec 2.4): a dumper that walks an object's real properties instead of asking
 * __debugInfo(), as Symfony's VarDumper behind Laravel's dd() and dump() does, printed the key out of the closure
 * that held it (`$apiKey: "sk_..."`) and the URL passwords. The walk below is a superset of what such dumpers
 * read: every property of every object, cast-array style, and a closure's bound variables and $this.
 */
final class InspectionTest extends TestCase
{
    use MakesClients;

    private const KEY = 'sk_live_Zm9v+YmFyLzk/QmF6+cXV4/dGVzdF9rZXk+MTIz/NDU2Nzg==';
    private const SECRETS = [self::KEY, 'Zm9v', 'YmFy', 'hunter2', 's3cret'];

    /**
     * Every string reachable from `$value` by walking properties (private ones included, bypassing __debugInfo) and
     * closures (their static variables and bound object), with the path it was found at.
     *
     * @param array<string, string> $found
     * @param array<int, true> $seen
     */
    private static function strings(mixed $value, string $path, array &$found, array &$seen = [], int $depth = 0): void
    {
        if ($depth > 12) {
            return;
        }
        if (is_string($value)) {
            $found[$path] = $value;
            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                self::strings($v, $path . '[' . str_replace("\0", '~', (string) $k) . ']', $found, $seen, $depth + 1);
            }
            return;
        }
        if (!is_object($value)) {
            return;
        }
        $id = spl_object_id($value);
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        if (!$value instanceof \Closure && (!str_starts_with($value::class, 'VoltKit\\Rtc\\') || str_starts_with($value::class, 'VoltKit\\Rtc\\Tests\\'))) {
            // Someone else's object (a logger, a PSR-18 client, the fake transport with its scripted replies, the test
            // runner behind a closure): its class is noted, its insides are its own.
            $found[$path] = $value::class;
            return;
        }
        if ($value instanceof \Closure) {
            $reflection = new \ReflectionFunction($value);
            self::strings($reflection->getStaticVariables(), "$path{closure static}", $found, $seen, $depth + 1);
            self::strings($reflection->getClosureThis(), "$path{closure this}", $found, $seen, $depth + 1);
            return;
        }
        // (array) on an object lists every property, whatever its visibility, and ignores __debugInfo.
        foreach ((array) $value as $k => $v) {
            self::strings($v, $path . '->' . str_replace("\0", '~', (string) $k), $found, $seen, $depth + 1);
        }
        // Reflection reaches what the cast might not (uninitialised or hooked properties are skipped by the cast).
        for ($class = new \ReflectionObject($value); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || !$property->isInitialized($value)) {
                    continue;
                }
                self::strings($property->getValue($value), $path . '::' . $property->getName(), $found, $seen, $depth + 1);
            }
        }
    }

    /** @return array<string, string> */
    private static function leaks(mixed $value): array
    {
        $found = [];
        self::strings($value, 'client', $found);
        $leaks = [];
        foreach ($found as $path => $text) {
            foreach (self::SECRETS as $secret) {
                if (str_contains($text, $secret)) {
                    $leaks[$path] = $text;
                }
            }
        }
        return $leaks;
    }

    private function makeClient(FakeTransport $t): StreamClient
    {
        return $this->client($t, apiKey: self::KEY, baseUrl: 'https://svc:hunter2@x.test/', ratesBaseUrl: 'https://web:s3cret@api.example.com');
    }

    #[Test]
    public function walkingEveryPropertyAndClosureOfTheClientFindsNoKeyAndNoUrlPassword(): void
    {
        $t = new FakeTransport(['status' => 401, 'body' => ['error' => 'unauthorized', 'message' => 'no']]);
        $c = $this->makeClient($t);
        self::assertSame([], self::leaks($c), 'a fresh client');

        // After an error the redaction matcher has been built: its tables spell the key out character by character.
        $this->failure(static fn () => $c->listRooms());
        self::assertSame([], self::leaks($c), 'a client that has redacted an error');
        self::assertSame([], self::leaks(clone $c), 'a cloned client');

        // The walk itself works: it finds what the client does show.
        $found = [];
        self::strings($c, 'client', $found);
        self::assertContains('https://[redacted]@x.test', $found);
    }

    #[Test]
    public function theClientStillSendsTheKeyAndReachesTheUrlsAsGivenAfterAnError(): void
    {
        $t = new FakeTransport(['status' => 401, 'body' => ['error' => 'unauthorized']], ['status' => 200, 'body' => ['rooms' => []]]);
        $c = $this->makeClient($t);
        $this->failure(static fn () => $c->listRooms());
        $c->listRooms();
        self::assertSame(self::KEY, $t->last()->headers['X-API-Key']);
        self::assertSame('https://svc:hunter2@x.test/v1/rooms', $t->last()->url);
    }

    #[Test]
    public function aPrintedRequestShowsNoUrlPasswordEither(): void
    {
        $request = new Request('GET', 'https://deploy:hunter2@x.test/v1/rooms', ['X-API-Key' => self::KEY], null, 10000, false);
        $json = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['print_r' => print_r($request, true), 'json_encode' => $json] as $how => $shown) {
            self::assertStringNotContainsString('hunter2', $shown, $how);
            self::assertStringNotContainsString(self::KEY, $shown, $how);
            self::assertStringContainsString('https://[redacted]@x.test/v1/rooms', $shown, $how);
        }
        self::assertSame('https://deploy:hunter2@x.test/v1/rooms', $request->url, 'the transport itself still sends to the URL as given');
    }
}
