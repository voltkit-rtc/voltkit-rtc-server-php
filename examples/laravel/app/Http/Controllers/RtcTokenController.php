<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use VoltKit\Rtc\Role;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;

/**
 * POST /api/rtc/token {"roomId": "..."}: a room token for the signed-in user, with the role this backend decides.
 *
 * The identity comes from the session (`auth:sanctum` on the route), never from the request body: a token is
 * minted for whoever the caller claims to be otherwise. The role comes from your own data, never from the client.
 */
final class RtcTokenController extends Controller
{
    public function __construct(private readonly StreamClient $rtc)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Authenticatable) {
            return new JsonResponse(['error' => 'Sign in first'], 401);
        }
        $roomId = $request->input('roomId');
        if (!is_string($roomId) || $roomId === '') {
            return new JsonResponse(['error' => 'roomId is required'], 400);
        }
        $userId = self::identifierOf($user);
        $identity = "user-$userId";
        $role = $this->isHostOf($roomId, $userId) ? Role::Host : Role::Viewer;

        try {
            $token = $this->rtc->createToken($roomId, $identity, $role);
        } catch (StreamError $e) {
            Log::warning('createToken failed', ['code' => $e->getErrorCode(), 'message' => $e->getMessage()]);   // never the secret
            if ($e->isNotFound()) {
                return new JsonResponse(['error' => 'This show has ended'], 404);
            }
            if ($e->isOutOfMinutes()) {
                return new JsonResponse(['error' => 'This show cannot take viewers right now'], 402);
            }
            if ($e->getErrorCode() === 'viewer-quota') {
                return new JsonResponse(['error' => 'This show is full'], 429);
            }
            if ($e->isRetryable()) {
                return new JsonResponse(['error' => 'Try again in a moment'], 503, ['Retry-After' => '10']);
            }
            if ($e->getStatus() >= 200 && $e->getStatus() < 300) {
                // A 2xx that is no token (the SDK refuses one without a token, and one that is not JSON).
                return new JsonResponse(['error' => 'The RTC service returned an incomplete token; try again'], 502);
            }
            return new JsonResponse(['error' => 'Something went wrong'], 500);   // unauthorized, bad-role, ...: a problem on this side
        }
        if (($token['wsUrl'] ?? '') === '') {
            // The SDK checks the token; without a wsUrl there is still nothing to connect to. Logged by room, never
            // the reply: it holds a token.
            Log::error('createToken returned no wsUrl', ['roomId' => $roomId]);
            return new JsonResponse(['error' => 'The RTC service returned an incomplete token; try again'], 502);
        }

        return new JsonResponse([
            'token' => $token['token'],
            'wsUrl' => $token['wsUrl'],
            'expiresAt' => $token['expiresAt'],
            'role' => $token['role'],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** The user's primary key as a string: an auto-increment id or a UUID. */
    private static function identifierOf(Authenticatable $user): string
    {
        $id = $user->getAuthIdentifier();
        if (!is_int($id) && !is_string($id)) {
            throw new \LogicException('the user has no usable identifier');
        }
        return (string) $id;
    }

    /**
     * Your rule for who hosts a room: look the show up and compare its owner with the user. The placeholder makes
     * the user whose id the room id ends with the host, which is fine for a demo and for nothing else.
     */
    private function isHostOf(string $roomId, string $userId): bool
    {
        // return Show::where('room_id', $roomId)->where('owner_id', $userId)->exists();
        return str_ends_with($roomId, $userId);
    }
}
