<?php

/**
 * A webhook receiver: the endpoint to configure in the dashboard.
 *
 *   VOLTKIT_RTC_WEBHOOK_SECRETS=whsec_current[,whsec_previous] php -S 127.0.0.1:8899 -t examples/plain
 *
 * Reads the RAW body, verifies it with every secret given (two during a rotation), answers 401 when the
 * signature does not verify so VoltKit retries, 200 at once otherwise, deduplicates on the event id,
 * and only then does the work. Delivery is at-least-once and unordered.
 */

declare(strict_types=1);

use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Webhooks;

foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

$secrets = array_values(array_filter(array_map('trim', explode(',', (string) getenv('VOLTKIT_RTC_WEBHOOK_SECRETS')))));
if ($secrets === []) {
    http_response_code(500);
    exit('VOLTKIT_RTC_WEBHOOK_SECRETS is not set');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}

// The bytes as received (the signature covers them), and never more than 1 MB: a delivery is a few KB.
$rawBody = (string) file_get_contents('php://input', false, null, 0, 1_048_577);
if (strlen($rawBody) > 1_048_576) {
    http_response_code(413);
    exit;
}
$header = $_SERVER['HTTP_X_STREAM_SIGNATURE'] ?? null;

try {
    $event = Webhooks::parse($rawBody, is_string($header) ? $header : null, $secrets);
} catch (StreamError $e) {
    http_response_code(401);                                    // bad-signature: not from VoltKit, or too old
    exit;
}

// Answer first: a slow handler is retried, and your latency becomes duplicate deliveries.
http_response_code(200);
header('Content-Length: 0');
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

// Deduplicate on the event id. A file stands in for your database here.
$seen = sys_get_temp_dir() . '/voltkit-webhook-' . hash('sha256', $event['id']);
if (file_exists($seen)) {
    exit;                                                        // delivered before: nothing to do
}
touch($seen);

// The work. $event is {id, type, tenantId, roomId?, createdAt, data?}; the event types are documented at
// https://voltkit.in/docs/webhooks.
switch ($event['type']) {
    case 'room.ended':
        error_log(sprintf('room %s ended: %s', $event['roomId'] ?? '?', json_encode($event['data'] ?? [])));
        break;
    case 'usage.limit_reached':
        error_log('usage limit reached: tell the project owner to recharge');
        break;
    default:
        error_log('event ' . $event['type'] . ' ' . $event['id']);
}
