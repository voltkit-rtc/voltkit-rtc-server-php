<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\PlaybackPolicy;
use VoltKit\Rtc\Role;
use VoltKit\Rtc\StreamAction;
use VoltKit\Rtc\Tests\Support\FakeTransport;
use VoltKit\Rtc\Tests\Support\MakesClients;

/** Spec 6: every method's request and what it resolves to. */
final class MethodsTest extends TestCase
{
    use MakesClients;

    private const ROOM = ['roomId' => 'show-42', 'tenantId' => 'acme', 'originNode' => 'node-3', 'state' => 'live', 'hostIdentity' => 'user-7', 'createdAt' => 1755600000000, 'nodes' => ['node-3'], 'participants' => 0];
    private const ROOM_WITH_TOKEN = self::ROOM + ['hostToken' => 'eyJ.host', 'wsUrl' => 'wss://sfu.example.com/ws', 'expiresAt' => 1755600600];

    /** @return array{0: string, 1: string, 2: ?string} method, url, body of the last request */
    private function sent(FakeTransport $t): array
    {
        $r = $t->last();
        return [$r->method, $r->url, $r->body];
    }

    #[Test]
    public function createRoomPostsAndAddsExistedFalse(): void
    {
        $t = new FakeTransport(['status' => 201, 'body' => self::ROOM_WITH_TOKEN]);
        $room = $this->client($t)->createRoom(hostIdentity: 'user-7', roomId: 'show-42', metadata: ['seats' => 8]);
        self::assertSame(['POST', 'https://x.test/v1/rooms', '{"roomId":"show-42","hostIdentity":"user-7","metadata":{"seats":8}}'], $this->sent($t));
        self::assertSame(self::ROOM_WITH_TOKEN + ['existed' => false], $room);
    }

    #[Test]
    public function ensureRoomPutsAndReportsWhetherTheRoomWasNew(): void
    {
        $t = new FakeTransport(['status' => 201, 'body' => self::ROOM_WITH_TOKEN]);
        $room = $this->client($t)->ensureRoom('show-42', 'user-7');
        self::assertSame(['PUT', 'https://x.test/v1/rooms/show-42', '{"hostIdentity":"user-7"}'], $this->sent($t), 'the id is in the path only');
        self::assertFalse($room['existed']);

        $t = new FakeTransport(['status' => 200, 'body' => self::ROOM_WITH_TOKEN]);
        $room = $this->client($t)->ensureRoom('show-42', 'user-7', ['seats' => 8]);
        self::assertSame('{"hostIdentity":"user-7","metadata":{"seats":8}}', $t->last()->body);
        self::assertTrue($room['existed']);
        self::assertSame('eyJ.host', $room['hostToken']);
    }

    #[Test]
    public function createTokenPostsIdentityAndRoleAndAcceptsTheEnum(): void
    {
        $reply = ['token' => 'eyJ.viewer', 'wsUrl' => 'wss://sfu.example.com/ws', 'role' => 'viewer', 'identity' => 'user-99', 'roomId' => 'show-42', 'expiresAt' => 1755600600];
        $t = new FakeTransport(['status' => 200, 'body' => $reply]);
        $c = $this->client($t);
        self::assertSame($reply, $c->createToken('show-42', 'user-99', 'viewer'));
        self::assertSame(['POST', 'https://x.test/v1/rooms/show-42/tokens', '{"identity":"user-99","role":"viewer"}'], $this->sent($t));
        $c->createToken('show-42', 'user-99', Role::Host);
        self::assertSame('{"identity":"user-99","role":"host"}', $t->last()->body);
    }

    #[Test]
    public function getRoomAndListRoomsReturnTheObjectAsSent(): void
    {
        $view = ['room' => self::ROOM, 'participants' => [['identity' => 'user-7', 'role' => 'host']], 'producers' => []];
        $t = new FakeTransport(['status' => 200, 'body' => $view]);
        self::assertSame($view, $this->client($t)->getRoom('show 42'));
        self::assertSame(['GET', 'https://x.test/v1/rooms/show%2042', null], $this->sent($t));

        $t = new FakeTransport(['status' => 200, 'body' => ['rooms' => [self::ROOM]]]);
        self::assertSame(['rooms' => [self::ROOM]], $this->client($t)->listRooms(), 'an object, not an array (Appendix A.4)');
        self::assertSame(['GET', 'https://x.test/v1/rooms', null], $this->sent($t));
    }

    #[Test]
    public function listParticipantsUnwrapsAndIsEmptyWhenAbsent(): void
    {
        $people = [['identity' => 'user-7', 'role' => 'host', 'nodeId' => 'node-3', 'handRaised' => false, 'muted' => false]];
        $t = new FakeTransport(['status' => 200, 'body' => ['participants' => $people]]);
        self::assertSame($people, $this->client($t)->listParticipants('show-42'));
        self::assertSame(['GET', 'https://x.test/v1/rooms/show-42/participants', null], $this->sent($t));

        $t = new FakeTransport(['status' => 200, 'body' => new \stdClass()]);
        self::assertSame([], $this->client($t)->listParticipants('show-42'));
    }

    #[Test]
    public function deleteRoomDeletesAndResolvesToNothing(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['closed' => true]]);
        $this->client($t)->deleteRoom('show-42');
        self::assertSame(['DELETE', 'https://x.test/v1/rooms/show-42', null], $this->sent($t));
    }

    #[Test]
    public function chatCallsHitTheChannelTheyNameEncoded(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['messages' => [], 'muted' => [], 'token' => 't', 'channel' => 'show 42', 'id' => '1-0']]);
        $c = $this->client($t);
        $c->createChatToken('show 42', 'u', Role::Moderator);
        $c->createChatToken('show 42', 'u');
        $c->getChatInfo('show 42');
        $c->listMessages('show 42', 20, '1755600000000-0');
        $c->deleteMessage('show 42', '1755600000000-0');
        $c->listChatMutes('show 42');
        $c->muteChat('show 42', 'user/7');
        $c->unmuteChat('show 42', 'user/7');
        $seen = array_map(static fn ($r) => "$r->method " . substr($r->url, strlen('https://x.test')), $t->calls);
        self::assertSame([
            'POST /v1/chat/show%2042/tokens',
            'POST /v1/chat/show%2042/tokens',
            'GET /v1/chat/show%2042',
            'GET /v1/chat/show%2042/messages?limit=20&before=1755600000000-0',
            'DELETE /v1/chat/show%2042/messages/1755600000000-0',
            'GET /v1/chat/show%2042/mutes',
            'PUT /v1/chat/show%2042/mutes/user%2F7',
            'DELETE /v1/chat/show%2042/mutes/user%2F7',
        ], $seen);
        self::assertSame('{"identity":"u","role":"moderator"}', $t->calls[0]->body, 'the channel is in the path, never repeated in the body');
        self::assertSame('{"identity":"u"}', $t->calls[1]->body, 'role left out: the server defaults to viewer');
        self::assertNull($t->calls[6]->body, 'a mute has no body');
        self::assertArrayNotHasKey('Content-Type', $t->calls[6]->headers);
    }

    #[Test]
    public function chatRepliesAreUnwrapped(): void
    {
        $t = new FakeTransport(['status' => 200, 'body' => ['channel' => 'c', 'members' => 1284]]);
        self::assertSame(['channel' => 'c', 'members' => 1284], $this->client($t)->getChatInfo('c'));

        $message = ['id' => '1-0', 'channel' => 'c', 'from' => 'system', 'type' => 'system', 'text' => 'hi', 'ts' => 1755600000000];
        $t = new FakeTransport(['status' => 200, 'body' => ['messages' => [$message]]]);
        self::assertSame([$message], $this->client($t)->listMessages('c'));
        $t = new FakeTransport(['status' => 200, 'body' => new \stdClass()]);
        self::assertSame([], $this->client($t)->listMessages('c'));

        $t = new FakeTransport(['status' => 200, 'body' => ['muted' => ['a', 'b']]]);
        self::assertSame(['a', 'b'], $this->client($t)->listChatMutes('c'));
        $t = new FakeTransport(['status' => 200, 'body' => new \stdClass()]);
        self::assertSame([], $this->client($t)->listChatMutes('c'));
    }

    #[Test]
    public function sendMessagePostsEverythingButTheChannelAndReturnsTheStoredMessage(): void
    {
        $stored = ['id' => '1-0', 'channel' => 'c', 'from' => 'user-7', 'type' => 'gift', 'ts' => 1, 'to' => 'user-13'];
        $t = new FakeTransport(['status' => 201, 'body' => $stored]);
        $sent = $this->client($t)->sendMessage('c', type: 'gift', from: 'user-7', to: 'user-13', metadata: ['gift' => 'rose', 'count' => 5]);
        self::assertSame($stored, $sent);
        self::assertSame(['POST', 'https://x.test/v1/chat/c/messages', '{"type":"gift","from":"user-7","to":"user-13","metadata":{"gift":"rose","count":5}}'], $this->sent($t));

        $t = new FakeTransport(['status' => 201, 'body' => $stored]);
        $this->client($t)->sendMessage('c', 'hi', 'system', ephemeral: true);
        self::assertSame('{"text":"hi","type":"system","ephemeral":true}', $t->last()->body);
    }

    #[Test]
    public function streamCallsUseTheRightVerbsPathsAndBodies(): void
    {
        $stream = ['streamId' => 's', 'tenantId' => 'acme', 'streamKey' => 'k', 'state' => 'idle', 'playback' => 'public', 'viewers' => 0, 'playbackUrls' => ['llhls' => 'https://cdn/x.m3u8', 'webrtc' => 'wss://live/x']];
        $t = new FakeTransport(['status' => 200, 'body' => $stream + ['streams' => [$stream], 'token' => 't', 'publish' => ['whipUrl' => 'https://live/x?token=t', 'token' => 't', 'expiresAt' => 1]]]);
        $c = $this->client($t);
        $created = $c->createStream('s', PlaybackPolicy::Token, ['title' => 'Launch']);
        self::assertSame('https://live/x?token=t', $created['publish']['whipUrl']);
        $c->getStream('s');
        $all = $c->listStreams();
        $c->updateStream('s', 'public');
        $c->updateStream('s', metadata: ['title' => 'Encore']);
        $c->createStreamToken('s', StreamAction::Play, 'u', 60);
        $c->createStreamToken('s', 'publish');
        $c->createStreamToken('s');
        $c->deleteStream('s');
        self::assertSame([$stream], $all, 'listStreams unwraps to an array');
        $seen = array_map(static fn ($r) => "$r->method " . substr($r->url, strlen('https://x.test')) . ' ' . ($r->body ?? '-'), $t->calls);
        self::assertSame([
            'POST /v1/streams {"streamId":"s","playback":"token","metadata":{"title":"Launch"}}',
            'GET /v1/streams/s -',
            'GET /v1/streams -',
            'PATCH /v1/streams/s {"playback":"public"}',
            'PATCH /v1/streams/s {"metadata":{"title":"Encore"}}',
            'POST /v1/streams/s/tokens {"action":"play","identity":"u","ttlSeconds":60}',
            'POST /v1/streams/s/tokens {"action":"publish"}',
            'POST /v1/streams/s/tokens {}',
            'DELETE /v1/streams/s -',
        ], $seen);

        $t = new FakeTransport(['status' => 200, 'body' => new \stdClass()]);
        self::assertSame([], $this->client($t)->listStreams());
    }

    #[Test]
    public function usageCallsUnwrapTheSeries(): void
    {
        $summary = ['tenantId' => 'acme', 'plan' => 'metered', 'minutesRemaining' => -1, 'today' => ['roomsCreated' => 1]];
        $t = new FakeTransport(['status' => 200, 'body' => $summary]);
        self::assertSame($summary, $this->client($t)->getUsage());
        self::assertSame(['GET', 'https://x.test/v1/usage', null], $this->sent($t));

        $day = ['day' => '2026-10-03', 'participantSeconds' => 0];
        $t = new FakeTransport(['status' => 200, 'body' => ['tenantId' => 'acme', 'days' => 7, 'series' => [$day]]]);
        self::assertSame([$day], $this->client($t)->getUsageSeries(7));
        self::assertSame(['GET', 'https://x.test/v1/usage/series?days=7', null], $this->sent($t));
        $t = new FakeTransport(['status' => 200, 'body' => ['tenantId' => 'acme']]);
        self::assertSame([], $this->client($t)->getUsageSeries());
    }

    #[Test]
    public function getBalanceKeepsCuAmountsAsStrings(): void
    {
        $balance = [
            'tenantId' => 'acme', 'billingMode' => 'wallet', 'canJoin' => true, 'blocked' => null,
            'legacy' => ['unlimited' => false, 'freeSeconds' => 0, 'ownSeconds' => 60, 'sharedSeconds' => 0, 'totalSeconds' => 60],
            'wallet' => ['spendOrder' => ['project:acme'], 'scopes' => ['project:acme' => ['paidCu' => '60000000', 'promoCu' => '6000000']], 'paidCu' => '180000000', 'promoCu' => '6000000', 'availableCu' => '9007199254740993', 'availableCredits' => '31'],
            'burn' => ['liveParticipants' => 2, 'projectCuPerSecond' => '19800', 'walletCuPerSecond' => '29700'],
            'remainingSeconds' => 6292, 'walletLow' => ['active' => false, 'graceSeconds' => 60], 'pricingVersion' => 3, 'updatedAt' => 1791459600000,
        ];
        $t = new FakeTransport(['status' => 200, 'body' => $balance]);
        $got = $this->client($t)->getBalance();
        self::assertSame($balance, $got);
        self::assertSame('9007199254740993', $got['wallet']['availableCu'], 'never through a float');
        self::assertSame(['GET', 'https://x.test/v1/billing/balance', null], $this->sent($t));
        self::assertSame('sk_test', $t->last()->headers['X-API-Key']);
    }

    #[Test]
    public function getRatesReadsTheWebsiteApiWithNoCredentialAndUnwrapsData(): void
    {
        $rates = ['version' => 3, 'kind' => 'standard', 'tiers' => [['id' => 'audio_std', 'credits' => '39.00', 'cuPerParticipantSecond' => '3900']]];
        $t = new FakeTransport(['status' => 200, 'body' => ['success' => true, 'data' => $rates]]);
        self::assertSame($rates, $this->client($t, ratesBaseUrl: 'https://api.example.com/')->getRates());
        self::assertSame(['GET', 'https://api.example.com/api/v1/billing/rtc/rates/current', null], $this->sent($t));
        self::assertArrayNotHasKey('X-API-Key', $t->last()->headers);
        self::assertStringNotContainsString('sk_test', print_r($t->last(), true));
    }

    #[Test]
    public function getRatesKeepsTheMusicAddonAndDecodesAListWithoutIt(): void
    {
        // The Music add-on (0.1.1): addons.music is kept whole, like a tier row.
        $music = [
            'id' => 'music', 'label' => 'Music add-on', 'price' => '10.00', 'cuPerParticipantSecond' => '1000',
            'unit' => 'credits per 1,000 participant-minutes while music plays',
            'appliesTo' => ['audio_std', 'audio_hd'],
            'includedIn' => ['video_30', 'video_480_60', 'video_720_60', 'video_1080_60'],
        ];
        $rates = ['version' => 4, 'kind' => 'standard', 'tiers' => [], 'addons' => ['music' => $music]];
        $t = new FakeTransport(['status' => 200, 'body' => ['success' => true, 'data' => $rates]]);
        $got = $this->client($t, ratesBaseUrl: 'https://api.example.com')->getRates();
        self::assertSame(['music' => $music], $got['addons'] ?? null);
        // An exact cost while music plays, like a tier's: 4 people for 30 minutes.
        self::assertSame(7200000, (int) $music['cuPerParticipantSecond'] * 4 * 30 * 60);
        $older = new FakeTransport(['status' => 200, 'body' => ['success' => true, 'data' => ['version' => 3, 'kind' => 'standard']]]);
        self::assertArrayNotHasKey('addons', $this->client($older, ratesBaseUrl: 'https://api.example.com')->getRates());
    }

    #[Test]
    public function getRatesOnAReplyWithoutTheEnvelopeFailsWithHttpStatusAndIsNotRetried(): void
    {
        foreach ([['status' => 'ok'], ['success' => true, 'data' => 'nope'], ['success' => true, 'data' => null], ['success' => true]] as $body) {
            $t = new FakeTransport(['status' => 200, 'body' => $body]);
            $e = $this->failure(fn () => $this->client($t, ratesBaseUrl: 'https://api.example.com')->getRates());
            self::assertSame('http-200', $e->getErrorCode());
            self::assertSame(200, $e->getStatus());
            self::assertFalse($e->isRetryable());
            self::assertSame('GET /api/v1/billing/rtc/rates/current: the reply carries no rates; is ratesBaseUrl the website API origin?', $e->getMessage());
            self::assertSame(1, $t->attempts());
        }
    }

    #[Test]
    public function getRatesPassesTheWebsiteApisOwnCodesThrough(): void
    {
        $t = new FakeTransport(['status' => 404, 'body' => ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Route not found'], 'requestId' => 'req_1']]);
        $e = $this->failure(fn () => $this->client($t, ratesBaseUrl: 'https://api.example.com')->getRates());
        self::assertSame('NOT_FOUND', $e->getErrorCode());
        self::assertTrue($e->isNotFound());
        self::assertFalse($e->isRetryable());
        self::assertSame(1, $t->attempts());

        $t = new FakeTransport(['status' => 429, 'body' => ['success' => false, 'error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.']]]);
        $e = $this->failure(fn () => $this->client($t, ratesBaseUrl: 'https://api.example.com', retries: 0)->getRates());
        self::assertSame('RATE_LIMITED', $e->getErrorCode());
        self::assertTrue($e->isRetryable(), 'a 429 may pass');
        self::assertTrue($e->isQuota());
    }

    #[Test]
    public function theEnumsSpellTheWireValues(): void
    {
        self::assertSame(['host', 'cohost', 'moderator', 'viewer'], array_map(static fn (Role $r) => $r->value, Role::cases()));
        self::assertSame(['public', 'token'], array_map(static fn (PlaybackPolicy $p) => $p->value, PlaybackPolicy::cases()));
        self::assertSame(['play', 'publish'], array_map(static fn (StreamAction $a) => $a->value, StreamAction::cases()));
    }
}
