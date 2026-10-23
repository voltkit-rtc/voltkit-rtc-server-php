<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use VoltKit\Rtc\StreamClient;

/**
 * Every method of the client, callable by the name the vectors use, with the
 * vectors' arguments (section requests) or the generator's defaults (sections
 * retry and goneIsDone, which name a bare call: ensureRoom is room "r",
 * createToken room "r" identity "u", deleteMessage "lobby"/"1-0", unmuteChat
 * "lobby"/"u", deleteStream "s", sendMessage channel "c").
 */
final class Calls
{
    /** The 20 places an id goes into a path (spec 3.1), by method and parameter. */
    public const PLACES = [
        ['ensureRoom', 'roomId'],
        ['createToken', 'roomId'],
        ['getRoom', 'roomId'],
        ['listParticipants', 'roomId'],
        ['deleteRoom', 'roomId'],
        ['createChatToken', 'channel'],
        ['getChatInfo', 'channel'],
        ['listMessages', 'channel'],
        ['sendMessage', 'channel'],
        ['deleteMessage', 'channel'],
        ['deleteMessage', 'messageId'],
        ['listChatMutes', 'channel'],
        ['muteChat', 'channel'],
        ['muteChat', 'identity'],
        ['unmuteChat', 'channel'],
        ['unmuteChat', 'identity'],
        ['getStream', 'streamId'],
        ['updateStream', 'streamId'],
        ['deleteStream', 'streamId'],
        ['createStreamToken', 'streamId'],
    ];

    /** The vectors name a method with a parenthetical note: "createRoom (roomId left out: ...)". */
    public static function methodName(string $label): string
    {
        return trim(explode(' ', $label, 2)[0]);
    }

    /**
     * Call `$method` on the client with `$args` (the vectors' argument object), filling the
     * generator's defaults for what is missing.
     *
     * @param array<string, mixed> $args
     */
    public static function invoke(StreamClient $c, string $method, array $args = []): mixed
    {
        $s = static function (string $key, ?string $default = null) use ($args): ?string {
            $value = $args[$key] ?? $default;
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException("$key must be a string in the vectors");
            }
            return $value;
        };
        $need = static function (string $key, string $default) use ($s): string {
            return $s($key, $default) ?? $default;
        };
        $metadata = $args['metadata'] ?? null;
        return match (self::methodName($method)) {
            'createRoom' => $c->createRoom($need('hostIdentity', 'h'), $s('roomId'), $metadata),
            'ensureRoom' => $c->ensureRoom($need('roomId', 'r'), $need('hostIdentity', 'h'), $metadata),
            'createToken' => $c->createToken($need('roomId', 'r'), $need('identity', 'u'), $need('role', 'viewer')),
            'getRoom' => $c->getRoom($need('roomId', 'r')),
            'listRooms' => $c->listRooms(),
            'listParticipants' => $c->listParticipants($need('roomId', 'r')),
            'deleteRoom' => $c->deleteRoom($need('roomId', 'r')),
            'createChatToken' => $c->createChatToken($need('channel', 'c'), $need('identity', 'u'), $s('role')),
            'getChatInfo' => $c->getChatInfo($need('channel', 'c')),
            'listMessages' => $c->listMessages(
                $need('channel', 'c'),
                isset($args['limit']) && is_int($args['limit']) ? $args['limit'] : null,
                $s('before'),
            ),
            'sendMessage' => $c->sendMessage(
                $need('channel', 'c'),
                $s('text', array_key_exists('channel', $args) ? null : 'hi'),
                $s('type'),
                $s('from'),
                $s('to'),
                $metadata,
                isset($args['ephemeral']) && is_bool($args['ephemeral']) ? $args['ephemeral'] : null,
            ),
            'deleteMessage' => $c->deleteMessage($need('channel', 'lobby'), $need('messageId', '1-0')),
            'listChatMutes' => $c->listChatMutes($need('channel', 'c')),
            'muteChat' => $c->muteChat($need('channel', 'lobby'), $need('identity', 'u')),
            'unmuteChat' => $c->unmuteChat($need('channel', 'lobby'), $need('identity', 'u')),
            'createStream' => $c->createStream($need('streamId', 's'), $s('playback'), $metadata),
            'getStream' => $c->getStream($need('streamId', 's')),
            'listStreams' => $c->listStreams(),
            'updateStream' => $c->updateStream(
                $need('streamId', 's'),
                $s('playback', array_key_exists('streamId', $args) ? null : 'public'),
                $metadata,
            ),
            'deleteStream' => $c->deleteStream($need('streamId', 's')),
            'createStreamToken' => $c->createStreamToken(
                $need('streamId', 's'),
                $s('action', array_key_exists('streamId', $args) ? null : 'play'),
                $s('identity'),
                isset($args['ttlSeconds']) && is_int($args['ttlSeconds']) ? $args['ttlSeconds'] : null,
            ),
            'getUsage' => $c->getUsage(),
            'getUsageSeries' => isset($args['days']) && is_int($args['days'])
                ? $c->getUsageSeries($args['days'])
                : $c->getUsageSeries(),
            'getBalance' => $c->getBalance(),
            'getRates' => $c->getRates(),
            default => throw new \InvalidArgumentException("unknown method $method"),
        };
    }

    /**
     * Call `$method` with `$id` in the place `$param`, the other arguments the generator's
     * (server-sdk-vectors.mjs PATH_IDS, mirrored in the Node tests NODE-041-2).
     */
    public static function withId(StreamClient $c, string $method, string $param, string $id): mixed
    {
        $args = match ("$method/$param") {
            'ensureRoom/roomId' => ['roomId' => $id, 'hostIdentity' => 'h'],
            'createToken/roomId' => ['roomId' => $id, 'identity' => 'u', 'role' => 'viewer'],
            'getRoom/roomId', 'listParticipants/roomId', 'deleteRoom/roomId' => ['roomId' => $id],
            'createChatToken/channel' => ['channel' => $id, 'identity' => 'u'],
            'getChatInfo/channel', 'listChatMutes/channel' => ['channel' => $id],
            'listMessages/channel' => ['channel' => $id, 'limit' => 5],
            'sendMessage/channel' => ['channel' => $id, 'text' => 'hi'],
            'deleteMessage/channel' => ['channel' => $id, 'messageId' => '1-0'],
            'deleteMessage/messageId' => ['channel' => 'lobby', 'messageId' => $id],
            'muteChat/channel', 'unmuteChat/channel' => ['channel' => $id, 'identity' => 'u'],
            'muteChat/identity', 'unmuteChat/identity' => ['channel' => 'lobby', 'identity' => $id],
            'getStream/streamId', 'deleteStream/streamId' => ['streamId' => $id],
            'updateStream/streamId' => ['streamId' => $id, 'playback' => 'public'],
            'createStreamToken/streamId' => ['streamId' => $id, 'action' => 'play'],
            default => throw new \InvalidArgumentException("no id place $method/$param"),
        };
        return self::invoke($c, $method, $args);
    }
}
