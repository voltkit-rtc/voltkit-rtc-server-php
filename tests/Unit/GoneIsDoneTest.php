<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 4.2: a delete whose reply was lost resolves when the retry finds the thing gone (Node NODE-041-5). */
final class GoneIsDoneTest extends TestCase
{
    use MakesClients;

    /** @return array<string, mixed> */
    private static function gone(string $code): array
    {
        return ['status' => 404, 'body' => ['error' => $code, 'message' => 'not found']];
    }

    /** @return iterable<string, array{0: callable(StreamClient): mixed, 1: string, 2: string}> */
    public static function deletes(): iterable
    {
        yield 'deleteRoom' => [static fn (StreamClient $c) => $c->deleteRoom('r'), 'no-room', 'DELETE /v1/rooms/r'];
        yield 'deleteMessage' => [static fn (StreamClient $c) => $c->deleteMessage('lobby', '1-0'), 'no-message', 'DELETE /v1/chat/lobby/messages/1-0'];
        yield 'unmuteChat' => [static fn (StreamClient $c) => $c->unmuteChat('lobby', 'u'), 'not-found', 'DELETE /v1/chat/lobby/mutes/u'];
        yield 'deleteStream' => [static fn (StreamClient $c) => $c->deleteStream('s'), 'no-stream', 'DELETE /v1/streams/s'];
    }

    /** @return list<array{0: string, 1: list<array<string, mixed>>}> */
    private static function lostReplies(): array
    {
        return [
            ['the connection broke', [['network' => true]]],
            ['the body dropped mid-read', [['bodyDropped' => true]]],
            ['a proxy answered 502', [['status' => 502, 'raw' => '<html>502 Bad Gateway</html>']]],
            ['the reply was a 503', [['status' => 503, 'body' => ['error' => 'unavailable']]]],
            ['the deadline passed', [['hangs' => true]]],
            ['the connection broke, then a 429', [['network' => true], ['status' => 429, 'body' => ['error' => 'rate-limited']]]],
            ['the connection broke twice', [['network' => true], ['network' => true]]],
        ];
    }

    #[Test]
    #[DataProvider('deletes')]
    public function aDeleteWhoseReplyWasLostResolvesWhenTheRetryFindsTheThingGone(callable $delete, string $code, string $where): void
    {
        foreach (self::lostReplies() as [$how, $before]) {
            $t = new FakeTransport(...[...$before, self::gone($code)]);
            self::assertNull($delete($this->client($t, retries: 2)), "$where, $how");
            self::assertSame(count($before) + 1, $t->attempts(), "$where, $how: retried until it was found gone");
            foreach ($t->calls as $r) {
                self::assertSame('DELETE', $r->method);
            }
        }
    }

    #[Test]
    #[DataProvider('deletes')]
    public function aNotFoundOnTheFirstAttemptStillFails(callable $delete, string $code, string $where): void
    {
        foreach ([0, 2] as $retries) {
            $t = new FakeTransport(self::gone($code));
            $e = $this->failure(fn () => $delete($this->client($t, retries: $retries)));
            self::assertSame($code, $e->getErrorCode(), "$where, retries $retries");
            self::assertTrue($e->isNotFound());
            self::assertFalse($e->isRetryable());
            self::assertSame("$where: not found", $e->getMessage());
            self::assertSame(1, $t->attempts(), "$where, retries $retries: asked once");
        }
    }

    #[Test]
    public function onlyANotFoundForAThingGoneAfterAnAttemptThatMayHaveLandedCountsAsDone(): void
    {
        $cases = [
            // A 429 is a refusal: that attempt did nothing, so the 404 is news.
            ['deleteRoom after a 429', static fn (StreamClient $c) => $c->deleteRoom('r'), [['status' => 429, 'body' => ['error' => 'rate-limited']], self::gone('no-room')], 'no-room'],
            // A deployment with broadcast or chat switched off deleted nothing.
            ['deleteStream, broadcast off', static fn (StreamClient $c) => $c->deleteStream('s'), [['network' => true], self::gone('broadcast-disabled')], 'broadcast-disabled'],
            ['deleteMessage, chat off', static fn (StreamClient $c) => $c->deleteMessage('lobby', '1-0'), [['network' => true], self::gone('chat-disabled')], 'chat-disabled'],
            ['unmuteChat, chat off', static fn (StreamClient $c) => $c->unmuteChat('lobby', 'u'), [['bodyDropped' => true], self::gone('chat-disabled')], 'chat-disabled'],
            // Another refusal on the retry is still a refusal.
            ['deleteRoom, then a 401', static fn (StreamClient $c) => $c->deleteRoom('r'), [['status' => 503, 'body' => ['error' => 'unavailable']], ['status' => 401, 'body' => ['error' => 'unauthorized']]], 'unauthorized'],
            // Only the four deletes: every other call reports a 404 on a retry as before.
            ['getRoom', static fn (StreamClient $c) => $c->getRoom('r'), [['network' => true], self::gone('no-room')], 'no-room'],
            ['ensureRoom (PUT)', static fn (StreamClient $c) => $c->ensureRoom('r', 'h'), [['network' => true], self::gone('no-room')], 'no-room'],
            ['muteChat (PUT)', static fn (StreamClient $c) => $c->muteChat('lobby', 'u'), [['network' => true], self::gone('not-found')], 'not-found'],
            ['createToken (POST)', static fn (StreamClient $c) => $c->createToken('r', 'u', 'viewer'), [['network' => true], self::gone('no-room')], 'no-room'],
            ['updateStream (PATCH)', static fn (StreamClient $c) => $c->updateStream('s', 'public'), [['network' => true], self::gone('no-stream')], 'no-stream'],
        ];
        foreach ($cases as [$what, $call, $steps, $code]) {
            $t = new FakeTransport(...$steps);
            $e = $this->failure(fn () => $call($this->client($t, retries: 2)));
            self::assertSame($code, $e->getErrorCode(), $what);
            self::assertSame(2, $t->attempts(), "$what: one retry, then that answer");
        }
    }

    #[Test]
    public function aCreateIsStillSentOnceHoweverItFails(): void
    {
        $t = new FakeTransport(['network' => true], ['status' => 201, 'body' => []]);
        $e = $this->failure(fn () => $this->client($t, retries: 2)->createRoom('h'));
        self::assertSame('network', $e->getErrorCode());
        self::assertSame(1, $t->attempts());
    }
}
