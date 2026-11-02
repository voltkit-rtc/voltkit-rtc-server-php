<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use VoltKit\Rtc\Internal\ReplyJson;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\Tests\Support\FakePsr17;
use VoltKit\Rtc\Tests\Support\FakePsr18Client;
use VoltKit\Rtc\Tests\Support\FakeResponse;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;
use VoltKit\Rtc\Transport\Psr18Transport;
use VoltKit\Rtc\Transport\Request;
use VoltKit\Rtc\Webhooks;

/**
 * Regression check of the SDK round of 2026-11-01 (D13, PHP 0.1.3), by the lead's verifier: what the three fixes must
 * not have moved. ReplyJson::decode gives, in both modes, exactly what json_decode($text, $assoc, 512,
 * JSON_THROW_ON_ERROR) gave on 0.1.2 for every ordinary text (big ints as floats, {} as [] in assoc mode, duplicate
 * keys, key order, -0.0, 1e400) and throws the same code and message for text that is not JSON; only a lone
 * surrogate escape and nesting past 512 moved, on purpose. On the symfony/http-client installed here (7.4) the
 * Psr18Transport still takes the withOptions() path: the key goes through a copy with max_redirects 0, the public
 * rates through the client as given, and a PSR-18 client that is not Symfony's is used as it is.
 */
final class Verify1101RegressionsTest extends TestCase
{
    use MakesClients;

    /** @return iterable<string, array{string}> */
    public static function ordinaryTexts(): iterable
    {
        yield 'an object of every kind of value' => ['{"a":1,"b":2.5,"c":-0,"d":-0.0,"e":1e5,"f":1E-7,"g":12345678901234567890123,'
            . '"h":[],"i":{},"j":[[],[{}]],"k":"\\u00e9\\ud83d\\ude00\\n\\\\\\"\\/\\b\\f\\r\\t","l":"é😀日本","m":null,"n":true,"o":false}'];
        yield 'duplicate keys' => ['{"dup":1,"x":2,"dup":3}'];
        yield 'duplicate keys of containers' => ['{"dup":{"a":1},"dup":[2]}'];
        yield 'numeric keys' => ['{"0":1,"1":2,"10":3,"-1":4,"01":5}'];
        yield 'an empty object' => ['{}'];
        yield 'an empty array' => ['[]'];
        yield 'empty containers nested' => ['[{},{"a":{}},[],[{}],{"b":[]}]'];
        yield 'big ints and overflowing floats' => ['[9223372036854775807,9223372036854775808,-9223372036854775808,-9223372036854775809,1e400,-1e400,1e-400]'];
        yield 'a string' => ['"s"'];
        yield 'zero' => ['0'];
        yield 'null' => ['null'];
        yield 'true' => ['true'];
        yield 'a float with a whole value' => ['1.0'];
        yield 'an exponent' => ['1.5e3'];
        yield 'whitespace around' => [" \t\n\r{\"a\" : [ 1 , 2 ] } \n"];
        yield 'a NUL inside a string' => ['{"a":"x\\u0000y"}'];
        yield 'an empty key' => ['{"":1}'];
        yield '511 levels (the deepest json_decode read at depth 512)' => [str_repeat('[', 511) . str_repeat(']', 511)];
        yield '300 levels of objects' => [str_repeat('{"a":', 300) . '1' . str_repeat('}', 300)];
        yield 'a 4-byte character raw and escaped' => ['["\\ud83d\\ude00","😀","\\u00e9","é"]'];
    }

    #[Test]
    #[DataProvider('ordinaryTexts')]
    public function replyJsonDecodesOrdinaryTextExactlyAsJsonDecodeDidOnZeroOneTwo(string $text): void
    {
        foreach ([true, false] as $assoc) {
            $before = json_decode($text, $assoc, 512, JSON_THROW_ON_ERROR);
            $after = ReplyJson::decode($text, $assoc);
            self::assertEquals($before, $after, $assoc ? 'assoc' : 'objects');
            // Types, float spelling, key order and the sign of zero, which assertEquals does not see.
            self::assertSame(var_export($before, true), var_export($after, true), $assoc ? 'assoc' : 'objects');
            self::assertSame(
                json_encode($before, JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR),
                json_encode($after, JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR),
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function textsPhpRefuses(): iterable
    {
        yield 'nothing' => [''];
        yield 'whitespace' => ['  '];
        yield 'an open brace' => ['{'];
        yield 'an open bracket' => ['['];
        yield 'a trailing comma in a list' => ['[1,]'];
        yield 'a trailing comma in an object' => ['{"a":1,}'];
        yield 'single quotes' => ["{'a':1}"];
        yield 'a leading zero' => ['01'];
        yield 'a plus sign' => ['+1'];
        yield 'a bare fraction' => ['.5'];
        yield 'a bare word' => ['nul'];
        yield 'a control character in a string' => ["\"\x01\""];
        yield 'invalid UTF-8' => ["\"\x80\""];
        yield 'trailing text' => ['[1] x'];
        yield 'a bad escape' => ['"\\x"'];
        yield 'a short unicode escape' => ['"\\u12"'];
        yield 'an unterminated string' => ['"abc'];
        yield 'a key without quotes' => ['{a:1}'];
        yield 'a property name starting with NUL' => ['{"\\u0000a":1}'];
    }

    #[Test]
    #[DataProvider('textsPhpRefuses')]
    public function replyJsonThrowsTheSameCodeAndMessageJsonDecodeThrewOnZeroOneTwo(string $text): void
    {
        foreach ([true, false] as $assoc) {
            $before = null;
            try {
                json_decode($text, $assoc, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $before = $e;
            }
            if ($before === null) {
                // Valid in this mode (the NUL key is refused in object mode only): then ReplyJson reads it too.
                self::assertEquals(json_decode($text, $assoc, 512, JSON_THROW_ON_ERROR), ReplyJson::decode($text, $assoc));
                continue;
            }
            try {
                ReplyJson::decode($text, $assoc);
                self::fail("ReplyJson read what json_decode refused: $text");
            } catch (\JsonException $after) {
                self::assertSame($before->getCode(), $after->getCode(), $assoc ? 'assoc' : 'objects');
                self::assertSame($before->getMessage(), $after->getMessage(), $assoc ? 'assoc' : 'objects');
            }
        }
    }

    #[Test]
    public function whatMovedOnPurposeIsTheLoneSurrogateAndTheDepthPastFiveHundredTwelveAndNothingElse(): void
    {
        // 0.1.2: JSON_ERROR_UTF16 (code 10) and JSON_ERROR_DEPTH (code 1, from 512 nested arrays on); 0.1.3 reads both.
        try {
            json_decode('"a\\ud83db"', true, 512, JSON_THROW_ON_ERROR);
            self::fail();
        } catch (\JsonException $e) {
            self::assertSame(JSON_ERROR_UTF16, $e->getCode());
        }
        self::assertSame("a\u{FFFD}b", ReplyJson::decode('"a\\ud83db"', true));
        $deep = str_repeat('[', 512) . str_repeat(']', 512);
        try {
            json_decode($deep, true, 512, JSON_THROW_ON_ERROR);
            self::fail();
        } catch (\JsonException $e) {
            self::assertSame(JSON_ERROR_DEPTH, $e->getCode());
        }
        self::assertIsArray(ReplyJson::decode($deep, true));
        // Past PHP's own parser limit it is still a clean \JsonException, as any text PHP cannot read.
        $tooDeep = str_repeat('[', 6000) . str_repeat(']', 6000);
        try {
            ReplyJson::decode($tooDeep, true);
            self::fail('read 6,000 levels');
        } catch (\JsonException $e) {
            self::assertContains($e->getCode(), [JSON_ERROR_DEPTH, JSON_ERROR_SYNTAX]);
        }
    }

    #[Test]
    public function throughTheClientAnOrdinaryReplyReadsAsOnZeroOneTwo(): void
    {
        $reply = '{"messages":[{"id":"1-0","channel":"c","from":"u","type":"text","text":"\\u00e9\\ud83d\\ude00 é😀",'
            . '"metadata":{},"ts":1787000000000},{"id":"2-0","channel":"c","from":"u","type":"text","text":"x",'
            . '"metadata":{"score":12.5,"big":12345678901234567890,"list":[],"obj":{"k":null}},"ts":9007199254740993}]}';
        $t = FakeTransport::raw(200, $reply);
        $messages = $this->client($t)->listMessages('c');
        self::assertCount(2, $messages);
        self::assertSame("é😀 é😀", $messages[0]['text'] ?? null);
        self::assertSame([], $messages[0]['metadata'] ?? null, '{} is [] in assoc mode, as before');
        self::assertSame(1787000000000, $messages[0]['ts']);
        self::assertSame(9007199254740993, $messages[1]['ts']);
        $metadata = $messages[1]['metadata'] ?? null;
        self::assertIsArray($metadata);
        self::assertSame(12.5, $metadata['score']);
        self::assertIsFloat($metadata['big'], 'a big int is a float, as json_decode without JSON_BIGINT_AS_STRING gave it');
        self::assertSame(['k' => null], $metadata['obj']);
        self::assertSame(1, $t->attempts());

        // Webhooks::parse: an ordinary event is the same array json_decode gave; a body that is not JSON is still
        // \JsonException, the documented type.
        $body = '{"id":"evt_1","type":"chat.message","tenantId":"t","createdAt":1787000000123,"data":{"text":"é😀","n":[1,2.5]}}';
        $event = Webhooks::parse($body, $this->signed($body), 'whsec_verify', ['now' => 1787000000]);
        self::assertSame(json_decode($body, true, 512, JSON_THROW_ON_ERROR), $event);
        $this->expectException(\JsonException::class);
        Webhooks::parse('{"id":', $this->signed('{"id":'), 'whsec_verify', ['now' => 1787000000]);
    }

    private function signed(string $body): string
    {
        return 't=1787000000,v1=' . hash_hmac('sha256', '1787000000.' . $body, 'whsec_verify');
    }

    #[Test]
    public function onSymfonySevenTheKeyStillGoesThroughTheWithOptionsCopyWithMaxRedirectsZeroAndTheRatesAsGiven(): void
    {
        $headers = ['response_headers' => ['content-type' => 'application/json']];
        $responses = [
            new MockResponse('{"rooms":[]}', $headers + ['http_code' => 200]),
            new MockResponse('{"success":true,"data":{"version":3,"kind":"standard","tiers":[]}}', $headers + ['http_code' => 200]),
            new MockResponse('{"rooms":[]}', $headers + ['http_code' => 200]),
        ];
        $factory = new FakePsr17();
        $symfony = new Psr18Client(new MockHttpClient($responses), $factory, $factory);
        $transport = new Psr18Transport($symfony, $factory, $factory);
        $rtc = new StreamClient('https://x.test', 'sk_test', 'https://api.example.com', 10000, 0, $transport);

        self::assertSame(['rooms' => []], $rtc->listRooms());
        self::assertSame('https://x.test/v1/rooms', $responses[0]->getRequestUrl());
        $options = $responses[0]->getRequestOptions();
        self::assertSame(0, $options['max_redirects'], 'the key goes through the non-following copy');
        self::assertIsArray($options['normalized_headers']);
        self::assertArrayHasKey('x-api-key', $options['normalized_headers']);

        $rates = $rtc->getRates();
        self::assertSame(3, $rates['version']);
        self::assertSame('https://api.example.com/api/v1/billing/rtc/rates/current', $responses[1]->getRequestUrl());
        $options = $responses[1]->getRequestOptions();
        // The client as given: no max_redirects of the adapter's in its options, so Symfony's default (20) applies.
        self::assertArrayNotHasKey('max_redirects', $options, 'the public rates go through the client as given');
        self::assertIsArray($options['normalized_headers']);
        self::assertArrayNotHasKey('x-api-key', $options['normalized_headers']);

        // The transport alone, a request carrying the key with followRedirects false: the same copy.
        $response = $transport->send(new Request('GET', 'https://x.test/v1/rooms', ['X-API-Key' => 'sk_test'], null, 1000, false));
        self::assertSame(200, $response->status);
        self::assertSame('{"rooms":[]}', $response->body);
        self::assertSame(0, $responses[2]->getRequestOptions()['max_redirects']);
    }

    #[Test]
    public function aPsr18ClientThatIsNotSymfonysIsStillUsedAsItIs(): void
    {
        $client = new FakePsr18Client([new FakeResponse(200, ['Content-Type' => 'application/json'], '{"rooms":[]}')]);
        $factory = new FakePsr17();
        $rtc = new StreamClient('https://x.test', 'sk_test', transport: new Psr18Transport($client, $factory, $factory));
        self::assertSame(['rooms' => []], $rtc->listRooms());
        self::assertCount(1, $client->requests);
        self::assertSame('https://x.test/v1/rooms', (string) $client->requests[0]->getUri());
    }
}
