<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 9: an optional PSR-3 logger gets method, path, status, attempt and elapsed time; never a header, a body or the key. */
final class LoggerTest extends TestCase
{
    use MakesClients;

    private const KEY = 'sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0';

    /** @return array{0: StreamClient, 1: \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>} */
    private function loggingClient(FakeTransport $t, int $retries = 2): array
    {
        /** @var \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}> $records */
        $records = new \ArrayObject();
        $logger = new class ($records) extends AbstractLogger {
            /** @param \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}> $records */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => is_scalar($level) ? (string) $level : 'unknown',
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        $c = new StreamClient('https://svc:hunter2@x.test', self::KEY, 'https://api.example.com', 10000, $retries, $t, $logger, static function (int $ms): void {
        });
        return [$c, $records];
    }

    /**
     * @param \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}> $records
     * @return array<string, mixed>
     */
    private static function context(\ArrayObject $records, int $i): array
    {
        $record = $records[$i] ?? null;
        self::assertNotNull($record, "record $i");
        return $record['context'];
    }

    #[Test]
    public function oneDebugRecordPerAttemptWithExactlyTheAllowedFields(): void
    {
        $t = new FakeTransport(['status' => 503, 'body' => ['error' => 'unavailable', 'message' => self::KEY]], ['status' => 201, 'body' => ['roomId' => 'r', 'hostToken' => 'eyJ.secret']]);
        [$c, $records] = $this->loggingClient($t);
        $c->ensureRoom('show 42', 'h', ['note' => 'private body text']);
        self::assertCount(2, $records);
        foreach ($records as $i => $record) {
            self::assertSame('debug', $record['level']);
            self::assertSame(['method', 'path', 'status', 'attempt', 'elapsedMs'], array_keys($record['context']));
            self::assertSame('PUT', $record['context']['method']);
            self::assertSame('/v1/rooms/show%2042', $record['context']['path']);
            self::assertSame($i + 1, $record['context']['attempt']);
            self::assertIsInt($record['context']['elapsedMs']);
            self::assertGreaterThanOrEqual(0, $record['context']['elapsedMs']);
        }
        self::assertSame(503, self::context($records, 0)['status']);
        self::assertSame(201, self::context($records, 1)['status']);
    }

    #[Test]
    public function nothingSecretReachesTheLogger(): void
    {
        $t = new FakeTransport(['status' => 401, 'body' => ['error' => 'unauthorized', 'message' => self::KEY . ' is not a key we know']]);
        [$c, $records] = $this->loggingClient($t, 0);
        $this->failure(fn () => $c->sendMessage('c', 'the body text', metadata: ['token' => 'eyJ.secret']));
        $everything = json_encode(iterator_to_array($records), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([self::KEY, 'hunter2', 'X-API-Key', 'the body text', 'eyJ.secret', 'is not a key we know', 'x.test'] as $secret) {
            self::assertStringNotContainsString($secret, $everything, $secret);
        }
        self::assertStringContainsString('/v1/chat/c/messages', $everything);
    }

    #[Test]
    public function aFailureBeforeAnyReplyIsLoggedWithStatusZero(): void
    {
        $t = new FakeTransport(['network' => true, 'message' => 'connect ECONNREFUSED https://svc:hunter2@x.test']);
        [$c, $records] = $this->loggingClient($t, 1);
        $this->failure(fn () => $c->getBalance());
        self::assertCount(2, $records);
        self::assertSame(0, self::context($records, 0)['status']);
        self::assertSame(0, self::context($records, 1)['status']);
        self::assertStringNotContainsString('hunter2', json_encode(iterator_to_array($records), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    #[Test]
    public function theRatesRequestIsLoggedWithItsPathToo(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['success' => true, 'data' => ['version' => 3]]]);
        [$c, $records] = $this->loggingClient($t);
        $c->getRates();
        self::assertSame('/api/v1/billing/rtc/rates/current', self::context($records, 0)['path']);
        self::assertSame(200, self::context($records, 0)['status']);
    }
}
