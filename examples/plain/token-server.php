<?php

/**
 * A token endpoint: POST {"roomId": "..."} answers a room token for the signed-in user.
 *
 *   VOLTKIT_RTC_URL=... VOLTKIT_RTC_SECRET=... php -S 127.0.0.1:8899 -t examples/plain
 *   curl -X POST http://127.0.0.1:8899/token-server.php -H 'X-Demo-User: 7' -d '{"roomId":"show-42"}'
 *
 * The identity comes from your session, never from the request body: a token would otherwise be minted for
 * whoever the caller claims to be. The X-Demo-User header stands in for that session here (see currentUserId).
 * Your backend decides the role; the token carries it and the client cannot change it.
 */

declare(strict_types=1);

use VoltKit\Rtc\Role;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;

foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

// One client for the process; in an application, make it once at startup and share it.
$rtc = StreamClient::fromEnv();

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 */
$answer = static function (int $status, array $body, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    foreach ($headers as $name => $value) {
        assert(is_string($value));
        header("$name: $value");
    }
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
};

/**
 * Who is asking: a stand-in for your authentication. In your application this reads the session or the bearer
 * token your framework verified; a demo header is the one thing it must never be in production.
 */
$currentUserId = static function (): ?string {
    $demo = $_SERVER['HTTP_X_DEMO_USER'] ?? null;
    return is_string($demo) && $demo !== '' ? $demo : null;
};

/**
 * Your rule for who hosts a room: look the show up and compare its owner with the user. The placeholder makes
 * the user whose id the room id ends with the host, which is fine for a demo and for nothing else.
 */
$isHostOf = static fn (string $roomId, string $userId): bool => str_ends_with($roomId, $userId);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $answer(405, ['error' => 'POST a JSON body: {"roomId": "..."}']);
}
$userId = $currentUserId();
if ($userId === null) {
    $answer(401, ['error' => 'Sign in first']);
}
// A token request is a few bytes: never read more than 1 MB and one byte, with a Content-Length or chunked.
$maxBody = 1_048_576;
$raw = (string) file_get_contents('php://input', false, null, 0, $maxBody + 1);
if (strlen($raw) > $maxBody) {
    $answer(413, ['error' => 'The request body is over 1 MB']);
}
$input = json_decode($raw, true);
$roomId = is_array($input) && is_string($input['roomId'] ?? null) ? $input['roomId'] : '';
if ($roomId === '') {
    $answer(400, ['error' => 'roomId is required']);
}

$role = $isHostOf($roomId, $userId) ? Role::Host : Role::Viewer;

try {
    $token = $rtc->createToken($roomId, "user-$userId", $role);
} catch (StreamError $e) {
    // Safe to log whole: the API secret is never in it.
    error_log('createToken failed: ' . $e->getErrorCode() . ' ' . $e->getMessage());
    if ($e->isNotFound()) {
        $answer(404, ['error' => 'This show has ended']);
    }
    if ($e->isOutOfMinutes()) {
        $answer(402, ['error' => 'This show cannot take viewers right now']);
    }
    if ($e->getErrorCode() === 'viewer-quota') {
        $answer(429, ['error' => 'This show is full']);
    }
    if ($e->isRetryable()) {
        $answer(503, ['error' => 'Try again in a moment'], ['Retry-After' => '10']);
    }
    if ($e->getStatus() >= 200 && $e->getStatus() < 300) {
        // A 2xx that is no token (the SDK refuses one without a token, and one that is not JSON): something other
        // than the RTC service answered, or it misbehaved.
        $answer(502, ['error' => 'The RTC service returned an incomplete token; try again']);
    }
    $answer(500, ['error' => 'Something went wrong']);   // unauthorized, bad-role, ...: a problem on this side
}
if (($token['wsUrl'] ?? '') === '') {
    // The SDK checks the token; without a wsUrl there is still nothing to connect to. Logged by room, never the
    // reply: it holds a token.
    error_log('createToken returned no wsUrl for room ' . json_encode($roomId, JSON_THROW_ON_ERROR));
    $answer(502, ['error' => 'The RTC service returned an incomplete token; try again']);
}

$answer(200, [
    'token' => $token['token'],
    'wsUrl' => $token['wsUrl'],
    'expiresAt' => $token['expiresAt'],
    'role' => $token['role'],
]);
