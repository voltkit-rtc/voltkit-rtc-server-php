<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Webhooks;

/** POST /api/hooks/voltkit: the endpoint configured in the dashboard. */
final class VoltKitWebhookController extends Controller
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function __invoke(Request $request): Response
    {
        // The current secret, and the previous one during a rotation: "whsec_new,whsec_old" in the .env file.
        $configured = $this->config->get('services.voltkit.webhook_secrets');
        $secrets = array_values(array_filter(
            array_map('trim', explode(',', is_string($configured) ? $configured : '')),
        ));

        // The RAW body: the signature covers the bytes as sent. $request->all() would decode and re-encode them.
        $rawBody = $request->getContent();
        $header = $request->header('X-Stream-Signature');
        try {
            $event = Webhooks::parse(
                is_string($rawBody) ? $rawBody : '',
                is_string($header) ? $header : null,
                $secrets,
            );
        } catch (StreamError) {
            return new Response('', 401);   // bad-signature: not from VoltKit, or too old; VoltKit retries
        }

        // Deduplicate on the event id (delivery is at-least-once); then do the work, ideally on a queue so the
        // answer goes out at once. Cache::add is atomic: it returns false when the key was already there.
        if (Cache::add('voltkit-webhook:' . $event['id'], true, 86400)) {
            Log::info('voltkit event', [
                'type' => $event['type'],
                'id' => $event['id'],
                'roomId' => $event['roomId'] ?? null,
            ]);
            // dispatch(new HandleVoltKitEvent($event));
        }
        return new Response('', 200);
    }
}
