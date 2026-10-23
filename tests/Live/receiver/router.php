<?php

/**
 * The live smoke's webhook receiver and rates stub, served by `php -S 127.0.0.1:18899 router.php`
 * (scripts/test/php-sdk-live.sh). Not part of the PHPUnit suite.
 *
 *   POST /hook                               a webhook delivery: verified with Webhooks::parse against the
 *                                            secrets in $RUN/secrets.json, recorded to $RUN/received.jsonl
 *                                            (header, raw body, which secrets verified it), 200 or 401
 *   GET /api/v1/billing/rtc/rates/current    the website API's envelope, for getRates()
 */

declare(strict_types=1);

use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Webhooks;

require __DIR__ . '/../../../vendor/autoload.php';

$run = getenv('PHP_SDK_LIVE_RUN');
if (!is_string($run) || $run === '') {
    http_response_code(500);
    exit('PHP_SDK_LIVE_RUN is not set');
}
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

if ($path === '/api/v1/billing/rtc/rates/current') {
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=30');
    echo json_encode([
        'success' => true,
        'data' => [
            'version' => 3,
            'kind' => 'standard',
            'effectiveAt' => '2026-10-13T00:00:00.000Z',
            'unit' => ['price' => 'credits per 1,000 participant-minutes', 'rate' => 'cu per participant-second', 'cuPerCredit' => '6000000'],
            'tiers' => [['id' => 'audio_std', 'rank' => 1, 'label' => 'Standard audio', 'media' => 'Opus', 'credits' => '39.00', 'cuPerParticipantSecond' => '3900', 'base' => '39.00', 'surcharge' => null, 'available' => true]],
            'surcharges' => [],
            'idleTier' => 'audio_std',
            'modes' => [],
            'conversion' => ['creditsPerRupee' => '1.0000', 'numerator' => '1', 'denominator' => '1', 'inrPerCredit' => '1.0000', 'version' => null, 'effectiveAt' => null, 'source' => 'default'],
            'notes' => [],
        ],
    ], JSON_THROW_ON_ERROR);
    return;
}

if ($path === '/hook' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $rawBody = (string) file_get_contents('php://input');
    $header = $_SERVER['HTTP_X_STREAM_SIGNATURE'] ?? null;
    $secrets = json_decode((string) file_get_contents("$run/secrets.json"), true);
    $secrets = is_array($secrets) ? array_values(array_filter($secrets, 'is_string')) : [];
    $verifiedWith = [];
    foreach ($secrets as $i => $secret) {
        if (Webhooks::verify($rawBody, is_string($header) ? $header : null, $secret)) {
            $verifiedWith[] = $i;
        }
    }
    $ok = false;
    $event = null;
    if ($secrets !== []) {
        try {
            $event = Webhooks::parse($rawBody, is_string($header) ? $header : null, $secrets);
            $ok = true;
        } catch (StreamError) {
            $ok = false;
        }
    }
    file_put_contents("$run/received.jsonl", json_encode([
        'header' => $header,
        'headers' => ['user-agent' => $_SERVER['HTTP_USER_AGENT'] ?? null, 'x-stream-event' => $_SERVER['HTTP_X_STREAM_EVENT'] ?? null, 'x-stream-delivery' => $_SERVER['HTTP_X_STREAM_DELIVERY'] ?? null, 'x-stream-attempt' => $_SERVER['HTTP_X_STREAM_ATTEMPT'] ?? null],
        'body' => $rawBody,
        'verifiedWith' => $verifiedWith,
        'ok' => $ok,
        'type' => is_array($event) ? ($event['type'] ?? null) : null,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    http_response_code($ok ? 200 : 401);
    return;
}

http_response_code(404);
