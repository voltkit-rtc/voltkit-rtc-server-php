<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Examples;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\Tests\Support\Loopback;

/**
 * examples/plain served by `php -S`, its client pointed at the loopback controller: the token endpoint never answers
 * 200 without a usable token, and neither endpoint reads more than 1 MB of body, with a Content-Length or chunked.
 *
 * The servers run with memory_limit=16M and display_errors off, so an endpoint that read a 24 MB body whole would
 * die with a 500 (with display_errors on, PHP keeps the 200 and prints the fatal error).
 */
final class PlainExamplesTest extends TestCase
{
    private const MAX_BODY = 1_048_576;

    private static ?Loopback $controller = null;

    /** @var array<string, array{process: resource, port: int, log: string}> one php -S per controller scenario */
    private static array $servers = [];

    /** @var list<string> the ids delivered, whose deduplication marks the receiver may have left */
    private static array $deliveredIds = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$deliveredIds as $id) {
            $seen = sys_get_temp_dir() . '/voltkit-webhook-' . hash('sha256', $id);   // the example's deduplication mark
            if (is_file($seen)) {
                unlink($seen);
            }
        }
        self::$deliveredIds = [];
        foreach (self::$servers as $server) {
            proc_terminate($server['process']);
            proc_close($server['process']);
            unlink($server['log']);
        }
        self::$servers = [];
        self::$controller?->stop();
        self::$controller = null;
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteTokens(): iterable
    {
        yield 'an object without token or wsUrl' => ['ok'];
        yield 'token and wsUrl empty' => ['token-empty'];
        yield 'no wsUrl' => ['token-no-ws'];
    }

    #[Test]
    #[DataProvider('incompleteTokens')]
    public function theTokenEndpointAnswers502ForAnIncompleteToken(string $scenario): void
    {
        [$status, $body] = self::askForToken($scenario, '{"roomId":"show-42"}');
        self::assertSame(502, $status, $body);
        self::assertSame(['error' => 'The RTC service returned an incomplete token; try again'], json_decode($body, true));
    }

    #[Test]
    public function anIncompleteTokenIsLoggedByRoomAndNeverWithTheReply(): void
    {
        [$status] = self::askForToken('token-no-ws', '{"roomId":"show-42"}');
        self::assertSame(502, $status);
        $log = (string) file_get_contents(self::server('token-no-ws')['log']);
        self::assertStringContainsString('"show-42"', $log);
        self::assertStringNotContainsString('tok_never_logged', $log);
    }

    #[Test]
    public function aCompleteTokenIsHandedOn(): void
    {
        [$status, $body] = self::askForToken('token', '{"roomId":"show-42"}');
        self::assertSame(200, $status, $body);
        self::assertSame(
            ['token' => 'tok', 'wsUrl' => 'wss://x.test/ws', 'expiresAt' => 1787000600, 'role' => 'viewer'],
            json_decode($body, true),
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function framings(): iterable
    {
        yield 'with a Content-Length' => [false];
        yield 'chunked' => [true];
    }

    #[Test]
    #[DataProvider('framings')]
    public function theTokenEndpointAnswers413ForABodyOver1Mb(bool $chunked): void
    {
        [$status, $body] = self::askForToken('token', self::tokenRequest(self::MAX_BODY + 1), $chunked);
        self::assertSame(413, $status, $body);
        self::assertSame(['error' => 'The request body is over 1 MB'], json_decode($body, true));
    }

    #[Test]
    public function theTokenEndpointTakesABodyOfExactly1Mb(): void
    {
        [$status, $body] = self::askForToken('token', self::tokenRequest(self::MAX_BODY), true);
        self::assertSame(200, $status, $body);
    }

    #[Test]
    public function theTokenEndpointNeverReadsA24MbBodyWhole(): void
    {
        [$status, $body] = self::askForToken('token', self::tokenRequest(24 * self::MAX_BODY), true);
        self::assertSame(413, $status, $body);
    }

    #[Test]
    #[DataProvider('framings')]
    public function theWebhookReceiverAnswers413ForABodyOver1Mb(bool $chunked): void
    {
        [$status] = self::deliver(self::delivery(self::MAX_BODY + 1), 'whsec_test', $chunked);
        self::assertSame(413, $status);
    }

    #[Test]
    public function theWebhookReceiverTakesASignedDeliveryOfExactly1Mb(): void
    {
        [$status, $body] = self::deliver(self::delivery(self::MAX_BODY), 'whsec_test', true);
        self::assertSame(200, $status, $body);
    }

    #[Test]
    public function theWebhookReceiverNeverReadsA24MbBodyWhole(): void
    {
        [$status, $body] = self::deliver(self::delivery(24 * self::MAX_BODY), 'whsec_test', true);
        self::assertSame(413, $status, $body);
    }

    #[Test]
    public function theWebhookReceiverAnswers401ForAnotherSecret(): void
    {
        [$status] = self::deliver(self::delivery(256), 'whsec_other', false);
        self::assertSame(401, $status);
    }

    /** {"roomId":"show-42","pad":"xx..."}, exactly $size bytes. */
    private static function tokenRequest(int $size): string
    {
        $head = '{"roomId":"show-42","pad":"';
        return $head . str_repeat('x', $size - strlen($head) - 2) . '"}';
    }

    /** A webhook.test event of exactly $size bytes with an id of its own, padded in its data. */
    private static function delivery(int $size): string
    {
        $id = self::$deliveredIds[] = 'evt_' . bin2hex(random_bytes(8));
        $head = '{"id":"' . $id . '","type":"webhook.test","tenantId":"t","createdAt":1787000000000,"data":{"pad":"';
        return $head . str_repeat('x', $size - strlen($head) - 3) . '"}}';
    }

    /** @return array{int, string} */
    private static function askForToken(string $scenario, string $body, bool $chunked = false): array
    {
        $headers = ['Content-Type' => 'application/json', 'X-Demo-User' => '7'];
        return self::post($scenario, 'token-server.php', $body, $headers, $chunked);
    }

    /** @return array{int, string} */
    private static function deliver(string $body, string $secret, bool $chunked): array
    {
        $t = (string) time();
        $headers = ['Content-Type' => 'application/json', 'X-Stream-Signature' => "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret)];
        return self::post('token', 'webhook-receiver.php', $body, $headers, $chunked);
    }

    /**
     * One POST to a served example. A chunked body goes without a Content-Length, so only the example's own read
     * can bound it.
     *
     * @param array<string, string> $headers
     * @return array{int, string} the status and the body
     */
    private static function post(string $scenario, string $path, string $body, array $headers, bool $chunked): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::server($scenario)['port'], $errno, $error, 5);
        self::assertNotFalse($socket, (string) $error);
        stream_set_timeout($socket, 30);
        $head = "POST /$path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $head .= "$name: $value\r\n";
        }
        fwrite($socket, $head . ($chunked ? "Transfer-Encoding: chunked\r\n\r\n" : 'Content-Length: ' . strlen($body) . "\r\n\r\n"));
        if ($chunked) {
            foreach (str_split($body, 65_536) as $chunk) {
                fwrite($socket, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
            }
            fwrite($socket, "0\r\n\r\n");
        } else {
            fwrite($socket, $body);
        }
        $response = (string) stream_get_contents($socket);
        fclose($socket);
        $parts = explode("\r\n\r\n", $response, 2);
        self::assertCount(2, $parts, "no HTTP answer: $response");
        return [(int) (explode(' ', $parts[0])[1] ?? '0'), $parts[1]];
    }

    /**
     * examples/plain under `php -S`, its client's base URL the controller's $scenario, started once per scenario.
     *
     * @return array{process: resource, port: int, log: string}
     */
    private static function server(string $scenario): array
    {
        if (isset(self::$servers[$scenario])) {
            return self::$servers[$scenario];
        }
        self::$controller ??= Loopback::start();
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $log = tempnam(sys_get_temp_dir(), 'voltkit-example-');
        self::assertIsString($log);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'memory_limit=16M', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2) . '/examples/plain'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            [
                'VOLTKIT_RTC_URL' => self::$controller->baseUrl($scenario),
                'VOLTKIT_RTC_SECRET' => 'sk_test_fake',
                'VOLTKIT_RTC_WEBHOOK_SECRETS' => 'whsec_test',
                'TMPDIR' => sys_get_temp_dir(),
            ],
        );
        self::assertIsResource($process);
        $deadline = microtime(true) + 5;
        while (($socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.2)) === false) {
            if (microtime(true) > $deadline) {
                self::fail('php -S did not start: ' . file_get_contents($log));
            }
            usleep(20_000);
        }
        fclose($socket);
        return self::$servers[$scenario] = ['process' => $process, 'port' => $port, 'log' => $log];
    }
}
