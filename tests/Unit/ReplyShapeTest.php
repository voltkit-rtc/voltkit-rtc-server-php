<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/**
 * X-1, PHP011-1 (D4; spec 3.6): a 2xx that is not what the call needs is refused with http-<status>, not retried, in
 * words that quote nothing of the reply. `createToken` answered 200 `{}` returned `[]`; a JSON list passed as an
 * object, because json_decode() makes [] and {} the same empty array and {"0":1} a list, and createRoom then added
 * `existed` to it. The vectors (section replies) hold every call; these are the traps of PHP's own decoder.
 */
final class ReplyShapeTest extends TestCase
{
    use MakesClients;

    private const KEY = 'sk_8d41f0c2b97e3a65d0f1c4e7a92b5d38c6e1f04a7b29d3c58e60f1a2b4c7d9e0';

    private function refusal(string $reply, callable $call, int $status = 200): StreamError
    {
        $t = FakeTransport::raw($status, $reply);
        $e = $this->failure(fn () => $call($this->client($t, retries: 2, apiKey: self::KEY)));
        self::assertSame(1, $t->attempts(), 'not retried');
        self::assertSame("http-$status", $e->getErrorCode());
        self::assertSame($status, $e->getStatus());
        self::assertFalse($e->isRetryable());
        return $e;
    }

    #[Test]
    public function theReportsRepliesAreRefused(): void
    {
        $token = static fn (StreamClient $c): array => $c->createToken('r', 'u', 'viewer');
        $prefix = 'POST /v1/rooms/r/tokens: the reply is not the expected shape: ';
        self::assertSame($prefix . '"token" is not a non-empty string', $this->refusal('{}', $token)->getMessage());
        self::assertSame($prefix . 'it is not an object', $this->refusal('[]', $token)->getMessage());
        self::assertSame($prefix . 'it is not an object', $this->refusal('', $token)->getMessage(), 'an empty body');
        self::assertSame($prefix . 'it is not an object', $this->refusal('[{"token":"t"}]', $token)->getMessage());
        $room = static fn (StreamClient $c): array => $c->createRoom('h');
        self::assertSame('POST /v1/rooms: the reply is not the expected shape: it is not an object', $this->refusal('[1,2]', $room, 201)->getMessage(), 'no `existed` on a list');
        $ensure = static fn (StreamClient $c): array => $c->ensureRoom('r', 'h');
        self::assertSame('PUT /v1/rooms/r: the reply is not the expected shape: "roomId" is not a non-empty string', $this->refusal('{}', $ensure)->getMessage());
    }

    #[Test]
    public function anObjectIsToldFromAListByTheJsonNotByWhatPhpDecodesItTo(): void
    {
        $t = FakeTransport::raw(200, '{"room":{}}');
        self::assertSame(['room' => []], $this->client($t)->getRoom('r'), '{} is an object, and comes back as []');
        $t = FakeTransport::raw(200, '{"room":{"0":"a","1":"b"}}');
        self::assertSame(['room' => ['a', 'b']], $this->client($t)->getRoom('r'), 'an object whose keys are numbers is an object');
        $getRoom = static fn (StreamClient $c): array => $c->getRoom('r');
        foreach (['{"room":[]}', '{"room":["a"]}', '{"room":null}', '{"room":"x"}'] as $reply) {
            self::assertSame('GET /v1/rooms/r: the reply is not the expected shape: "room" is not an object', $this->refusal($reply, $getRoom)->getMessage(), $reply);
        }
        $listRooms = static fn (StreamClient $c): array => $c->listRooms();
        foreach (['{"rooms":{}}', '{"rooms":{"0":{"roomId":"x"}}}', '{"rooms":null}', '{}'] as $reply) {
            self::assertSame('GET /v1/rooms: the reply is not the expected shape: "rooms" is not an array', $this->refusal($reply, $listRooms)->getMessage(), $reply);
        }
        $participants = static fn (StreamClient $c): array => $c->listParticipants('r');
        foreach (['{"participants":{}}', '{"participants":{"0":{"identity":"u"}}}', '{"participants":null}'] as $reply) {
            self::assertSame('GET /v1/rooms/r/participants: the reply is not the expected shape: "participants" is not an array', $this->refusal($reply, $participants)->getMessage(), $reply);
        }
        self::assertSame([], $this->client(FakeTransport::raw(200, '{}'))->listParticipants('r'), 'missing is an empty list');
        foreach (['[]', '[{"participants":[]}]', '""', 'null'] as $reply) {
            self::assertSame('GET /v1/rooms/r/participants: the reply is not the expected shape: it is not an object', $this->refusal($reply, $participants)->getMessage(), $reply);
        }
        $usage = static fn (StreamClient $c): array => $c->getUsage();
        self::assertSame([], $this->client(FakeTransport::raw(200, " {}\n"))->getUsage(), 'JSON whitespace around an object');
        self::assertSame('GET /v1/usage: the reply is not the expected shape: it is not an object', $this->refusal(" []\n", $usage)->getMessage());
    }

    #[Test]
    public function anUnknownFieldIsAcceptedWhateverItsNameEvenOneJsonDecodeCannotMakeAPropertyOf(): void
    {
        // json_decode() refuses a property name that starts with a NUL when it builds objects; the wire is additive.
        $reply = '{"token":"t","\u0000x":1,"metadata":{"\u0000y":{"\u0000":[]}},"brandNewField":{"a":[1]}}';
        /** @var array<string, mixed> $token */
        $token = $this->client(FakeTransport::raw(200, $reply))->createToken('r', 'u', 'viewer');
        self::assertSame('t', $token['token']);
        self::assertSame(1, $token["\0x"] ?? null);
        self::assertSame(['a' => [1]], $token['brandNewField'] ?? null);
        // A string value that merely holds the escape is untouched.
        $info = $this->client(FakeTransport::raw(200, '{"channel":"\u0000c","note":"say \"\u0000\""}'))->getChatInfo('c');
        self::assertSame(["channel" => "\0c", 'note' => "say \"\0\""], $info);
    }

    #[Test]
    public function aDeleteOrAMuteResolvesOnAnyJsonButNotOnAPage(): void
    {
        foreach (['', '[]', 'null', '5', '"x"', '{"closed":true}', '{}'] as $reply) {
            $this->client(FakeTransport::raw(200, $reply))->deleteRoom('r');
            $this->client(FakeTransport::raw(200, $reply))->muteChat('c', 'u');
        }
        $e = $this->refusal('<html>', static fn (StreamClient $c) => $c->deleteRoom('r'));
        self::assertSame('DELETE /v1/rooms/r: the reply is not JSON: <html>', $e->getMessage());
        $this->addToAssertionCount(14);
    }

    #[Test]
    public function theMessageQuotesNothingOfTheReplyAndNoFrameHoldsIt(): void
    {
        $ignore = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $reply = '{"other":"' . self::KEY . '","token":["' . self::KEY . '"]}';
            $e = $this->refusal($reply, static fn (StreamClient $c): array => $c->createToken('r', 'u', 'viewer'));
            self::assertSame('POST /v1/rooms/r/tokens: the reply is not the expected shape: "token" is not a non-empty string', $e->getMessage());
            $frames = array_filter($e->getTrace(), static fn (array $f): bool => str_starts_with((string) ($f['class'] ?? ''), 'VoltKit\\Rtc\\') && !str_starts_with((string) ($f['class'] ?? ''), 'VoltKit\\Rtc\\Tests\\'));
            self::assertNotEmpty($frames);
            self::assertStringNotContainsString(self::KEY, serialize(array_values($frames)), 'no SDK frame holds the reply or the key');
            self::assertStringNotContainsString('8d41f0c2', (string) $e);
        } finally {
            ini_set('zend.exception_ignore_args', $ignore);
        }
    }

    #[Test]
    public function aTypedCallOnARetryThatLandsOnTheWrongShapeIsNotRetriedAgain(): void
    {
        $t = new FakeTransport(
            ['status' => 503, 'body' => ['error' => 'unavailable', 'message' => 'm']],
            ['status' => 200, 'body' => new \stdClass()],
            ['status' => 200, 'body' => ['roomId' => 'r']],
        );
        $e = $this->failure(fn () => $this->client($t)->ensureRoom('r', 'h'));
        self::assertSame(['http-200', 2], [$e->getErrorCode(), $t->attempts()]);
    }
}
