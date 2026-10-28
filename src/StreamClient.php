<?php

declare(strict_types=1);

namespace VoltKit\Rtc;

use Psr\Log\LoggerInterface;
use VoltKit\Rtc\Internal\ApiKey;
use VoltKit\Rtc\Internal\Redactor;
use VoltKit\Rtc\Internal\Secrets;
use VoltKit\Rtc\Transport\CurlTransport;
use VoltKit\Rtc\Transport\Request;
use VoltKit\Rtc\Transport\TransportException;
use VoltKit\Rtc\Transport\TransportInterface;

/**
 * Server SDK for VoltKit RTC: rooms, tokens, chat, broadcast streams, usage, billing, and
 * (see Webhooks) verified webhooks. Your backend does two things: decide who may do what,
 * and hand the client a short-lived token. It is never in the media path.
 *
 * The API secret must never leave your server. It is tenant-wide: anything holding it can
 * create rooms, mint host tokens for any identity and end anyone's broadcast. It is sent to
 * one place, the controller named by $baseUrl, in the X-API-Key header, never across a
 * redirect, never to the rates host, never into an error message, a log line or a printed
 * client. Replies are the wire as decoded JSON: arrays with camelCase keys; unknown fields pass
 * through, and money (cu amounts) stays a string.
 *
 * One client is safe to share across a whole process; it holds no per-call state.
 *
 * @phpstan-type Room array{roomId: string, tenantId: string, originNode: string, state: string, hostIdentity: string, createdAt: int, nodes: list<string>, participants?: int, demo?: bool, metadata?: mixed, ...}
 * @phpstan-type RoomWithHostToken array{roomId: string, tenantId: string, originNode: string, state: string, hostIdentity: string, createdAt: int, nodes: list<string>, participants?: int, demo?: bool, metadata?: mixed, hostToken: string, wsUrl: string, expiresAt: int, existed: bool, ...}
 * @phpstan-type RoomToken array{token: string, wsUrl: string, role: string, identity: string, roomId: string, expiresAt: int, ...}
 * @phpstan-type Participant array{identity: string, role: string, nodeId: string, handRaised: bool, muted: bool, videoBlocked?: bool, joinedAt?: int, handMetadata?: mixed, kicked?: bool, metadata?: mixed, ...}
 * @phpstan-type RoomView array{room: Room, participants: list<Participant>, producers?: list<array<string, mixed>>, ...}
 * @phpstan-type ChatToken array{token: string, wsUrl: string, role: string, identity: string, channel: string, expiresAt: int, ...}
 * @phpstan-type ChatInfo array{channel: string, members: int, ...}
 * @phpstan-type ChatMessage array{id: string, channel: string, from: string, to?: string, type: string, text?: string, metadata?: mixed, clientMsgId?: string, ts: int, ...}
 * @phpstan-type PlaybackUrls array{llhls: string, webrtc: string, token?: string, expiresAt?: int, ...}
 * @phpstan-type BroadcastStream array{streamId: string, tenantId: string, streamKey: string, state: string, playback: string, createdAt: int, startedAt?: int, endedAt?: int, viewers: int, peakViewers: int, metadata?: mixed, playbackUrls: PlaybackUrls, ...}
 * @phpstan-type BroadcastStreamWithPublish array{streamId: string, tenantId: string, streamKey: string, state: string, playback: string, createdAt: int, startedAt?: int, endedAt?: int, viewers: int, peakViewers: int, metadata?: mixed, playbackUrls: PlaybackUrls, publish: array{whipUrl: string, token: string, expiresAt: int, ...}, ...}
 * @phpstan-type StreamToken array{streamId: string, action: string, token: string, expiresAt: int, whipUrl?: string, llhls?: string, webrtc?: string, ...}
 * @phpstan-type Usage array{participantSeconds: int, publisherSeconds: int, roomSeconds: int, roomsCreated: int, peakParticipants: int, chatMessages: int, chatConnectionSeconds: int, broadcastSeconds: int, broadcastViewerSeconds: int, streamsStarted: int, billableSeconds?: int, freeSeconds?: int, ...}
 * @phpstan-type DailyUsage array{day: string, participantSeconds: int, publisherSeconds: int, roomSeconds: int, roomsCreated: int, peakParticipants: int, chatMessages: int, chatConnectionSeconds: int, broadcastSeconds: int, broadcastViewerSeconds: int, streamsStarted: int, billableSeconds?: int, freeSeconds?: int, ...}
 * @phpstan-type UsageSummary array{tenantId: string, plan: string, status: string, minutesLimit: int, usedMinutes: int, sharedUsedMinutes?: int, minutesRemaining: int, allowance?: array<string, mixed>, today: Usage, month: Usage, lifetime: Usage, ...}
 * @phpstan-type LegacyFunds array{unlimited: bool, freeSeconds: int, ownSeconds: int, sharedSeconds: int, totalSeconds: int, ...}
 * @phpstan-type WalletFunds array{spendOrder: list<string>, scopes: array<string, array{paidCu: string, promoCu: string, ...}>, paidCu: string, promoCu: string, availableCu: string, availableCredits: string, ...}
 * @phpstan-type ProjectBalance array{tenantId: string, billingMode: string, canJoin: bool, blocked: ?string, legacy: LegacyFunds, wallet: ?WalletFunds, burn: array{liveParticipants: int, projectCuPerSecond: string, walletCuPerSecond: string, ...}, remainingSeconds: int, walletLow: array{active: bool, graceSeconds: int, ...}, pricingVersion: ?int, updatedAt: int, ...}
 * @phpstan-type RtcAddonRate array{price: string, unit: string, appliesTo: list<string>, includedIn: list<string>, id?: string, label?: string, cuPerParticipantSecond?: string, ...}
 * @phpstan-type RtcRates array{version: int, kind: string, effectiveAt: ?string, unit: array{price: string, rate: string, cuPerCredit: string, ...}, tiers: list<array<string, mixed>>, surcharges: list<array<string, mixed>>, addons?: array{music?: RtcAddonRate, ...}, idleTier: string, modes: list<array<string, mixed>>, conversion: array<string, mixed>, notes: list<string>, ...}
 */
final class StreamClient implements \JsonSerializable, \Stringable
{
    /** The package version; also the version in the User-Agent. Bump with CHANGELOG.md. */
    public const VERSION = '0.1.1';
    /** Sent on every request, to the controller and to the rates host: nothing more identifying (spec 8). */
    public const USER_AGENT = 'voltkit-rtc-php/' . self::VERSION;

    public const DEFAULT_TIMEOUT_MS = 10000;
    public const DEFAULT_RETRIES = 2;
    public const DEFAULT_USAGE_SERIES_DAYS = 30;
    /** 2^31-1 ms, about 24.8 days: a timeout past it, PHP_INT_MAX included, is held here. */
    public const MAX_TIMEOUT_MS = 2147483647;

    /** The wait before retry n (1-based) is BACKOFF_BASE_MS * BACKOFF_FACTOR^(n-1) plus 0 to BACKOFF_JITTER_MAX_MS. */
    public const BACKOFF_BASE_MS = 200;
    public const BACKOFF_FACTOR = 2;
    public const BACKOFF_JITTER_MAX_MS = 100;

    /**
     * The most of a reply body the SDK reads, 4 MiB, on a 2xx and on an error status alike (0.1.1). A reply past it
     * fails with http-<status>, retryable by the status rule, and a message that quotes nothing of it; the curl
     * transport stops reading at the cap, and one declared longer is not read at all. The largest honest reply (a
     * room of thousands of participants, every live room of a project) is well under it. PHP has a memory_limit (128
     * MB by default) that json_decode exhausts with a fatal error no catch can handle: a 10 MB reply of small values
     * needed over 128 MB (SDK red team, 2026-10-28). Go reads up to 8 MiB; a Go program has no such limit.
     */
    public const MAX_REPLY_BYTES = 4 * 1024 * 1024;
    /**
     * The most objects and arrays a reply under MAX_REPLY_BYTES may hold (each `{` and `[` in the text counts), or it
     * is refused as too large before json_decode builds anything (0.1.1). json_decode makes a PHP array of 200 to 450
     * bytes of each, so a 4 MiB reply of `[0],[0],...` would need about 240 MB, where values alone cost at most about
     * 12 bytes per byte of reply (50 MB at the cap). At 100,000 the worst reply under both limits takes about 85 MB;
     * an honest reply at the byte cap holds at most about 80,000 (a room is two or three, a participant one).
     */
    public const MAX_REPLY_CONTAINERS = 100_000;
    /** What a reply past either limit fails with, after "<METHOD> <path>: the reply is too large: ". */
    private const TOO_LARGE_BYTES = 'over ' . (self::MAX_REPLY_BYTES >> 20) . ' MiB, the most this SDK reads';
    private const TOO_LARGE_CONTAINERS = 'over 100,000 objects and arrays, the most this SDK decodes';

    /** Where a message is cut, after redaction: the text of an error reply that names no message, a 2xx that is not JSON, a redirect's target. */
    public const CUT_ERROR_REPLY = 300;
    public const CUT_NOT_JSON = 100;
    public const CUT_REDIRECT_TARGET = 200;

    public const API_KEY_HEADER = 'X-API-Key';
    /** Where the website API serves the public rates. */
    public const RATES_PATH = '/api/v1/billing/rtc/rates/current';

    private readonly Secrets $secrets;
    /** baseUrl with any credentials shown as [redacted]: what printing the client shows. */
    private readonly string $baseUrlShown;
    private readonly ?string $ratesBaseUrlShown;
    private readonly int $timeoutMs;
    private readonly int $retries;
    private readonly TransportInterface $transport;
    private readonly ?LoggerInterface $logger;
    /** @var ?\Closure(int): void */
    private readonly ?\Closure $sleeper;

    /**
     * @param string $baseUrl the controller's origin, exact scheme and host (https://api.voltkit.in for VoltKit cloud); the key is sent here and nowhere else
     * @param string $apiKey the project API secret, server-side only; whitespace around it is dropped
     * @param ?string $ratesBaseUrl the website API's origin (https://api.voltkit.in), where getRates() reads the public price list with no credential; "" counts as unset
     * @param int $timeoutMs the deadline for each attempt, from sending the request to the last byte of the reply; default 10000, must be above 0
     * @param int $retries extra attempts after a transient failure on a call that is safe to repeat; default 2, 0 turns retries off
     * @param ?TransportInterface $transport the HTTP transport; the curl one by default (a PSR-18 adapter and test doubles exist)
     * @param ?LoggerInterface $logger a PSR-3 logger handed method, path, status, attempt and elapsed milliseconds per attempt, at debug; never a header, a body or the key
     * @param ?\Closure(int): void $sleeper how to wait between retries, given milliseconds; usleep() by default. For tests
     * @throws \InvalidArgumentException for a missing baseUrl, a baseUrl or ratesBaseUrl that is not an absolute http(s) URL with a host, or a key that is missing, whitespace, or holds a character no header or no VoltKit key has (never quoted)
     * @throws \RangeException for a timeoutMs of 0 or below, or negative retries
     */
    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] string $apiKey,
        ?string $ratesBaseUrl = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        int $retries = self::DEFAULT_RETRIES,
        ?TransportInterface $transport = null,
        ?LoggerInterface $logger = null,
        ?\Closure $sleeper = null,
    ) {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('baseUrl is required');
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $netKey = ApiKey::net($apiKey);
        $origin = self::httpOrigin('baseUrl', self::withoutTrailingSlash($baseUrl));
        // Empty counts as unset, so getRates says what is missing rather than requesting a relative URL.
        $rates = $ratesBaseUrl === null ? null : self::withoutTrailingSlash($ratesBaseUrl);
        if ($rates === '') {
            $rates = null;
        }
        if ($rates !== null) {
            $rates = self::httpOrigin('ratesBaseUrl', $rates);
        }
        $this->secrets = new Secrets($netKey, $origin, $rates);
        $this->baseUrlShown = Redactor::redactUrlCredentials($origin);
        $this->ratesBaseUrlShown = $rates === null ? null : Redactor::redactUrlCredentials($rates);
        if ($timeoutMs <= 0) {
            throw new \RangeException("timeoutMs must be a number above 0, got $timeoutMs");
        }
        $this->timeoutMs = min($timeoutMs, self::MAX_TIMEOUT_MS);
        if ($retries < 0) {
            throw new \RangeException("retries must be a whole number of 0 or more, got $retries");
        }
        $this->retries = $retries;
        $this->transport = $transport ?? new CurlTransport();
        $this->logger = $logger;
        $this->sleeper = $sleeper;
    }

    /**
     * A client from the environment: VOLTKIT_RTC_URL (baseUrl), VOLTKIT_RTC_SECRET (apiKey) and the optional
     * VOLTKIT_API_URL (ratesBaseUrl), read from $_SERVER, $_ENV and getenv() in that order, so a value a
     * dotenv loader put only in the arrays (Laravel) is found too. A missing variable is named in the error;
     * its value never is.
     *
     * @throws \InvalidArgumentException when VOLTKIT_RTC_URL or VOLTKIT_RTC_SECRET is not set, or a value is refused
     */
    public static function fromEnv(
        ?TransportInterface $transport = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        int $retries = self::DEFAULT_RETRIES,
        ?LoggerInterface $logger = null,
    ): self {
        $baseUrl = self::env('VOLTKIT_RTC_URL');
        if ($baseUrl === null) {
            throw new \InvalidArgumentException(
                'VOLTKIT_RTC_URL is not set: the API base URL from your project\'s Credentials tab,'
                . ' e.g. https://api.voltkit.in',
            );
        }
        $apiKey = self::env('VOLTKIT_RTC_SECRET');
        if ($apiKey === null) {
            throw new \InvalidArgumentException(
                'VOLTKIT_RTC_SECRET is not set: the project API secret from the Credentials tab',
            );
        }
        return new self($baseUrl, $apiKey, self::env('VOLTKIT_API_URL'), $timeoutMs, $retries, $transport, $logger);
    }

    // --- rooms -------------------------------------------------------------------------------------------------------

    /**
     * Create a room. Fails with `room-exists` (409) if the id is taken; the server makes an id up when none
     * is given. Never retried: a lost reply would turn a success into a spurious room-exists (spec 4.1).
     * For "go live", prefer ensureRoom(): a host reconnecting after a dropped call hits the existing-room
     * path, and this turns that into two round trips.
     *
     * @param string $hostIdentity who the host token is for
     * @param ?string $roomId your own id (webhooks read back usefully), or null for a server-made one
     * @param mixed $metadata opaque JSON every participant receives on join; null leaves it out
     * @return RoomWithHostToken the room with hostToken, wsUrl, expiresAt (unix seconds) and existed: false
     */
    public function createRoom(string $hostIdentity, ?string $roomId = null, mixed $metadata = null): array
    {
        $body = ['roomId' => $roomId, 'hostIdentity' => $hostIdentity, 'metadata' => $metadata];
        [$room] = $this->callObject('POST', '/v1/rooms', $body, safeToRepeat: false);
        $room['existed'] = false;
        /** @var RoomWithHostToken $room */
        return $room;
    }

    /**
     * Create the room, or return it with a fresh host token if it already exists: one round trip either way.
     * `existed` says which happened (201 created, 200 it existed); metadata is applied only on creation.
     *
     * @param mixed $metadata opaque JSON every participant receives on join; null leaves it out
     * @return RoomWithHostToken
     */
    public function ensureRoom(string $roomId, string $hostIdentity, mixed $metadata = null): array
    {
        $path = '/v1/rooms/' . self::segment('roomId', $roomId);
        $body = ['hostIdentity' => $hostIdentity, 'metadata' => $metadata];
        [$room, $status] = $this->callObject('PUT', $path, $body, safeToRepeat: true);
        $room['existed'] = $status === 200;
        /** @var RoomWithHostToken $room */
        return $room;
    }

    /**
     * Authorise one of your users: your backend decides the role, it is signed into the token and the client
     * cannot change it. Tokens last ten minutes; a connected client is refreshed by the controller.
     *
     * @param Role|string $role host, cohost, moderator or viewer; an unknown role is `bad-role` (400)
     * @return RoomToken
     */
    public function createToken(string $roomId, string $identity, Role|string $role): array
    {
        $path = '/v1/rooms/' . self::segment('roomId', $roomId) . '/tokens';
        $body = ['identity' => $identity, 'role' => $role instanceof Role ? $role->value : $role];
        [$token] = $this->callObject('POST', $path, $body, safeToRepeat: true);
        /** @var RoomToken $token */
        return $token;
    }

    /**
     * The authoritative live view: the room, who is in it, and what they publish. Poll this for a live
     * count rather than adding up webhook joins and leaves.
     *
     * @return RoomView { room, participants, producers } as sent
     */
    public function getRoom(string $roomId): array
    {
        $path = '/v1/rooms/' . self::segment('roomId', $roomId);
        [$view] = $this->callObject('GET', $path, null, safeToRepeat: true);
        /** @var RoomView $view */
        return $view;
    }

    /**
     * Every live room of the project.
     *
     * @return array{rooms: list<Room>, ...} the object as sent, not unwrapped
     */
    public function listRooms(): array
    {
        [$rooms] = $this->callObject('GET', '/v1/rooms', null, safeToRepeat: true);
        /** @var array{rooms: list<Room>, ...} $rooms */
        return $rooms;
    }

    /**
     * Who is in a room right now: a point-in-time read for an admin screen or a reconciliation job, not a
     * presence feed (take the webhooks for that).
     *
     * @return list<Participant> empty when the reply has none
     */
    public function listParticipants(string $roomId): array
    {
        $path = '/v1/rooms/' . self::segment('roomId', $roomId) . '/participants';
        [$value] = $this->call('GET', $path, null, safeToRepeat: true);
        /** @var list<Participant> */
        return self::listOf($value, 'participants');
    }

    /** End a room: everyone is disconnected and the room is gone. Resolves too when a lost reply's retry finds it gone. */
    public function deleteRoom(string $roomId): void
    {
        $path = '/v1/rooms/' . self::segment('roomId', $roomId);
        $this->call('DELETE', $path, null, safeToRepeat: true, goneIsDone: true);
    }

    // --- chat --------------------------------------------------------------------------------------------------------
    //
    // A channel shares its name with the room of the same name: the people in room "show-42" and the people
    // only in chat "show-42" are one conversation. A channel with no room behind it is chat on its own.

    /**
     * Admit one of your users to a channel's chat with no media rights: an audience watching a broadcast,
     * say. People in the room already have chat on the socket they hold.
     *
     * @param Role|string|null $role null leaves it out: the server defaults to viewer
     * @return ChatToken wsUrl ends in /ws/chat
     */
    public function createChatToken(string $channel, string $identity, Role|string|null $role = null): array
    {
        $path = '/v1/chat/' . self::segment('channel', $channel) . '/tokens';
        $body = ['identity' => $identity, 'role' => $role instanceof Role ? $role->value : $role];
        [$token] = $this->callObject('POST', $path, $body, safeToRepeat: true);
        /** @var ChatToken $token */
        return $token;
    }

    /**
     * How many identities are connected to a channel, in the room or only in its chat.
     *
     * @return ChatInfo
     */
    public function getChatInfo(string $channel): array
    {
        $path = '/v1/chat/' . self::segment('channel', $channel);
        [$info] = $this->callObject('GET', $path, null, safeToRepeat: true);
        /** @var ChatInfo $info */
        return $info;
    }

    /**
     * The newest `$limit` messages (50 by default, at most 100), oldest first, direct messages included:
     * your backend owns the conversation. Pass the oldest id you hold as `$before` to page back.
     * A limit of 0 and an empty `$before` are not sent.
     *
     * @return list<ChatMessage> empty when the reply has none
     */
    public function listMessages(string $channel, ?int $limit = null, ?string $before = null): array
    {
        $query = [];
        if ($limit !== null && $limit !== 0) {
            $query[] = 'limit=' . self::formEncode((string) $limit);
        }
        if ($before !== null && $before !== '') {
            $query[] = 'before=' . self::formEncode($before);
        }
        $path = '/v1/chat/' . self::segment('channel', $channel) . '/messages'
            . ($query === [] ? '' : '?' . implode('&', $query));
        [$value] = $this->call('GET', $path, null, safeToRepeat: true);
        /** @var list<ChatMessage> */
        return self::listOf($value, 'messages');
    }

    /**
     * Speak from your backend: a system notice, a gift announcement, a warning. Skips the per-socket rate
     * limit and the mute list, both of which exist to restrain clients. Never retried: a lost reply would
     * become the same message twice (spec 4.1). A message needs text or metadata.
     *
     * @param ?string $type defaults to "text" on the server; anything else is your own vocabulary
     * @param ?string $from who it is from; defaults to "system"
     * @param ?string $to make it a direct message to one identity
     * @param mixed $metadata opaque JSON; null leaves it out
     * @param ?bool $ephemeral deliver without storing: for reactions and anything not worth replaying
     * @return ChatMessage the stored message
     */
    public function sendMessage(
        string $channel,
        ?string $text = null,
        ?string $type = null,
        ?string $from = null,
        ?string $to = null,
        mixed $metadata = null,
        ?bool $ephemeral = null,
    ): array {
        $path = '/v1/chat/' . self::segment('channel', $channel) . '/messages';
        $body = [
            'text' => $text,
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'metadata' => $metadata,
            'ephemeral' => $ephemeral,
        ];
        [$message] = $this->callObject('POST', $path, $body, safeToRepeat: false);
        /** @var ChatMessage $message */
        return $message;
    }

    /** Remove a message; everyone who could see it is told to drop it. Resolves too when a lost reply's retry finds it gone. */
    public function deleteMessage(string $channel, string $messageId): void
    {
        $path = '/v1/chat/' . self::segment('channel', $channel)
            . '/messages/' . self::segment('messageId', $messageId);
        $this->call('DELETE', $path, null, safeToRepeat: true, goneIsDone: true);
    }

    /**
     * The identities currently muted in a channel's chat.
     *
     * @return list<string> empty when the reply has none
     */
    public function listChatMutes(string $channel): array
    {
        $path = '/v1/chat/' . self::segment('channel', $channel) . '/mutes';
        [$value] = $this->call('GET', $path, null, safeToRepeat: true);
        /** @var list<string> */
        return self::listOf($value, 'muted');
    }

    /** Silence an identity in a channel's chat. Held for the channel, not the connection, so reconnecting does not undo it; they can still read. */
    public function muteChat(string $channel, string $identity): void
    {
        $path = '/v1/chat/' . self::segment('channel', $channel)
            . '/mutes/' . self::segment('identity', $identity);
        $this->call('PUT', $path, null, safeToRepeat: true);
    }

    /** Lift a mute. Resolves too when a lost reply's retry finds it already gone. */
    public function unmuteChat(string $channel, string $identity): void
    {
        $path = '/v1/chat/' . self::segment('channel', $channel)
            . '/mutes/' . self::segment('identity', $identity);
        $this->call('DELETE', $path, null, safeToRepeat: true, goneIsDone: true);
    }

    // --- broadcast ---------------------------------------------------------------------------------------------------
    //
    // One publisher, any number of viewers, over LL-HLS and WebRTC. Not offered on VoltKit cloud today: every
    // call answers `broadcast-disabled` (404) on a deployment without a media server.

    /**
     * Create a stream. The reply's publish.whipUrl is complete: hand it to the encoder as it is. This is the
     * ONLY call that returns a publish token; reading or listing a stream never does. Never retried, for the
     * reason createRoom() is not.
     *
     * @param PlaybackPolicy|string|null $playback public (the default) or token; null leaves it out
     * @param mixed $metadata opaque JSON; null leaves it out
     * @return BroadcastStreamWithPublish
     */
    public function createStream(
        string $streamId,
        PlaybackPolicy|string|null $playback = null,
        mixed $metadata = null,
    ): array {
        $body = [
            'streamId' => $streamId,
            'playback' => $playback instanceof PlaybackPolicy ? $playback->value : $playback,
            'metadata' => $metadata,
        ];
        [$stream] = $this->callObject('POST', '/v1/streams', $body, safeToRepeat: false);
        /** @var BroadcastStreamWithPublish $stream */
        return $stream;
    }

    /**
     * A stream's state, viewers and peak viewers; never its publish token.
     *
     * @return BroadcastStream
     */
    public function getStream(string $streamId): array
    {
        $path = '/v1/streams/' . self::segment('streamId', $streamId);
        [$stream] = $this->callObject('GET', $path, null, safeToRepeat: true);
        /** @var BroadcastStream $stream */
        return $stream;
    }

    /**
     * Every stream of the project.
     *
     * @return list<BroadcastStream> empty when the reply has none
     */
    public function listStreams(): array
    {
        [$value] = $this->call('GET', '/v1/streams', null, safeToRepeat: true);
        /** @var list<BroadcastStream> */
        return self::listOf($value, 'streams');
    }

    /**
     * Change who may watch, or the stream's metadata. Fields left null are not sent.
     *
     * @param PlaybackPolicy|string|null $playback public or token
     * @param mixed $metadata opaque JSON
     * @return BroadcastStream
     */
    public function updateStream(
        string $streamId,
        PlaybackPolicy|string|null $playback = null,
        mixed $metadata = null,
    ): array {
        $path = '/v1/streams/' . self::segment('streamId', $streamId);
        $body = [
            'playback' => $playback instanceof PlaybackPolicy ? $playback->value : $playback,
            'metadata' => $metadata,
        ];
        [$stream] = $this->callObject('PATCH', $path, $body, safeToRepeat: true);
        /** @var BroadcastStream $stream */
        return $stream;
    }

    /** Delete a stream: its publish and playback URLs stop working at once. Resolves too when a lost reply's retry finds it gone. */
    public function deleteStream(string $streamId): void
    {
        $path = '/v1/streams/' . self::segment('streamId', $streamId);
        $this->call('DELETE', $path, null, safeToRepeat: true, goneIsDone: true);
    }

    /**
     * Mint a playback token for a viewer of a token-protected stream, or a fresh publish token for an
     * encoder whose URL has expired. The reply carries the URLs the token belongs in.
     *
     * @param StreamAction|string|null $action play (the default) or publish; null leaves it out
     * @param ?string $identity the viewer, for a play token
     * @param ?int $ttlSeconds defaults to 6 hours on the server and is capped at 24
     * @return StreamToken with whipUrl (publish) or llhls and webrtc (play)
     */
    public function createStreamToken(
        string $streamId,
        StreamAction|string|null $action = null,
        ?string $identity = null,
        ?int $ttlSeconds = null,
    ): array {
        $path = '/v1/streams/' . self::segment('streamId', $streamId) . '/tokens';
        $body = [
            'action' => $action instanceof StreamAction ? $action->value : $action,
            'identity' => $identity,
            'ttlSeconds' => $ttlSeconds,
        ];
        [$token] = $this->callObject('POST', $path, $body, safeToRepeat: true);
        /** @var StreamToken $token */
        return $token;
    }

    // --- usage -------------------------------------------------------------------------------------------------------

    /**
     * Your balance of minutes, and usage for today, this month and lifetime. minutesRemaining is -1 on an
     * unlimited plan; allowance says where the account stands against every limit.
     *
     * @return UsageSummary
     */
    public function getUsage(): array
    {
        [$usage] = $this->callObject('GET', '/v1/usage', null, safeToRepeat: true);
        /** @var UsageSummary $usage */
        return $usage;
    }

    /**
     * One entry per UTC day, oldest first, ending today; days with no usage are present and zero. The
     * server treats days <= 0 as 30 and caps at 90.
     *
     * @return list<DailyUsage> empty when the reply has none
     */
    public function getUsageSeries(int $days = self::DEFAULT_USAGE_SERIES_DAYS): array
    {
        $path = '/v1/usage/series?days=' . rawurlencode((string) $days);
        [$value] = $this->call('GET', $path, null, safeToRepeat: true);
        /** @var list<DailyUsage> */
        return self::listOf($value, 'series');
    }

    // --- billing -----------------------------------------------------------------------------------------------------

    /**
     * What your project can spend right now, and whether a newcomer would be admitted. Read only: recharges
     * happen on the website. Amounts are cu (1 credit = 6,000,000 cu) as decimal strings: add them up with
     * bcmath or gmp, never as floats.
     *
     * @return ProjectBalance
     */
    public function getBalance(): array
    {
        [$balance] = $this->callObject('GET', '/v1/billing/balance', null, safeToRepeat: true);
        /** @var ProjectBalance $balance */
        return $balance;
    }

    /**
     * The public price list, from the website API named by $ratesBaseUrl, with no credential: it is public
     * and on another host. Fails with `rates-unconfigured` (status 0) without $ratesBaseUrl, and with
     * `http-<status>` when the reply is not the website's { success, data } envelope.
     *
     * `addons.music` (0.1.1) is the Music add-on: in-app music billed on standard and HD voice while music
     * plays (`price` in credits per 1,000 participant-minutes, "0.00" when this price list does not charge for it;
     * `cuPerParticipantSecond` the exact integer; `appliesTo`, `includedIn`), included with video; absent from a
     * price list older than the add-on.
     * `music` is an add-on id, not a tier: a usage line with `tier: "music"` is the add-on's minutes.
     *
     * @return RtcRates the `data` of the envelope
     */
    public function getRates(): array
    {
        if ($this->secrets->ratesBaseUrl() === null) {
            throw new StreamError(
                'rates-unconfigured',
                0,
                'getRates needs ratesBaseUrl: the website API origin that serves the public rates,'
                . ' e.g. https://api.example.com',
                false,
            );
        }
        [$value, $status] = $this->call('GET', self::RATES_PATH, null, safeToRepeat: true, public: true);
        $data = is_array($value) ? ($value['data'] ?? null) : null;
        if (!is_array($data)) {
            // Not the API's { success, data } envelope: ratesBaseUrl most likely names some other service, and
            // asking again gets the same answer.
            throw new StreamError(
                "http-$status",
                $status,
                'GET ' . self::RATES_PATH . ': the reply carries no rates; is ratesBaseUrl the website API origin?',
                false,
            );
        }
        /** @var RtcRates $data */
        return $data;
    }

    // --- what the client shows of itself ---------------------------------------------------------------------------

    /** @return array<string, mixed> for var_dump() and print_r(): the URLs with credentials redacted, never the key */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize() + ['transport' => $this->transport::class, 'apiKey' => Redactor::MARKER];
    }

    /** @return array{baseUrl: string, ratesBaseUrl: ?string, timeoutMs: int, retries: int} for json_encode(): never the key */
    public function jsonSerialize(): array
    {
        return [
            'baseUrl' => $this->baseUrlShown,
            'ratesBaseUrl' => $this->ratesBaseUrlShown,
            'timeoutMs' => $this->timeoutMs,
            'retries' => $this->retries,
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'StreamClient(baseUrl: %s, ratesBaseUrl: %s, timeoutMs: %d, retries: %d)',
            $this->baseUrlShown,
            $this->ratesBaseUrlShown ?? 'null',
            $this->timeoutMs,
            $this->retries,
        );
    }

    /** serialize() would carry the key: refused. Make a new client where you need one. @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException(
            'a VoltKit\Rtc\StreamClient cannot be serialized: it holds the API key.'
            . ' Make a new client where you need one',
        );
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('a VoltKit\Rtc\StreamClient cannot be unserialized: it holds the API key');
    }

    // --- transport ---------------------------------------------------------------------------------------------------
    /**
     * One call: the body serialized once, then up to retries + 1 attempts on a call that is safe to repeat,
     * with exponential backoff and jitter so a fleet-wide blip does not produce a synchronised retry from
     * every backend at once (spec 4.1); gone-is-done for the deletes (4.2).
     *
     * Nothing here takes the server's words, the transport's exception or an origin as an argument: a StreamError
     * records the arguments of every active frame in its trace, and with zend.exception_ignore_args off they would
     * ride along unredacted (review 2026-10-23).
     *
     * @param ?array<string, mixed> $body the fields to send; null values are left out; null for no body
     * @param bool $safeToRepeat whether repeating is harmless: decided per method, not per verb
     * @param bool $goneIsDone a delete: a 404 on a retry after an attempt that may have landed means done
     * @param bool $public send to ratesBaseUrl with no key (the public rates); false means the controller, with the key
     * @param bool $object the method is typed to return a JSON object: any other 2xx value is http-<status>
     * @return array{0: mixed, 1: int} the decoded value (null for an empty 2xx) and the status
     */
    private function call(
        string $method,
        string $path,
        ?array $body,
        bool $safeToRepeat,
        bool $goneIsDone = false,
        bool $public = false,
        bool $object = false,
    ): array {
        // Once, before anything is sent: a body JSON cannot carry is the caller's to fix, and no attempt would go
        // differently.
        $encoded = $body === null ? null : self::serialize($method, $path, $body);
        $attempts = $safeToRepeat ? $this->retries + 1 : 1;
        $last = null;
        // Whether an earlier attempt may have reached the server and done the work, its reply lost on the way
        // back. A 429 is a refusal: that attempt did nothing.
        $mayHaveLanded = false;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1) {
                // The wait doubles each time and is held at the longest deadline, as the deadline itself is: past
                // 2^56 the product overflows into a float (review 2026-10-23).
                $wait = (int) min(self::BACKOFF_BASE_MS * self::BACKOFF_FACTOR ** ($attempt - 2), self::MAX_TIMEOUT_MS);
                $this->sleep($wait + random_int(0, self::BACKOFF_JITTER_MAX_MS));
            }
            try {
                return $this->once($method, $path, $encoded, $public, $object, $attempt);
            } catch (StreamError $e) {
                if ($goneIsDone && $mayHaveLanded && self::isGone($e)) {
                    // The earlier attempt deleted it; this one finds it gone.
                    return [null, $e->status];
                }
                if (!$e->retryable) {
                    throw $e;
                }
                $last = $e;
                if ($e->status !== 429) {
                    $mayHaveLanded = true;
                }
            }
        }
        assert($last !== null);
        throw $last;
    }

    /**
     * call() for a method typed to return a JSON object: the value, narrowed.
     *
     * @param ?array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function callObject(string $method, string $path, ?array $body, bool $safeToRepeat): array
    {
        [$value, $status] = $this->call($method, $path, $body, $safeToRepeat, object: true);
        if (!is_array($value)) {
            throw new \LogicException('call(object: true) let a reply through that is not a JSON object');
        }
        /** @var array<string, mixed> $value */
        return [$value, $status];
    }

    /**
     * One attempt. The key is attached here and only here, and only for the controller.
     *
     * @return array{0: mixed, 1: int}
     */
    private function once(string $method, string $path, ?string $body, bool $public, bool $object, int $attempt): array
    {
        $withKey = !$public;
        $headers = [];
        if ($withKey) {
            $headers[self::API_KEY_HEADER] = $this->secrets->apiKey();
        }
        $headers['User-Agent'] = self::USER_AGENT;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $origin = $public
            ? ($this->secrets->ratesBaseUrl() ?? throw new \LogicException('a public request needs ratesBaseUrl'))
            : $this->secrets->baseUrl();
        // Never follow a redirect with the key: a transport keeps headers across a redirect to another host, so
        // following one would hand X-API-Key to whatever host it names. The public rates carry no credential and
        // may follow one (a website API behind a www. or https redirect).
        $request = new Request($method, $origin . $path, $headers, $body, $this->timeoutMs, $public);
        $started = hrtime(true);
        $status = 0;
        try {
            try {
                $response = $this->transport->send($request);
            } catch (\Throwable $e) {
                // A request that failed in transit, before or during the body: the deadline passing, or the
                // connection failing. Retryable; call() repeats it only for a call that is safe to repeat. The
                // transport's own words may quote the URL, credentials and all, or the header value it refused:
                // redacted. Its exception is not attached as the previous one, for the same reason.
                $reason = $this->networkReason($e);
                throw new StreamError('network', 0, "$method $path: $reason", true);
            }
            $status = $response->status;
            if ($withKey && $status >= 300 && $status < 400) {
                // The 3xx itself is the answer: not retried (the same baseUrl redirects the same way) and not
                // followed. The target is named so the fix is obvious; it is cut for a log line after the key is
                // taken out, so the cut cannot leave part of it behind.
                $target = $response->header('location');
                $to = $target !== null && $target !== ''
                    ? ' to ' . self::cut($this->secrets->redact($target), self::CUT_REDIRECT_TARGET)
                    : '';
                throw new StreamError(
                    'redirected',
                    $status,
                    "$method $path: the server answered $status$to; a request carrying the API key is never redirected."
                    . ' Point baseUrl at the controller itself (its exact scheme and host)',
                    false,
                );
            }
            // Before anything is decoded, on any status: past the cap the reply is neither the API's nor worth
            // decoding (a misbehaving server, a proxy), and decoding it could end this process. Nothing of it is
            // quoted, so nothing in the message needs redacting; retryable as any reply that named no code.
            $tooLarge = $response->tooLarge ? self::TOO_LARGE_BYTES : self::tooLarge($response->body);
            if ($tooLarge !== null) {
                throw new StreamError(
                    "http-$status",
                    $status,
                    "$method $path: the reply is too large: $tooLarge",
                    $status >= 500 || $status === 429,
                );
            }
            $decoded = $this->decodeReply($method, $path, $status, $response->body, $object);
            if ($decoded['error'] !== null) {
                [$code, $message, $retryable] = $decoded['error'];
                throw new StreamError($code, $status, $message, $retryable);
            }
            return [$decoded['value'], $status];
        } finally {
            $this->logger?->debug('voltkit-rtc {method} {path}: {status} (attempt {attempt}, {elapsedMs} ms)', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'attempt' => $attempt,
                'elapsedMs' => (int) ((hrtime(true) - $started) / 1_000_000),
            ]);
        }
    }

    /**
     * Why a reply is too large to decode (what the message says after "the reply is too large: "), or null. The
     * objects and arrays are counted only in a reply that could be a JSON object or array: an HTML page full of
     * braces is "not JSON", as before. A `{` or `[` inside a string counts too, so the count errs on the safe side.
     * This returns rather than throws, so that the raw text is in no frame's arguments when the error is made.
     */
    private static function tooLarge(string $text): ?string
    {
        if (strlen($text) > self::MAX_REPLY_BYTES) {
            return self::TOO_LARGE_BYTES;
        }
        $first = $text[strspn($text, " \t\r\n")] ?? '';
        if (
            ($first === '{' || $first === '[')
            && substr_count($text, '{') + substr_count($text, '[') > self::MAX_REPLY_CONTAINERS
        ) {
            return self::TOO_LARGE_CONTAINERS;
        }
        return null;
    }

    /** What a network error says after "<METHOD> <path>: ": the timeout in ms, or the transport's words redacted. */
    private function networkReason(\Throwable $e): string
    {
        return $e instanceof TransportException && $e->timedOut
            ? "timed out after {$this->timeoutMs}ms"
            : $this->secrets->redact($e->getMessage());
    }

    /**
     * A whole reply, read: the value on a 2xx, else what the StreamError must say. The server's words go through
     * redaction before they reach a message, and before they are cut short, so a cut cannot leave part of the key
     * behind (spec 3.6). This returns rather than throws, so that the raw text is in no frame's arguments when the
     * error is made.
     *
     * @param bool $object a 2xx must decode to a JSON object (a method typed to return one); Node hands any JSON
     *   value back, a method typed to return an array cannot (a decision beyond the spec; the API never answers
     *   otherwise)
     * @return array{value: mixed, error: ?array{0: string, 1: string, 2: bool}} the value, or [code, message, retryable]
     */
    private function decodeReply(string $method, string $path, int $status, string $text, bool $object): array
    {
        if ($status < 200 || $status >= 300) {
            [$code, $message] = self::errorFields($text);
            // Redacted too: a server answering {"error": "Invalid API key: <key>"} put the key in the code, the
            // field a log line prints (N044-1). An ordinary code holds no key and passes unchanged.
            $code = $code !== null ? $this->secrets->redact($code) : "http-$status";
            $message = $message !== null
                ? $this->secrets->redact($message)
                : self::cut($this->secrets->redact($text), self::CUT_ERROR_REPLY);
            // 5xx and 429 may pass; a 4xx is a decision the server made and will make again. billing-unavailable
            // is the 503 that will not: it lasts until an operator acts.
            $retryable = ($status >= 500 || $status === 429) && $code !== 'billing-unavailable';
            return ['value' => null, 'error' => [$code, "$method $path: $message", $retryable]];
        }
        if ($text === '' && !$object) {
            return ['value' => null, 'error' => null];
        }
        try {
            $value = $text === '' ? null : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A success that is not JSON came from something other than the API, a web page or a proxy, and a
            // retry would fetch the same thing.
            return ['value' => null, 'error' => [
                "http-$status",
                "$method $path: the reply is not JSON: " . self::cut($this->secrets->redact($text), self::CUT_NOT_JSON),
                false,
            ]];
        }
        if ($object && !is_array($value)) {
            $shown = self::cut($this->secrets->redact($text), self::CUT_NOT_JSON);
            $message = "$method $path: the reply is not a JSON object: $shown";
            return ['value' => null, 'error' => ["http-$status", $message, false]];
        }
        return ['value' => $value, 'error' => null];
    }

    /**
     * The code and message of an error reply, either absent when empty or not a string. The controller
     * answers { error, message }; the website API { success: false, error: { code, message } }. Anything
     * else, a proxy's HTML page, has neither.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function errorFields(string $text): array
    {
        try {
            $body = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, null];
        }
        if (!is_array($body)) {
            return [null, null];
        }
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            return [self::nonEmpty($error['code'] ?? null), self::nonEmpty($error['message'] ?? null)];
        }
        return [self::nonEmpty($error), self::nonEmpty($body['message'] ?? null)];
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The list inside a wrapper, or an empty list when the key is missing.
     *
     * @return list<mixed>
     */
    private static function listOf(mixed $value, string $key): array
    {
        $list = is_array($value) ? ($value[$key] ?? null) : null;
        /** @var list<mixed> */
        return is_array($list) ? array_values($list) : [];
    }

    /**
     * The body as JSON, serialized once, null fields left out, slashes and unicode as they are; or an
     * \InvalidArgumentException naming the call, with the encoder's \JsonException as its previous. Never
     * a retryable network error: no retry would change it (spec 3.3).
     *
     * @param array<string, mixed> $fields
     */
    private static function serialize(string $method, string $path, array $fields): string
    {
        $given = array_filter($fields, static fn (mixed $v): bool => $v !== null);
        try {
            return json_encode(
                $given === [] ? new \stdClass() : $given,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                "$method $path: the body cannot be sent as JSON: " . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * An id, as one path segment. "", "." and ".." are refused: a URL reads them as steps through the path,
     * spelt %2e or not, so no encoding keeps them in their segment (deleteRoom('..') would send DELETE /v1/,
     * with the key). Every other id is percent-encoded as encodeURIComponent does: UTF-8 bytes, upper-case
     * hex, everything but A-Z a-z 0-9 - _ . ~ ! * ' ( ) encoded; rawurlencode() alone would encode ! * ' ( ).
     */
    private static function segment(string $name, string $id): string
    {
        if ($id === '' || $id === '.' || $id === '..') {
            throw new StreamError(
                'bad-request',
                0,
                "$name \"$id\": an id may not be \"\", \".\" or \"..\", which a URL resolves to another endpoint;"
                . ' nothing was sent',
                false,
            );
        }
        return strtr(rawurlencode($id), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }

    /** A query value as URLSearchParams writes it: form-encoded, a space as +, * kept. */
    private static function formEncode(string $value): string
    {
        return strtr(urlencode($value), ['%2A' => '*']);
    }

    /** A 404 saying the thing is not there; not one saying chat or broadcast is switched off, which deleted nothing. */
    private static function isGone(StreamError $e): bool
    {
        return $e->status === 404 && $e->errorCode !== 'chat-disabled' && $e->errorCode !== 'broadcast-disabled';
    }

    /** The first `$characters` of `$text`, already redacted. */
    private static function cut(string $text, int $characters): string
    {
        return mb_substr($text, 0, $characters, 'UTF-8');
    }

    private static function withoutTrailingSlash(string $url): string
    {
        return str_ends_with($url, '/') ? substr($url, 0, -1) : $url;
    }

    /**
     * `$url` when it is an absolute http(s) URL naming a host, else an \InvalidArgumentException naming the
     * option and never the URL (which may hold credentials). Given "api.voltkit.in" or "127.0.0.1:8080", curl
     * guesses http:// and would send the API key in clear text; Node's fetch refuses such a URL on every call
     * (review 2026-10-23). Nothing else is checked: a path prefix is kept, as in Node.
     */
    private static function httpOrigin(string $option, string $url): string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
        if (($scheme !== 'https' && $scheme !== 'http') || $host === '') {
            throw new \InvalidArgumentException(
                "$option must be an absolute URL starting with https:// (or http:// for a controller on your own"
                . ' network) and naming a host, exactly as the Credentials tab shows it: without a scheme the'
                . ' transport would guess one and could send the API key over plain HTTP',
            );
        }
        return $url;
    }

    private function sleep(int $milliseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);
            return;
        }
        usleep($milliseconds * 1000);
    }

    /** A variable from $_SERVER, $_ENV or the process environment, in that order; null when unset or empty. */
    private static function env(string $name): ?string
    {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }
}
