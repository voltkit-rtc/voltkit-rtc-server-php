<?php

/**
 * The live smoke (spec 11, item 5), run by scripts/test/php-sdk-live.sh against a real controller,
 * media node and Redis: every answer asserted, in the spec's order. Not part of the PHPUnit suite.
 *
 * Environment: ORCH_URL, ADMIN_TOKEN, TENANT, RECEIVER_URL (the webhook receiver, which verifies with
 * this SDK and records to RUN_DIR/received.jsonl, reading its secrets from RUN_DIR/secrets.json),
 * RATES_URL (the rates stub), RUN_DIR. Prints PASS/FAIL lines; exits 1 when anything failed.
 */

declare(strict_types=1);

use VoltKit\Rtc\Role;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Live\CountingTransport;
use VoltKit\Rtc\Transport\CurlTransport;
use VoltKit\Rtc\Transport\Request;
use VoltKit\Rtc\Webhooks;

require __DIR__ . '/../../vendor/autoload.php';

$env = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "$name is not set\n");
        exit(2);
    }
    return $value;
};
$orchUrl = $env('ORCH_URL');
$adminToken = $env('ADMIN_TOKEN');
$tenant = $env('TENANT');
$receiverUrl = $env('RECEIVER_URL');
$ratesUrl = $env('RATES_URL');
$runDir = $env('RUN_DIR');

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $what, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  \033[32mPASS\033[0m $what\n";
    } else {
        $failed++;
        echo "  \033[31mFAIL\033[0m $what $detail\n";
    }
};
$step = static fn (string $s) => print("\n\033[1m$s\033[0m\n");

/** JSON for a FAIL line's detail. */
$j = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
/** A value as a string, or '' when it is not one. */
$s = static fn (mixed $value): string => is_string($value) ? $value : '';
/** A nested value of decoded JSON, or null. */
$dig = static function (mixed $value, string ...$path): mixed {
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }
    return $value;
};

/** The admin API, with the controller's own transport; nothing here goes through the SDK's key path. */
/**
 * @param array<string, mixed>|null $body
 * @return array{status: int, body: array<string, mixed>}
 */
$admin = static function (string $method, string $path, ?array $body = null) use ($orchUrl, $adminToken): array {
    $headers = ['X-API-Key' => $adminToken];
    $encoded = null;
    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
    }
    $response = (new CurlTransport())->send(new Request($method, $orchUrl . $path, $headers, $encoded, 10000, false));
    $decoded = $response->body === '' ? [] : json_decode($response->body, true);
    return ['status' => $response->status, 'body' => is_array($decoded) ? $decoded : []];
};

/** The error a call failed with, or null when it resolved. */
$failure = static function (callable $call): ?StreamError {
    try {
        $call();
        return null;
    } catch (StreamError $e) {
        return $e;
    }
};

/** @return list<array<string, mixed>> */
$received = static function () use ($runDir): array {
    $text = (string) file_get_contents("$runDir/received.jsonl");
    $out = [];
    foreach (array_filter(explode("\n", $text)) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }
    return $out;
};
$until = static function (string $what, callable $cond, float $seconds = 20.0) use ($check): bool {
    $deadline = microtime(true) + $seconds;
    while (!$cond()) {
        if (microtime(true) > $deadline) {
            $check(false, "waiting for $what", 'timed out');
            return false;
        }
        usleep(100_000);
    }
    return true;
};
/** @param list<string> $secrets */
$setSecrets = static function (array $secrets) use ($runDir): void {
    file_put_contents("$runDir/secrets.json", json_encode(array_values($secrets), JSON_THROW_ON_ERROR));
};

try {
    $step('a project and its API key, through the admin API');
    $created = $admin('POST', '/admin/tenants', ['tenantId' => $tenant, 'name' => 'PHP SDK live smoke', 'plan' => 'unlimited']);
    $check(in_array($created['status'], [200, 201], true), 'tenant created', $j($created));
    $key = $s($dig($admin('POST', "/admin/tenants/$tenant/keys", ['label' => 'php-live']), 'body', 'key', 'secret'));
    $check(str_starts_with($key, 'sk_'), 'API key issued');

    $counting = new CountingTransport();
    $rtc = new StreamClient($orchUrl, $key, $ratesUrl, 10000, 2, $counting);
    $attempts = static fn (): int => $counting->take();
    $roomId = 'php-live-room';

    $step('rooms');
    $rooms = $rtc->listRooms();
    $check(($rooms['rooms'] ?? null) === [], 'listRooms: empty', $j($rooms));
    // PHP011-4 (0.1.2): a baseUrl read from a file with its line feed, and a slash, works against the real controller.
    $fromFile = (new StreamClient("$orchUrl/\n", $key, null, 10000, 2, new CountingTransport()))->listRooms();
    $check(($fromFile['rooms'] ?? null) === [], 'a baseUrl with a slash and a line feed after it: listRooms works', $j($fromFile));

    $room = $rtc->ensureRoom($roomId, 'host-1', ['seats' => 8]);
    $check($room['existed'] === false && $room['roomId'] === $roomId && is_string($room['hostToken']) && is_string($room['wsUrl']), 'ensureRoom: 201, existed false', $j($room));
    $again = $rtc->ensureRoom($roomId, 'host-1');
    $check($again['existed'] === true && is_string($again['hostToken']), 'ensureRoom again: 200, existed true, a fresh host token');

    $attempts();
    $e = $failure(static fn () => $rtc->createRoom('host-1', $roomId));
    $check($e !== null && $e->getErrorCode() === 'room-exists' && $e->getStatus() === 409 && !$e->isRetryable(), 'createRoom same id: room-exists 409', (string) $e?->getMessage());
    $check($attempts() === 1, 'createRoom: one attempt, not retried');

    $viewer = $rtc->createToken($roomId, 'viewer-1', Role::Viewer);
    $host = $rtc->createToken($roomId, 'host-1', 'host');
    $check($viewer['role'] === 'viewer' && $host['role'] === 'host' && is_string($viewer['token']) && $viewer['roomId'] === $roomId, 'createToken: viewer and host');

    $view = $rtc->getRoom($roomId);
    $check(($view['room']['participants'] ?? null) === 0 && $view['participants'] === [], 'getRoom: 0 participants', $j($view));
    $check($rtc->listParticipants($roomId) === [], 'listParticipants: []');

    $step('chat');
    $chat = $rtc->createChatToken($roomId, 'audience-1');
    $check($chat['role'] === 'viewer' && str_ends_with($chat['wsUrl'], '/ws/chat') && $chat['channel'] === $roomId, 'createChatToken: viewer, wsUrl ends in /ws/chat', $j($chat));
    $info = $rtc->getChatInfo($roomId);
    $check($info['channel'] === $roomId && $info['members'] === 0, 'getChatInfo: 0 members', $j($info));
    $sent = $rtc->sendMessage($roomId, 'hello from the PHP SDK', 'system', metadata: ['smoke' => true]);
    $check(($sent['text'] ?? null) === 'hello from the PHP SDK' && $sent['from'] === 'system', 'sendMessage: 201, the stored message', $j($sent));
    $messages = $rtc->listMessages($roomId);
    $check(count($messages) === 1 && $messages[0]['id'] === $sent['id'], 'listMessages: holds it, oldest first', $j($messages));
    $rtc->deleteMessage($roomId, $sent['id']);
    $check(true, 'deleteMessage');
    $attempts();
    $e = $failure(static fn () => $rtc->deleteMessage($roomId, $sent['id']));
    $check($e !== null && $e->getErrorCode() === 'no-message' && $e->isNotFound() && $attempts() === 1, 'deleteMessage again: no-message on a first attempt, not retried', (string) $e?->getMessage());
    $rtc->muteChat($roomId, 'audience-1');
    $check($rtc->listChatMutes($roomId) === ['audience-1'], 'muteChat then listChatMutes: holds it');
    $rtc->unmuteChat($roomId, 'audience-1');
    $check($rtc->listChatMutes($roomId) === [], 'unmuteChat: gone');

    $step('the plain examples, served by php -S against this controller');
    $examplesPort = 8899;
    $exampleSecret = 'whsec_' . bin2hex(random_bytes(32));
    $serve = [PHP_BINARY, '-S', "127.0.0.1:$examplesPort", '-t', __DIR__ . '/../../examples/plain'];
    if ((getenv('PHP_SDK_LIVE_MEMCAP') ?: '1') === '1' && is_executable('/usr/bin/systemd-run')) {
        $serve = ['systemd-run', '--user', '--scope', '--quiet', '-p', 'MemoryMax=300M', '-p', 'MemorySwapMax=0', ...$serve];
    }
    $exampleEnv = getenv() + ['VOLTKIT_RTC_URL' => $orchUrl, 'VOLTKIT_RTC_SECRET' => $key, 'VOLTKIT_RTC_WEBHOOK_SECRETS' => "$exampleSecret,whsec_previous_placeholder"];
    $examples = proc_open($serve, [0 => ['pipe', 'r'], 1 => ['file', "$runDir/examples.log", 'a'], 2 => ['file', "$runDir/examples.log", 'a']], $examplePipes, null, $exampleEnv);
    $http = new CurlTransport();
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    $post = static function (string $path, string $body, array $headers = []) use ($http, $examplesPort): array {
        $all = ['Content-Type' => 'application/json'];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $all[$name] = $value;
            }
        }
        $response = $http->send(new Request('POST', "http://127.0.0.1:$examplesPort$path", $all, $body, 10000, false));
        $decoded = $response->body === '' ? [] : json_decode($response->body, true);
        return ['status' => $response->status, 'body' => is_array($decoded) ? $decoded : [], 'headers' => $response->headers];
    };
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) {
        try {
            $up = $http->send(new Request('GET', "http://127.0.0.1:$examplesPort/token-server.php", [], null, 2000, false))->status === 405;
        } catch (\Throwable) {
            usleep(100_000);
        }
    }
    $check($up, 'php -S serves examples/plain');
    if ($up) {
        $viewer = $post('/token-server.php', $j(['roomId' => $roomId]), ['X-Demo-User' => 'viewer-9']);
        $check($viewer['status'] === 200 && ($viewer['body']['role'] ?? null) === 'viewer' && is_string($viewer['body']['token'] ?? null) && ($viewer['headers']['cache-control'] ?? null) === 'no-store', 'token-server.php: a viewer token, Cache-Control no-store', $j($viewer));
        $host = $post('/token-server.php', $j(['roomId' => $roomId]), ['X-Demo-User' => 'room']);
        $check($host['status'] === 200 && ($host['body']['role'] ?? null) === 'host', 'token-server.php: the placeholder rule makes the owner a host', $j($host['body']));
        $gone = $post('/token-server.php', $j(['roomId' => 'php-live-missing']), ['X-Demo-User' => 'viewer-9']);
        $check($gone['status'] === 404, 'token-server.php: no-room is answered 404', $j($gone));
        $bad = $post('/token-server.php', $j(['roomId' => '']), ['X-Demo-User' => 'viewer-9']);
        $check($bad['status'] === 400, 'token-server.php: a missing field is 400', $j($bad));
        $anonymous = $post('/token-server.php', $j(['roomId' => $roomId]));
        $check($anonymous['status'] === 401, 'token-server.php: no signed-in user is 401, whatever the body says', $j($anonymous));

        $deliveryId = 'evt_example_' . bin2hex(random_bytes(6));
        $eventBody = $j(['id' => $deliveryId, 'type' => 'room.ended', 'tenantId' => $tenant, 'roomId' => $roomId, 'createdAt' => (int) (microtime(true) * 1000), 'data' => ['reason' => 'host-left']]);
        $ts = time();
        $signature = "t=$ts,v1=" . hash_hmac('sha256', "$ts.$eventBody", $exampleSecret);
        $accepted = $post('/webhook-receiver.php', $eventBody, ['X-Stream-Signature' => $signature]);
        $check($accepted['status'] === 200, 'webhook-receiver.php: a signed delivery is answered 200', $j($accepted));
        $again = $post('/webhook-receiver.php', $eventBody, ['X-Stream-Signature' => $signature]);
        $check($again['status'] === 200, 'webhook-receiver.php: a redelivery is answered 200 too (deduplicated on id)', $j($again));
        $tampered = $post('/webhook-receiver.php', $eventBody . ' ', ['X-Stream-Signature' => $signature]);
        $check($tampered['status'] === 401, 'webhook-receiver.php: a tampered body is answered 401', $j($tampered));
        $unsigned = $post('/webhook-receiver.php', $eventBody);
        $check($unsigned['status'] === 401, 'webhook-receiver.php: no signature is answered 401', $j($unsigned));
        $old = time() - 3600;
        $stale = $post('/webhook-receiver.php', $eventBody, ['X-Stream-Signature' => "t=$old,v1=" . hash_hmac('sha256', "$old.$eventBody", $exampleSecret)]);
        $check($stale['status'] === 401, 'webhook-receiver.php: an hour-old delivery is answered 401', $j($stale));
    }
    if (is_resource($examples)) {
        $pid = (int) proc_get_status($examples)['pid'];
        exec("pkill -TERM -P $pid 2>/dev/null");
        proc_terminate($examples, 15);
        usleep(200_000);
        exec("pkill -KILL -P $pid 2>/dev/null");
        proc_close($examples);
    }

    $step('usage and balance');
    $usage = $rtc->getUsage();
    $check(($usage['today']['roomsCreated'] ?? null) === 1 && $usage['tenantId'] === $tenant, 'getUsage: roomsCreated 1 today', $j($usage['today'] ?? null));
    $days = array_column($rtc->getUsageSeries(7), 'day');
    $check(count($days) === 7 && $days[6] === gmdate('Y-m-d'), 'getUsageSeries(7): 7 entries, the last today', $j($days));
    $attempts();
    $e = $failure(static fn () => $rtc->getBalance());
    if ($e === null) {
        $check(true, 'getBalance: wallet billing is wired on this controller');
    } else {
        $check($e->isBillingUnavailable() && $e->getStatus() === 503 && !$e->isRetryable() && $attempts() === 1, 'getBalance: billing-unavailable on a controller without wallet billing, one attempt', $e->getMessage());
    }

    $step('streams');
    $attempts();
    $e = $failure(static fn () => $rtc->listStreams());
    if ($e === null) {
        $live = $rtc->createStream('php-live-stream', 'public');
        $check(isset($live['publish']['whipUrl']), 'createStream: publish URL');
        $check($rtc->getStream('php-live-stream')['streamId'] === 'php-live-stream', 'getStream');
        $check($rtc->updateStream('php-live-stream', 'token')['playback'] === 'token', 'updateStream');
        $check(isset($rtc->createStreamToken('php-live-stream', 'play', 'viewer-1')['llhls']), 'createStreamToken play');
        $check(isset($rtc->createStreamToken('php-live-stream', 'publish')['whipUrl']), 'createStreamToken publish');
        $rtc->deleteStream('php-live-stream');
        $check(true, 'deleteStream');
    } else {
        $check($e->isBroadcastDisabled() && $e->isNotFound() && $e->getStatus() === 404 && !$e->isRetryable() && $attempts() === 1, 'listStreams: broadcast-disabled on a controller without OME, one attempt', $e->getMessage());
    }

    $step('a wrong key');
    $wrongCounting = new CountingTransport();
    $wrong = new StreamClient($orchUrl, 'sk_not_a_real_key_0123456789abcdef', null, 10000, 2, $wrongCounting);
    $e = $failure(static fn () => $wrong->listRooms());
    $check($e !== null && $e->getErrorCode() === 'unauthorized' && $e->getStatus() === 401 && !$e->isRetryable(), 'wrong key: unauthorized 401', (string) $e?->getMessage());
    $check($wrongCounting->sent === 1, 'wrong key: one attempt');
    $check($e !== null && !str_contains($e->getMessage(), 'sk_not_a_real_key'), 'wrong key: the key is not in the message');

    $step('deletes');
    $rtc->deleteRoom($roomId);
    $check(true, 'deleteRoom');
    $e = $failure(static fn () => $rtc->getRoom($roomId));
    $check($e !== null && $e->getErrorCode() === 'no-room' && $e->isNotFound(), 'getRoom after: no-room', (string) $e?->getMessage());
    $attempts();
    $e = $failure(static fn () => $rtc->deleteRoom($roomId));
    $check($e !== null && $e->getErrorCode() === 'no-room' && $attempts() === 1, 'deleteRoom again: no-room on a first attempt rejects, one attempt', (string) $e?->getMessage());

    $step('webhooks: delivered to a receiver that verifies with this SDK');
    $put = $admin('PUT', "/admin/tenants/$tenant/webhook", ['url' => $receiverUrl, 'allEvents' => true]);
    $first = $s($dig($put, 'body', 'secret'));
    $check($put['status'] === 200 && preg_match('/^whsec_[0-9a-f]{64}$/', $first) === 1, 'a new endpoint is issued a secret', $j(['status' => $put['status'], 'keys' => array_keys($put['body'])]));
    $setSecrets([$first]);
    $check($admin('POST', "/admin/tenants/$tenant/webhook/test")['status'] === 202, 'test event queued');
    if ($until('the test event', static fn () => count($received()) >= 1)) {
        $r = $received()[0];
        $check($r['ok'] === true && $r['type'] === 'webhook.test', 'webhook.test verified by Webhooks::parse at the receiver', $j($r['headers']));
        $check($s($dig($r, 'headers', 'user-agent')) === 'VoltKit-Webhooks/1' && $s($dig($r, 'headers', 'x-stream-attempt')) === '1', 'User-Agent VoltKit-Webhooks/1, attempt 1');
        $event = Webhooks::parse($s($r['body']), $s($r['header']), $first);
        $check($event['tenantId'] === $tenant && !isset($event['roomId']) && $s($dig($r, 'headers', 'x-stream-delivery')) === $event['id'], 'the envelope: tenantId, the delivery id, no roomId', $j($event));
        $check(!Webhooks::verify($s($r['body']), $s($r['header']), 'whsec_other'), 'another secret does not verify it');
    }

    $step('rotation with an overlap: two signatures, either secret verifies');
    $rotated = $admin('POST', "/admin/tenants/$tenant/webhook/rotate", ['overlapSeconds' => 3600]);
    $second = $s($dig($rotated, 'body', 'secret'));
    $check($rotated['status'] === 200 && $second !== '' && $second !== $first, 'rotated: a new secret, the previous one kept for the overlap');
    // The receiver has not been told the new secret yet: it still holds the first.
    $before = count($received());
    $admin('POST', "/admin/tenants/$tenant/webhook/test");
    if ($until('a test event during the overlap', static fn () => count($received()) > $before)) {
        $r = $received()[$before];
        $header = $s($r['header']);
        $body = $s($r['body']);
        $check(count(array_filter(explode(',', $header), static fn (string $p) => str_starts_with(trim($p), 'v1='))) === 2, 'two v1 signatures during the overlap', $header);
        $check($r['ok'] === true && $r['verifiedWith'] === [0], 'a receiver on the old secret still verifies');
        $check(Webhooks::verify($body, $header, $second), 'and the new secret verifies the same delivery');
        $check(Webhooks::verify($body, $header, [$first, $second]), 'and so does a receiver holding both');
        $check(!Webhooks::verify($body, $header, ['whsec_other', 'whsec_nope']), 'but not a receiver with neither');
    }
    $setSecrets([$second, $first]);
    $before = count($received());
    $admin('POST', "/admin/tenants/$tenant/webhook/test");
    if ($until('a test event with both secrets at the receiver', static fn () => count($received()) > $before)) {
        $r = $received()[$before];
        $check($r['ok'] === true && $r['verifiedWith'] === [0, 1], 'the receiver holding both: both verify');
    }

    $step('rotation without overlap: only the new secret');
    $third = $s($dig($admin('POST', "/admin/tenants/$tenant/webhook/rotate", ['overlapSeconds' => 0]), 'body', 'secret'));
    $check($third !== '' && $third !== $second, 'rotated again');
    $setSecrets([$third]);
    $before = count($received());
    $admin('POST', "/admin/tenants/$tenant/webhook/test");
    if ($until('a test event after the rotation', static fn () => count($received()) > $before)) {
        $r = $received()[$before];
        $check($r['ok'] === true && count(explode(',', $s($r['header']))) === 2, 'one signature, the new secret');
        $check(!Webhooks::verify($s($r['body']), $s($r['header']), [$first, $second]), 'the old secrets no longer verify');
    }

    $step('getRates against the local stub');
    $rates = $rtc->getRates();
    $check($rates['version'] === 3 && $rates['kind'] === 'standard' && $dig($rates, 'tiers', '0', 'cuPerParticipantSecond') === '3900', 'getRates: the envelope unwrapped', $j($rates));

    $step('cleanup');
    $deleted = $admin('DELETE', "/admin/tenants/$tenant");
    $check(in_array($deleted['status'], [200, 204], true), 'tenant deleted', $j($deleted));
} catch (\Throwable $e) {
    $failed++;
    echo "  \033[31mFAIL\033[0m unexpected " . $e::class . ': ' . $e->getMessage() . "\n";
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
