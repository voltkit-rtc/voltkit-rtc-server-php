<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\Calls;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 3.1 to 3.3: path segments, query strings, headers and bodies. */
final class RequestsTest extends TestCase
{
    use MakesClients;

    #[Test]
    public function anIdOfEmptyDotOrDotDotIsRefusedAtEveryPlaceAndNothingIsSent(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => []]);
        $c = $this->client($t);
        $outcomes = 0;
        foreach (Calls::PLACES as [$method, $param]) {
            foreach (['', '.', '..'] as $id) {
                $outcomes++;
                try {
                    Calls::withId($c, $method, $param, $id);
                    self::fail("$method $param " . var_export($id, true) . ': sent');
                } catch (StreamError $e) {
                    self::assertSame('bad-request', $e->getErrorCode(), "$method $param");
                    self::assertSame(0, $e->getStatus(), "$method $param: never sent");
                    self::assertFalse($e->isRetryable());
                    self::assertSame(
                        "$param \"$id\": an id may not be \"\", \".\" or \"..\", which a URL resolves to another endpoint; nothing was sent",
                        $e->getMessage(),
                    );
                }
            }
        }
        self::assertSame(60, $outcomes, '20 ids in paths, 3 values each');
        self::assertSame(0, $t->attempts(), 'nothing is sent');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function encodedIds(): iterable
    {
        yield 'plain' => ['show-42', 'show-42'];
        yield 'space, slash, question mark, hash' => ['a b/c?d#e', 'a%20b%2Fc%3Fd%23e'];
        yield 'a pre-encoded id stays in its segment' => ['%2e%2e', '%252e%252e'];
        yield 'unicode' => ['ü-房间', '%C3%BC-%E6%88%BF%E9%97%B4'];
        yield "!*'() are kept, unlike rawurlencode" => ["!*'()", "!*'()"];
        yield 'three dots' => ['...', '...'];
        yield 'dot then a letter' => ['.a', '.a'];
        yield 'a letter then a dot' => ['a.', 'a.'];
        yield 'tilde, dash, underscore kept' => ['~a-b_c', '~a-b_c'];
        yield 'plus, ampersand, equals, percent encoded' => ['a+b&c=d%e', 'a%2Bb%26c%3Dd%25e'];
        yield 'a colon and an at sign' => ['user:7@host', 'user%3A7%40host'];
    }

    #[Test]
    #[DataProvider('encodedIds')]
    public function everyOtherIdIsPercentEncodedLikeEncodeUriComponent(string $id, string $segment): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['room' => new \stdClass()]]);
        $this->client($t)->getRoom($id);
        self::assertSame("https://x.test/v1/rooms/$segment", $t->last()->url);
    }

    #[Test]
    public function listMessagesSendsOnlyTheOptionsGivenAndNonEmpty(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['messages' => []]]);
        $c = $this->client($t);
        $c->listMessages('show-42', 20, '1755600000000-0');
        self::assertSame('https://x.test/v1/chat/show-42/messages?limit=20&before=1755600000000-0', $t->last()->url);
        $c->listMessages('show-42');
        self::assertSame('https://x.test/v1/chat/show-42/messages', $t->last()->url);
        $c->listMessages('show-42', 0, '');
        self::assertSame('https://x.test/v1/chat/show-42/messages', $t->last()->url, 'limit 0 and before "" are not sent');
        $c->listMessages('show-42', null, 'a b&c');
        self::assertSame('https://x.test/v1/chat/show-42/messages?before=a+b%26c', $t->last()->url, 'form-encoded, as URLSearchParams writes it');
        $c->listMessages('show-42', 5);
        self::assertSame('https://x.test/v1/chat/show-42/messages?limit=5', $t->last()->url);
    }

    #[Test]
    public function getUsageSeriesAlwaysSendsDays(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['series' => []]]);
        $c = $this->client($t);
        $c->getUsageSeries();
        self::assertSame('https://x.test/v1/usage/series?days=30', $t->last()->url);
        $c->getUsageSeries(7);
        self::assertSame('https://x.test/v1/usage/series?days=7', $t->last()->url);
    }

    #[Test]
    public function aKeyedRequestCarriesTheKeyAndTheUserAgentAndNothingElseWithoutABody(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => []]]);
        $this->client($t)->listRooms();
        $r = $t->last();
        self::assertSame(['X-API-Key' => 'sk_test', 'User-Agent' => 'voltkit-rtc-php/' . StreamClient::VERSION], $r->headers);
        self::assertNull($r->body);
        self::assertFalse($r->followRedirects, 'a redirect is never followed with the key');
        self::assertSame('GET', $r->method);
    }

    #[Test]
    public function aRequestWithABodyAddsContentTypeJson(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['token' => 't']]);
        $this->client($t)->createToken('r', 'u', 'viewer');
        $r = $t->last();
        self::assertSame('application/json', $r->headers['Content-Type']);
        self::assertSame('sk_test', $r->headers['X-API-Key']);
        self::assertSame('{"identity":"u","role":"viewer"}', $r->body);
    }

    #[Test]
    public function callsWithoutABodySendNoneAndNoContentType(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['room' => new \stdClass()]]);
        $c = $this->client($t);
        $c->getRoom('r');
        $c->deleteRoom('r');
        $c->muteChat('lobby', 'u');
        $c->unmuteChat('lobby', 'u');
        foreach ($t->calls as $r) {
            self::assertNull($r->body, "$r->method $r->url");
            self::assertArrayNotHasKey('Content-Type', $r->headers, "$r->method $r->url");
        }
        self::assertSame(['GET', 'DELETE', 'PUT', 'DELETE'], array_map(static fn ($r) => $r->method, $t->calls));
    }

    #[Test]
    public function theRatesRequestCarriesNoKeyAndMayFollowRedirects(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['success' => true, 'data' => ['version' => 3]]]);
        $this->client($t, ratesBaseUrl: 'https://api.example.com')->getRates();
        $r = $t->last();
        self::assertSame(['User-Agent' => 'voltkit-rtc-php/' . StreamClient::VERSION], $r->headers, 'no X-API-Key, no Authorization, nothing');
        self::assertTrue($r->followRedirects);
        self::assertStringNotContainsString('sk_test', print_r($r, true));
    }

    #[Test]
    public function aBodyIsSerializedOnceWithSlashesAndUnicodeUnescapedAndOptionalFieldsLeftOut(): void
    {
        $metadata = ['seats' => 8, 'text' => 'ü 房间 "quoted" a/b', 'nested' => ['list' => [1, 'two', null]], 'flag' => false];
        $expected = '{"hostIdentity":"h","metadata":{"seats":8,"text":"ü 房间 \"quoted\" a/b","nested":{"list":[1,"two",null]},"flag":false}}';
        $t = new FakeTransport(['status' => 201, 'body' => ['roomId' => 'r']]);
        $this->client($t)->ensureRoom('r', 'h', $metadata);
        self::assertSame($expected, $t->last()->body);

        $t = new FakeTransport(['status' => 201, 'body' => ['roomId' => 'r']]);
        $this->client($t)->createRoom('h');
        self::assertSame('{"hostIdentity":"h"}', $t->last()->body, 'roomId and metadata left out, not null');

        $t = new FakeTransport(['status' => 201, 'body' => ['id' => '1-0']]);
        $this->client($t)->sendMessage('c', 'hi', 'system', from: 'user-7', to: 'user-13', metadata: ['gift' => 'rose'], ephemeral: true);
        self::assertSame('{"text":"hi","type":"system","from":"user-7","to":"user-13","metadata":{"gift":"rose"},"ephemeral":true}', $t->last()->body);
    }

    #[Test]
    public function anEmptyPatchIsAnEmptyJsonObjectNotAnEmptyList(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['streamId' => 's']]);
        $this->client($t)->updateStream('s');
        self::assertSame('{}', $t->last()->body);
    }

    #[Test]
    public function metadataKeepsItsShape(): void
    {
        $t = new FakeTransport(['status' => 201, 'body' => ['roomId' => 'r']]);
        $c = $this->client($t);
        $c->ensureRoom('r', 'h', [1, 'two']);
        self::assertSame('{"hostIdentity":"h","metadata":[1,"two"]}', $t->last()->body, 'a list stays a list');
        $c->ensureRoom('r', 'h', new \stdClass());
        self::assertSame('{"hostIdentity":"h","metadata":{}}', $t->last()->body, 'an object stays an object');
        $c->ensureRoom('r', 'h', 'plain text');
        self::assertSame('{"hostIdentity":"h","metadata":"plain text"}', $t->last()->body, 'opaque JSON of any kind');
        $c->ensureRoom('r', 'h', ['seats' => 8]);
        self::assertSame('{"hostIdentity":"h","metadata":{"seats":8}}', $t->last()->body);
    }

    #[Test]
    public function aRetriedCallSendsTheSameBytesEachTimeAndSerializesOnce(): void
    {
        $counting = new class implements \JsonSerializable {
            public int $serialized = 0;

            public function jsonSerialize(): mixed
            {
                $this->serialized++;
                return ['seats' => 8];
            }
        };
        $t = new FakeTransport(
            ['status' => 503, 'body' => ['error' => 'unavailable']],
            ['status' => 503, 'body' => ['error' => 'unavailable']],
            ['status' => 201, 'body' => ['roomId' => 'r']],
        );
        $this->client($t)->ensureRoom('r', 'h', $counting);
        self::assertSame(3, $t->attempts());
        self::assertSame(1, $counting->serialized, 'serialized once, before the first attempt');
        $bodies = array_map(static fn ($r) => $r->body, $t->calls);
        self::assertSame(['{"hostIdentity":"h","metadata":{"seats":8}}', '{"hostIdentity":"h","metadata":{"seats":8}}', '{"hostIdentity":"h","metadata":{"seats":8}}'], $bodies);
        foreach ($t->calls as $r) {
            self::assertSame('application/json', $r->headers['Content-Type']);
        }
    }

    /** @return iterable<string, array{0: string, 1: callable(StreamClient): mixed}> */
    public static function unencodableBodies(): iterable
    {
        $nan = ['amount' => NAN];
        $loop = ['seats' => 8];
        $loop['self'] = &$loop;
        yield 'PUT /v1/rooms/r' => ['PUT /v1/rooms/r', static fn (StreamClient $c) => $c->ensureRoom('r', 'h', $loop)];
        yield 'PATCH /v1/streams/s' => ['PATCH /v1/streams/s', static fn (StreamClient $c) => $c->updateStream('s', metadata: $nan)];
        yield 'POST /v1/rooms' => ['POST /v1/rooms', static fn (StreamClient $c) => $c->createRoom('h', metadata: $nan)];
        yield 'POST /v1/streams' => ['POST /v1/streams', static fn (StreamClient $c) => $c->createStream('s', metadata: $loop)];
        yield 'POST /v1/chat/c/messages' => ['POST /v1/chat/c/messages', static fn (StreamClient $c) => $c->sendMessage('c', 'hi', metadata: $nan)];
        yield 'invalid UTF-8 in a string' => ['PUT /v1/rooms/r', static fn (StreamClient $c) => $c->ensureRoom('r', 'h', ['t' => "caf\xE9"])];
    }

    #[Test]
    #[DataProvider('unencodableBodies')]
    public function aBodyTheEncoderRefusesFailsBeforeAnythingIsSentAsAProgrammerError(string $where, callable $call): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => []]);
        try {
            $call($this->client($t));
            self::fail('sent');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith("$where: the body cannot be sent as JSON: ", $e->getMessage());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious(), 'the encoder\'s error is the cause');
        }
        self::assertSame(0, $t->attempts(), 'nothing is sent, and nothing is retried');
    }
}
