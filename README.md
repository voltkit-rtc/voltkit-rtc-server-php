# voltkit/rtc-server

Server SDK for [VoltKit RTC](https://voltkit.in/rtc) in PHP: rooms, tokens, chat, broadcast
streams, usage, billing and verified webhooks. PHP 8.2 or newer, `ext-curl`, `ext-json` and
`ext-mbstring`, no Composer dependency at runtime.

Your backend does two things: decide who may do what, and hand the client a short-lived token. It
is never in the media path: there is no stream to proxy and nothing that scales with viewers.

```bash
composer require voltkit/rtc-server
```

| | |
| --- | --- |
| [Quickstart](#quickstart) | a client, a room, a token endpoint, in five minutes |
| [Rooms](#rooms) | `ensureRoom` `createRoom` `createToken` `getRoom` `listRooms` `listParticipants` `deleteRoom` |
| [Chat](#chat) | `createChatToken` `sendMessage` `listMessages` `deleteMessage` `muteChat` `unmuteChat` `listChatMutes` `getChatInfo` |
| [Usage](#usage) | `getUsage` `getUsageSeries` |
| [Billing](#billing) | `getBalance` `getRates` |
| [Configuration](#configuration) | options, `fromEnv`, timeouts and retries, your own transport, a logger |
| [Errors](#errors) | `StreamError`, the predicates, what to do for every code, `ErrorCodes` |
| [Retries](#retries) | what is repeated, the calls that never are, deletes that lost their reply |
| [Where your API secret goes](#where-your-api-secret-goes) | one host, no redirects, never in a message or a printed client |
| [Webhooks](#webhooks) | `Webhooks::parse` `Webhooks::verify`, plain PHP and Laravel, rotation |
| [Testing](#testing) | a fake transport |
| [Broadcast](#broadcast) | `createStream` `createStreamToken` `getStream` `listStreams` `updateStream` `deleteStream` (not offered on VoltKit cloud today) |
| [Versions](#versions), [Help](#help) | |

## Quickstart

```php
use VoltKit\Rtc\StreamClient;

$rtc = new StreamClient(
    baseUrl: getenv('VOLTKIT_RTC_URL'),      // your API base URL, from the project's Credentials tab
    apiKey: getenv('VOLTKIT_RTC_SECRET'),    // the project API secret: server-side only, never in a page or an app
);
// or, reading VOLTKIT_RTC_URL, VOLTKIT_RTC_SECRET and the optional VOLTKIT_API_URL for you:
$rtc = StreamClient::fromEnv();
```

Make one client when your application starts and share it: it holds no per-call state. The
constructor checks every option, so a missing or malformed value fails at startup rather than on a
user's first call.

```php
// Create the room, or get it back with a fresh host token if it already exists.
$room = $rtc->ensureRoom(roomId: "show-{$show->id}", hostIdentity: "user-{$owner->id}");
// -> ['roomId' => ..., 'hostToken' => ..., 'wsUrl' => ..., 'expiresAt' => ..., 'existed' => false, ...]

// Let someone in, with the role YOUR backend decides.
$token = $rtc->createToken(roomId: "show-{$show->id}", identity: "user-{$user->id}", role: 'viewer');
// -> ['token' => ..., 'wsUrl' => ..., 'role' => 'viewer', 'identity' => ..., 'roomId' => ..., 'expiresAt' => ...]
```

A token endpoint, the way most backends use the SDK (the whole program is
[`examples/plain/token-server.php`](examples/plain/token-server.php); a Laravel controller is in
[`examples/laravel/`](examples/laravel/)):

```php
use VoltKit\Rtc\Role;
use VoltKit\Rtc\StreamError;

$role = $show->ownerId === $user->id ? Role::Host : Role::Viewer;   // your rule; the token carries it
try {
    $token = $rtc->createToken("show-{$show->id}", "user-{$user->id}", $role);
} catch (StreamError $e) {
    error_log($e->getMessage());                                        // safe: the API secret is never in it
    if ($e->isNotFound()) {
        return json(404, ['error' => 'This show has ended']);
    }
    if ($e->isOutOfMinutes()) {
        return json(402, ['error' => 'This show cannot take viewers right now']);
    }
    if ($e->isRetryable()) {
        return json(503, ['error' => 'Try again in a moment'], ['Retry-After' => '10']);
    }
    return json(500, ['error' => 'Something went wrong']);               // a problem on your side: unauthorized, bad-role
}
return json(200, ['token' => $token['token'], 'wsUrl' => $token['wsUrl'], 'expiresAt' => $token['expiresAt']]);
```

Every reply is the API's JSON as a PHP array with the same camelCase keys (`$room['hostToken']`);
unknown fields pass through, and the PHPDoc array shapes on each method tell your editor what is
there. Optional arguments left `null` are not sent.

## Rooms

A room is the interactive kind of session: everyone in it can be given the right to speak.

```php
$room = $rtc->ensureRoom('show-42', 'user-7');                  // PUT /v1/rooms/show-42: create, or return the live one
$room = $rtc->ensureRoom('show-42', 'user-7', metadata: ['seatFormat' => 20]);
$room = $rtc->createRoom(hostIdentity: 'user-7', roomId: 'show-42');   // POST /v1/rooms: fails with room-exists if taken
$room = $rtc->createRoom(hostIdentity: 'user-7');               // the server makes an id up

$token = $rtc->createToken('show-42', 'user-99', Role::Viewer); // or 'host', 'cohost', 'moderator', 'viewer'

$view = $rtc->getRoom('show-42');                                // ['room' => [...], 'participants' => [...], 'producers' => [...]]
$rooms = $rtc->listRooms();                                     // ['rooms' => [...]]: the object as sent, not unwrapped
$people = $rtc->listParticipants('show-42');                    // [['identity' => ..., 'role' => ..., 'handRaised' => ..., 'muted' => ..., 'joinedAt' => ..., 'metadata' => ...], ...]
$rtc->deleteRoom('show-42');                                    // ends it for everyone
```

- `ensureRoom` is create-or-return in one request; `existed` says which happened, so you can tell
  a new "go live" from a host reconnecting after a dropped call. `createRoom` is the strict version:
  it fails with `room-exists` (409) if the id is taken, and is **never retried** (see [Retries](#retries)).
- `metadata` is any JSON (an array, a `\stdClass`, a `JsonSerializable`) every participant receives
  on join. It is applied only when the room is created; `ensureRoom` on a live room ignores it. An
  empty PHP array is sent as `[]`; pass `new \stdClass()` for `{}`.
- `role` is signed into the token, so the client cannot change it. A token lasts ten minutes; a
  connected client is refreshed by the controller, so a call can run for hours.
- Poll `getRoom` for a live count rather than adding up webhook joins and leaves: delivery is
  at-least-once and unordered. `listParticipants` is a point-in-time read, not a presence feed.

## Chat

Every room has a chat channel of the same name, and a channel needs no room: `show-42` can be chat
for a broadcast, a lobby, or nothing but chat. People in the room already have chat on their room
connection; for anyone else, mint a chat token:

```php
$chat = $rtc->createChatToken('show-42', 'user-99');            // role defaults to viewer on the server
$chat = $rtc->createChatToken('show-42', 'user-99', Role::Moderator);
// -> ['token' => ..., 'wsUrl' => '.../ws/chat', 'role' => ..., 'identity' => ..., 'channel' => ..., 'expiresAt' => ...]
```

Your backend takes part without holding a connection:

```php
$rtc->sendMessage('show-42', text: 'The show starts in 5 minutes', type: 'system');
$rtc->sendMessage('show-42', type: 'gift', from: 'user-7', metadata: ['gift' => 'rose', 'count' => 5]);
$rtc->sendMessage('show-42', type: 'reaction', metadata: ['emoji' => 'heart'], ephemeral: true);   // not stored
$rtc->sendMessage('show-42', to: 'user-13', text: 'Please keep it friendly');                        // a direct message

$messages = $rtc->listMessages('show-42', limit: 100);           // the newest 100, oldest first
$older = $rtc->listMessages('show-42', before: $messages[0]['id']);
$rtc->deleteMessage('show-42', $messages[0]['id']);
$rtc->muteChat('show-42', 'user-13');                            // survives reconnecting; they can still read
$rtc->unmuteChat('show-42', 'user-13');
$muted = $rtc->listChatMutes('show-42');                         // ['user-13']
$info = $rtc->getChatInfo('show-42');                            // ['channel' => 'show-42', 'members' => 1284]
```

`from` defaults to `system`, and you may speak as one of your own users: a gift announcement from
your backend can be trusted in a way a client-sent one cannot. Backend messages skip the
per-connection rate limit and the mute list. `listMessages` returns the newest `limit` messages (50
by default, at most 100), oldest first, direct messages included: your backend owns the
conversation. **`sendMessage` is never retried**: a lost reply would post the message twice.

## Usage

```php
$usage = $rtc->getUsage();
// ['tenantId' => ..., 'plan' => 'metered', 'minutesRemaining' => 990, 'allowance' => [...],
//  'today' => ['participantSeconds' => ..., 'chatMessages' => ..., 'roomsCreated' => ..., ...], 'month' => [...], 'lifetime' => [...]]
$days = $rtc->getUsageSeries(30);                                // one entry per UTC day, oldest first, ending today
```

`minutesRemaining` is `-1` on an unlimited plan. `allowance` says where the account stands: free
minutes left today and this month, the paid balance, the daily and monthly limits, and `blocked`
with the reason when the project cannot be used. `getUsageSeries` goes back up to 90 days; a day
with no usage is present and zero.

## Billing

### getBalance

```php
$balance = $rtc->getBalance();
// ['tenantId' => ..., 'billingMode' => 'wallet', 'canJoin' => true, 'blocked' => null,
//  'legacy' => ['unlimited' => false, 'freeSeconds' => 0, 'ownSeconds' => 60, 'sharedSeconds' => 0, 'totalSeconds' => 60],
//  'wallet' => ['spendOrder' => [...], 'scopes' => [...], 'paidCu' => '180000000', 'promoCu' => '6000000',
//               'availableCu' => '186000000', 'availableCredits' => '31'],
//  'burn' => ['liveParticipants' => 2, 'projectCuPerSecond' => '19800', 'walletCuPerSecond' => '29700'],
//  'remainingSeconds' => 6292, 'walletLow' => ['active' => false, 'graceSeconds' => 60], 'pricingVersion' => 3, 'updatedAt' => ...]

if (!$balance['canJoin']) {
    // 'blocked' is the code a join or a token would be refused with: tenant-suspended, billing-unavailable,
    // quota-exhausted, daily-limit-reached, monthly-limit-reached.
}
```

What your project can spend right now, to show a balance, warn before a call runs out, or decide
whether to start one. Read only: recharges happen in the dashboard. `wallet` is `null` unless the
project is billed from its wallet. **Amounts are strings.** Money is in credit units (cu),
1 credit = 6,000,000 cu, and every cu amount is a decimal string because a float rounds a large
balance without telling you. Add them up with `bcmath` or `gmp`, and show `availableCredits`, which
is already formatted:

```php
$cu = $balance['wallet']['availableCu'] ?? '0';           // '186000000'
$wholeCredits = bcdiv($cu, '6000000', 0);                   // '31'
```

### getRates

```php
$rtc = new StreamClient(baseUrl: ..., apiKey: ..., ratesBaseUrl: 'https://api.voltkit.in');   // the website API origin
$rates = $rtc->getRates();
// ['version' => 3, 'kind' => 'standard', 'effectiveAt' => ..., 'unit' => [...],
//  'tiers' => [['id' => 'video_30', 'credits' => '99.00', 'cuPerParticipantSecond' => '9900', ...], ...],
//  'surcharges' => [...], 'addons' => ['music' => ['price' => '10.00', 'cuPerParticipantSecond' => '1000', 'unit' => ..., 'appliesTo' => [...], 'includedIn' => [...]]],
//  'idleTier' => 'audio_std', 'modes' => [...], 'conversion' => [...], 'notes' => [...]]
```

**The Music add-on (0.1.1).** `$rates['addons']['music']` prices in-app music (`publishMusic` in the
client SDKs): Music is an add-on on standard and HD voice (₹10 per 1,000 participant-minutes while music
plays, enabled in the project's Settings → Media and quality); included with video. `price` is in credits
per 1,000 participant-minutes and `cuPerParticipantSecond` the same as an exact integer; `appliesTo` names
the voice tiers it is billed on top of and `includedIn` the video tiers that include it. `addons` is
optional: a price list from before the add-on has no such key, so read it with `?? null`. `music` is an
add-on id, not a tier: a usage line with `tier: "music"` (the settlement feed, the console's usage, the
invoice) is the add-on's participant-minutes next to the participant's own tier line.

The public price list: each tier in credits per 1,000 participant-minutes, the media modes that
are switched on, and the conversion for new recharges. Prices are set on the website, so this is
read from the website API named by `ratesBaseUrl`, with **no credential**: the API secret only
ever goes to `baseUrl`. Without `ratesBaseUrl`, `getRates` fails at once with `rates-unconfigured`
(status 0) and sends nothing. The website caches the list for 30 seconds; cache it on your side too.
A tier's `cuPerParticipantSecond` is exact, so a cost is integer arithmetic:
`bcmul($tier['cuPerParticipantSecond'], (string) (4 * 30 * 60))` for 4 people for 30 minutes.

## Configuration

```php
new StreamClient(
    baseUrl: 'https://api.voltkit.in',   // required: the controller's exact scheme and host; the key is sent here and nowhere else; whitespace around it is dropped
    apiKey: 'sk_...',                    // required; whitespace around it is dropped
    ratesBaseUrl: null,                  // the website API origin, for getRates(); never sent the key
    timeoutMs: 10000,                    // per attempt, from sending the request to the last byte of the reply
    retries: 2,                          // extra attempts on a transient failure, for calls that are safe to repeat; 0 turns them off
    transport: null,                     // a TransportInterface; the curl one by default
    logger: null,                        // a PSR-3 logger; see below
);
```

Named arguments are the readable way to call it; the positional order is the one above.

- **`StreamClient::fromEnv()`** reads `VOLTKIT_RTC_URL`, `VOLTKIT_RTC_SECRET` and the optional
  `VOLTKIT_API_URL` from `$_SERVER`, `$_ENV` and `getenv()` in that order, so a value a dotenv loader
  put only in the arrays (Laravel) is found. A missing variable is named in the exception; its
  value never is. It takes the same `transport`, `timeoutMs`, `retries` and `logger`.
- **Timeout.** `timeoutMs` (default 10 s) is the deadline for each attempt, headers and body: a
  service that does not answer, or a reply that stalls after its headers, fails with `network` and
  "timed out after 10000ms". It must be above 0; a value past 2^31-1 ms is held there.
- **Retries.** `retries` (default 2) must be 0 or more. A call can take up to
  `(retries + 1) × timeoutMs` plus the waits between attempts (about 200 ms, 400 ms, ... doubling,
  plus up to 100 ms of jitter, held at 2^31-1 ms): about 31 seconds with the defaults. In a request
  a user is waiting on, lower both: `new StreamClient(..., timeoutMs: 5000, retries: 1)`.
- **Mistakes are caught when the client is made.** Whitespace around `baseUrl` and `ratesBaseUrl`
  (spaces, tabs, a line break: a line read from a file) and then one trailing `/` are dropped, as
  for the key. What is left must be a URL a request can use, or the constructor throws
  `\InvalidArgumentException` saying what is wrong (0.1.2; until then such a URL made a client whose
  every call failed as a retried `network` error): an absolute `https://` or `http://` URL (given
  `api.voltkit.in` without a scheme, curl would guess `http://` and send the key in clear text),
  naming a host (letters, digits, `-`, `.`, `_`, `~`, or a bracketed IPv6 address) and a port only as
  a number from 1 to 65535, with no query or fragment, and nothing but printable ASCII: no
  whitespace inside, no control character, no backslash, no character outside ASCII (percent-encode
  it, or give an international host in its `xn--` form). A path prefix is fine. Credentials in the
  URL (`https://user:pass@host`) are sent by curl as Basic credentials, so they are accepted; curl
  takes the first `@` for the end of them, so with the curl transport an `@` inside a name or
  password must be written `%40`, and a raw one is refused. An empty `baseUrl` or `apiKey`, or a
  `baseUrl` with nothing left, throws the same; a `ratesBaseUrl` with nothing left (`""`, `"/"`,
  whitespace) counts as unset. A key that is only whitespace, a key holding a character no HTTP
  header can carry (a line break or a NUL inside it, another control character, anything above
  U+00FF) or one no VoltKit key has (a backslash, an accented letter) throws too. A `timeoutMs` of 0
  or below or negative `retries` throws `\RangeException`; a non-integer is refused by PHP's own
  type check. None of these messages quotes the key or any part of a URL, and with
  `zend.exception_ignore_args` off their stack traces hold neither (both are
  `#[\SensitiveParameter]`).
- **Reply size.** A reply is read up to 4 MiB (`StreamClient::MAX_REPLY_BYTES`) and no further, on a
  2xx and on an error status alike: the curl transport stops reading one byte past it, and does not
  read a body whose `Content-Length` says more. A reply under that holding more than 100,000 objects
  and arrays (`StreamClient::MAX_REPLY_CONTAINERS`, each `{` and `[` counted) is refused too, before
  `json_decode` builds anything. Either fails with `http-<status>`, that status, `isRetryable()` true
  only for a 5xx or 429, and the message `GET /v1/rooms: the reply is too large: over 4 MiB, the most
  this SDK reads` (or `over 100,000 objects and arrays, the most this SDK decodes`); nothing of the
  reply is quoted. Why: `json_decode` turns a small value into 16 bytes and an object or array into
  200 to 450, so a 10 MB reply of small values needs more than PHP's default `memory_limit` of
  128 MB, which ends the worker with a fatal error no `catch` can handle. The largest real reply (a
  room of thousands of participants, every live room of a project) is well under both limits, and
  the costliest reply they let through takes about 85 MB. The Go SDK's cap is 8 MiB.

### Your own transport

`VoltKit\Rtc\Transport\TransportInterface` has one method, `send(Request $request): Response`, and
two implementations:

```php
use VoltKit\Rtc\Transport\CurlTransport;
use VoltKit\Rtc\Transport\Psr18Transport;

// The default, with extra curl options: a proxy, a CA bundle, an interface to bind.
$transport = new CurlTransport([CURLOPT_PROXY => 'http://proxy.internal:3128', CURLOPT_CAINFO => '/etc/ssl/certs/ca.pem']);

// Any PSR-18 client with PSR-17 factories (composer require psr/http-client psr/http-factory and an implementation).
$transport = new Psr18Transport(new \GuzzleHttp\Client(['timeout' => 10]), $psr17Factory, $psr17Factory);
$transport = new Psr18Transport(
    new \Symfony\Component\HttpClient\Psr18Client(\Symfony\Component\HttpClient\HttpClient::create(['timeout' => 10])),
    $psr17Factory,
    $psr17Factory,
);

$rtc = new StreamClient(baseUrl: ..., apiKey: ..., transport: $transport);
```

The curl transport's `CURLOPT_TIMEOUT_MS` covers the whole exchange and it never follows a
redirect on a request that carries the key (it does not even read the redirect's body); options
you pass that would change that are overridden. A PSR-18 client decides those two things itself.
**Redirects:** a client that follows one has already sent `X-API-Key` to the host it named before
the SDK sees the answer. Symfony's `Psr18Client` and `HttplugClient` follow up to 20 by default
and keep every header but `Authorization` and `Cookie` across hosts, so for a request carrying the
key the adapter sends through their `withOptions(['max_redirects' => 0])` copy (Symfony 6.2 and
later; 0.1.2), and `getRates()` through the client as you built it. Guzzle's PSR-18
`sendRequest()` never follows. **Any other PSR-18 client must be built not to follow redirects**:
the adapter cannot tell. **A total timeout** covering the reply is yours to set too (Guzzle:
`timeout`, of which it has none by default), ideally the same value as `timeoutMs`. A
`Request` is a credential: it carries the real `X-API-Key` for the transport, and the URL as given.
`print_r`, `var_dump` and `json_encode` of one show the key and any URL password as `[redacted]`;
a dumper that reads properties (Laravel's `dd()`) shows them as they are, so never dump or log a
`Request`.

Both transports read at most 4 MiB of a body. The PSR-18 adapter reads the PSR-7 body in chunks and
not at all when its size is known to be past the cap, but the client may already have buffered the
whole reply (Guzzle and Symfony do by default): to stop a large reply in transit, use the client's
own limit (Guzzle's `on_headers` or `progress` option, Symfony's `on_progress`). Your own transport
should stop reading past `StreamClient::MAX_REPLY_BYTES` and return `new Response($status, $headers,
'', tooLarge: true)`; one that hands a longer body over whole is refused by the client all the same.

### A logger

```php
$rtc = new StreamClient(baseUrl: ..., apiKey: ..., logger: $psrLogger);
```

Nothing is logged by default. With a PSR-3 logger (`composer require psr/log` and any
implementation), every attempt is logged at `debug` with exactly `method`, `path`, `status`,
`attempt` and `elapsedMs` in the context: never a header, a body, a token, a URL with credentials or
the key.

## Errors

A call that fails throws `VoltKit\Rtc\StreamError`, a `\RuntimeException`. Branch on the code, and
log the message. A mistake in your own setup is an `\InvalidArgumentException`, a `\RangeException`
or a `\TypeError` instead, raised before anything is sent, so a handler cannot mistake it for an
answer from VoltKit.

```php
use VoltKit\Rtc\StreamError;

try {
    $room = $rtc->ensureRoom($roomId, $hostIdentity);
} catch (StreamError $e) {
    $e->getErrorCode();     // 'no-room', 'quota-exhausted', 'network', 'http-502', 'redirected', ...
    $e->getStatus();        // the HTTP status; 0 when nothing reached the service; the 3xx for redirected
    $e->getCode();          // the same status: \Exception::$code is an int, so the string code is getErrorCode()
    $e->isRetryable();      // true for network, 429 and 5xx (except billing-unavailable): the SDK has already retried
    $e->getMessage();       // 'PUT /v1/rooms/show-42: room quota exhausted': the method and path, then what went wrong
}
```

The fields are also public readonly properties: `$e->errorCode`, `$e->status`, `$e->retryable`.

| Predicate | True when |
| --- | --- |
| `isNotFound()` | `status` is 404: no such room, stream or message, or not yours. `broadcast-disabled` and `chat-disabled` are 404s too, so test `isBroadcastDisabled()` first |
| `isOutOfMinutes()` | `status` is 402, or the code is `quota-exhausted`, `daily-limit-reached` or `monthly-limit-reached` |
| `isQuota()` | the code is `room-quota`, or `status` is 429: `rate-limited`, `viewer-quota` and `stream-quota` included |
| `isCapacityExhausted()` | `capacity-exhausted` or `no-nodes`: no capacity for a new room right now. The one to alert on |
| `isBroadcastDisabled()` | `broadcast-disabled`: broadcast is not switched on for this deployment |
| `isBillingUnavailable()` | `billing-unavailable`: the project is billed from the wallet and no price list is active, or the service has no wallet billing |

### What to do, code by code

Every code a call can fail with. "Retried" means the SDK already tried again on a call that is safe
to repeat; `createRoom`, `createStream` and `sendMessage` are [sent once](#calls-that-are-never-repeated).

| Code | Status | Retried | What it means, and what to do |
| --- | --- | --- | --- |
| `unauthorized` | 401 | no | Wrong or revoked secret, or `baseUrl` is not this project's. Check `VOLTKIT_RTC_SECRET`; alert yourself, and tell the user only that something failed. A cheap read at startup, such as `getUsage()`, catches it before your users do |
| `tenant-suspended` | 403 | no | The account is suspended. Contact support |
| `operator-only` | 403 | no | The call is for the operator's own project. Not something an application does |
| `quota-exhausted` | 402 | no | Out of minutes or credits. The project owner recharges; `getBalance()` says so before anyone tries |
| `daily-limit-reached` | 402 | no | The daily usage cap is reached; it resets at midnight UTC |
| `monthly-limit-reached` | 402 | no | The monthly usage cap is reached; it resets on the first of the month |
| `billing-unavailable` | 503 | **no** | Wallet billing is not ready (no active price list, or none on this service). An operator fixes it, not a retry. Contact support |
| `no-room` | 404 | no | The room is gone, or was never yours. Treat it as ended |
| `no-message` | 404 | no | The message is gone. Nothing to do |
| `not-found` | 404 | no | The mute, or whatever was named, is not there. Nothing to do |
| `no-stream` | 404 | no | The stream is gone. Treat it as ended |
| `broadcast-disabled` | 404 | no | Broadcast is not available on this deployment (VoltKit cloud today). Do not offer it |
| `chat-disabled` | 404 | no | Chat is not available on this deployment |
| `room-exists` | 409 | no | The id is taken. Use `ensureRoom`, or another id |
| `stream-exists` | 409 | no | The id is taken. Use the existing stream, or another id |
| `bad-request` | 400 | no | Fix the call: the message names the field. With status 0, the SDK refused an id of `""`, `"."` or `".."` before sending. An id is a `string`: PHP itself refuses `null`, an array or an object with a `\TypeError` before anything is sent, and a file with `declare(strict_types=1)` a number or a boolean too. A file without it gets PHP's conversion: `42` is sent as `42`, `false` is `""` and refused, and `true` is `"1"`, a valid id: declare strict types where your ids come from |
| `bad-role` | 400 | no | The role is not host, cohost, moderator or viewer |
| `bad-signature` | 401 | no | From `Webhooks::parse`: the delivery did not verify. Answer 401; VoltKit retries |
| `rate-limited` | 429 | yes | Your project's API rate limit (600 calls a minute by default). Slow down; spread the calls |
| `room-quota` | 429 | yes | Your concurrent-room limit. End rooms you no longer need, or ask for a higher limit |
| `viewer-quota` | 429 | yes | The room is full. Tell the viewer |
| `stream-quota` | 429 | yes | Your concurrent-stream limit. End streams you no longer need |
| `internal` | 500 | yes | A fault on the service. Try again later; tell us if it repeats |
| `unavailable` | 503 | yes | A dependency of the service could not be read. Try again shortly |
| `no-nodes` | 503 | yes | No media node is registered. Try again shortly, and alert: new rooms are failing |
| `capacity-exhausted` | 503 | yes | Every media node is full. Try again shortly, and alert |
| `network` | 0 | yes | No reply: DNS, a refused or dropped connection, the timeout, a body that broke off. Try again later; if it persists, check your server's outbound network |
| `rates-unconfigured` | 0 | no | `getRates()` without `ratesBaseUrl`. Pass the website API origin |
| `redirected` | 3xx | no | `baseUrl` redirects. Fix it: see [Redirects are never followed](#redirects-are-never-followed) |
| `http-<status>` | that status | 429 and 5xx | A reply that named no code: a proxy's error page, a 2xx that is not JSON, a 2xx that is not what the call needs ("the reply is not the expected shape": no `token` from `createToken`, a list where an object belongs; 0.1.2), or a reply past the size limits ("the reply is too large", see [Configuration](#configuration)). Check `baseUrl` and anything between your server and VoltKit |
| `RATE_LIMITED`, `NOT_FOUND`, ... | | per status | The website API's own codes, from `getRates()`, passed through |

When an error reaches you with `isRetryable()` true, the SDK has already spent its retries. Try
again later, from a queue or the user's next action, rather than at once in a loop.

### The error registry

Every code a VoltKit RTC SDK can report is in one shared registry, copied here in its order:

```php
use VoltKit\Rtc\ErrorCodes;

ErrorCodes::ALL;                      // every code, 105 today
ErrorCodes::REST;                     // the ones a call from this SDK can fail with, 29 today
ErrorCodes::info('no-room');          // ErrorInfo { origin: 'server', http: 404, terminal: true, retryable: false, rest: true }, or null
ErrorCodes::isKnown($e->getErrorCode());   // a bounded label for your metrics: known codes, or 'other'
```

A newer service may answer with a code this release does not know; it is passed through
unchanged, and `isRetryable()` follows the status.

## Retries

Transient failures are retried with jittered backoff on calls where repeating is harmless: token
minting, reads (`getBalance` and `getRates` included), `ensureRoom`, mutes and deletes. Transient
means a `network` failure (no answer, a dropped connection, the timeout, a body that broke off), a
429, or a 5xx other than `billing-unavailable`. The waits are about 200 ms, 400 ms, 800 ms, doubling,
plus up to 100 ms at random, so a fleet of servers does not retry in step. Not retried: any other
4xx (the service made a decision and will make it again), `billing-unavailable`, `redirected`, a
2xx that is not JSON or not what the call needs, and every error raised before sending.

### Calls that are never repeated

`createRoom`, `createStream` and `sendMessage` are sent once, however they fail. If a reply is
lost, repeating them would turn a success into a spurious `room-exists` or `stream-exists`, or post
the message twice. When one of them fails with `isRetryable()` true, you decide:

- A 429, whatever its code, is a refusal: nothing was done. Send it again after a pause.
- A `network` error or a 5xx may have done the work before the reply was lost. Check first:

| Call | Check, then repeat |
| --- | --- |
| `createRoom` | Call `ensureRoom` with the same `roomId`: it returns the room if your call made it, or makes it. Use ids nobody else uses, such as a UUID |
| `createStream` | Call `getStream($streamId)`. If it is there, mint a publish URL with `createStreamToken($streamId, 'publish')`, since only `createStream` returns one. If it fails with `isNotFound()`, create it again |
| `sendMessage` | Put your own id in `metadata`, and look for it in `listMessages($channel)` before sending again. An `ephemeral` message is never stored, so it cannot be found: send it again or drop it |

```php
function announceGift(StreamClient $rtc, string $channel, array $gift): array
{
    $send = fn () => $rtc->sendMessage($channel, type: 'gift', from: $gift['from'], metadata: ['giftId' => $gift['id'], 'gift' => $gift['name']]);
    try {
        return $send();
    } catch (StreamError $e) {
        if (!$e->isRetryable()) {
            throw $e;
        }
        if ($e->getStatus() !== 429) {
            // It may have been stored before the reply was lost: look before sending again.
            foreach ($rtc->listMessages($channel, limit: 100) as $message) {
                if (($message['metadata']['giftId'] ?? null) === $gift['id']) {
                    return $message;
                }
            }
        }
        usleep(1_000_000);
        return $send();
    }
}
```

### Deletes that lost their reply

`deleteRoom`, `deleteMessage`, `unmuteChat` and `deleteStream` hold across a lost reply. When an
attempt fails in a way that may have reached the service (a `network` error or a 5xx) and the
retry answers 404, the first attempt did the deleting, so the call returns normally. A 404 on the
first attempt still throws, as does one after a 429 (which deleted nothing) or one saying chat or
broadcast is switched off. To treat "already gone" as done:

```php
try {
    $rtc->deleteRoom($roomId);
} catch (StreamError $e) {
    if (!$e->isNotFound()) {
        throw $e;   // it had already ended
    }
}
```

## Where your API secret goes

The SDK sends the secret in the `X-API-Key` header of requests to `baseUrl`, and nowhere else:
`getRates()` sends nothing to `ratesBaseUrl`, a redirect is never followed with it, and it never
appears in an error or in the client object.

### Redirects are never followed

A request that carries the secret **never follows a redirect**: HTTP clients keep custom headers
across a redirect to another host, so following one would hand `X-API-Key` to whatever host it
named. A 3xx answer fails at once with the code `redirected` (`getStatus()` is the 3xx, the message
names where it pointed) and is not retried, because the same `baseUrl` redirects the same way. If
your `baseUrl` reached the service through a redirect (`http://` upgraded to `https://`, a `www.`
rewrite), set it to the exact scheme and host the Credentials tab shows. `getRates()` sends no
secret and may follow redirects.

### Safe to log

An error never carries the secret, or the credentials of a URL (`user:pass@` in `baseUrl` or
`ratesBaseUrl`), so `error_log($e->getMessage())` and your logger can take it whole. Wherever the
transport, a dropped connection, a redirect or the service's reply quotes either one, the message
and the code say `[redacted]` instead, however it was encoded: as is, URL-encoded by any encoder in
either hex case, form-encoded, JSON-escaped (`\/`, `/`), as HTML character references, named
or numeric, or any of those escaped once more (`%252F`, `\\\/`, `\&quot;`). The key is looked for if
it has at most 256 characters (a VoltKit key has 67). For a longer key, words at least as long as
it (in bytes) are shown as `[not shown: it could not be checked for the API key]` and shorter
words, such as error codes, are kept; if such a key holds a tab or a space, the whole message is
withheld unless it is shorter than the key. A long message is cut only after that, so a cut cannot
leave part of the secret behind.

The client object keeps its secrets out of sight too. The key, the URLs as given and the redaction
tables are not properties of any object the client holds (they live in a `WeakMap` keyed by the
client's secret holder), so a dumper that walks properties finds nothing: Laravel's `dd($rtc)` and
`dump($rtc)` (Symfony VarDumper), a reflection-based logger, an IDE's variable view. `var_dump($rtc)`,
`print_r($rtc)`, `json_encode($rtc)` (`jsonSerialize`), `(string) $rtc` (`__toString`),
`debug_zval_dump` (`__debugInfo`) and `var_export($rtc)` show `baseUrl` and `ratesBaseUrl` with any
credentials as `[redacted]` and no part of the key; `serialize($rtc)` and `unserialize()` of one throw a
`\LogicException` (`__serialize`, `__unserialize`), since a serialized client would carry the key.
Requests still go to the URLs as given, credentials included. The arguments a stack trace records
(with `zend.exception_ignore_args` off) hold neither the service's reply, nor the transport's error,
nor an origin: a `StreamError` is built after those have been redacted and dropped, so an error
reporter that renders arguments sees what the message shows. The constructor's `baseUrl`,
`ratesBaseUrl` and `apiKey`, and every parameter a URL or the key passes through on the way to a
refusal, are `#[\SensitiveParameter]`: a trace shows them as `SensitiveParameterValue`, which PHP
refuses to `serialize()`. Anyone with
your source and your environment can still read the secret: keep it in the environment or a secrets
manager, never in code.

What a call returns is another matter: `token`, `hostToken` and `publish.whipUrl` are credentials
for one room or stream. Hand each to the one client that needs it, and never log them.

## Webhooks

VoltKit signs every delivery: `X-Stream-Signature: t=<unix seconds>,v1=<hex>`, HMAC-SHA256 over
`<t>.<raw body>` with your signing secret. Verify it from the **raw body**, answer first, then do
the work.

Plain PHP (the whole program is [`examples/plain/webhook-receiver.php`](examples/plain/webhook-receiver.php)):

```php
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Webhooks;

$rawBody = file_get_contents('php://input');              // the bytes as received: the signature covers them
$header = $_SERVER['HTTP_X_STREAM_SIGNATURE'] ?? null;
$secrets = [getenv('VOLTKIT_RTC_WEBHOOK_SECRET')];          // add the previous secret during a rotation

try {
    $event = Webhooks::parse($rawBody, $header, $secrets);   // ['id' => ..., 'type' => ..., 'tenantId' => ..., 'roomId' => ..., 'createdAt' => ..., 'data' => [...]]
} catch (StreamError $e) {
    http_response_code(401);                                 // bad-signature: not from VoltKit, or too old; VoltKit retries
    exit;
}
http_response_code(200);                                     // answer first: a slow handler is retried
fastcgi_finish_request();
if (markSeen($event['id'])) {                                // deduplicate: delivery is at-least-once
    handle($event);
}
```

Laravel (the controller, a provider and the route are in [`examples/laravel/`](examples/laravel/)):

```php
public function __invoke(Request $request): Response
{
    try {
        $event = Webhooks::parse($request->getContent(), $request->header('X-Stream-Signature'), config('services.voltkit.webhook_secrets'));
    } catch (StreamError) {
        return new Response('', 401);
    }
    if (Cache::add('voltkit-webhook:' . $event['id'], true, 86400)) {
        HandleVoltKitEvent::dispatch($event);
    }
    return new Response('', 200);
}
```

- **Pass the raw body.** `$request->getContent()` in Laravel and Symfony, `php://input` in plain
  PHP, `(string) $request->getBody()` with PSR-7. Never `$request->all()`, `json_decode` and back,
  or anything that re-encodes: the bytes change and nothing will ever verify. A body that is not a
  string is refused with a `\TypeError`, never a quiet `false` that looks like an attack.
- **What it checks.** The timestamp must be within `toleranceSeconds` of your clock (default 300,
  both directions, inclusive), so a captured request cannot be replayed later; whole seconds are
  compared (your clock's second against the header's), so 300.9 s old passes and `0` accepts the
  current second. A `v1` signature must match one of your secrets, compared in constant time
  (`hash_equals`), every secret against every signature with no early return. Widen the window with
  `Webhooks::parse($body, $header, $secrets, ['toleranceSeconds' => 600])`. A negative or `NAN`
  tolerance throws a `\RangeException`, and a string is not coerced (`''` from an unset variable
  would be a window of nothing): pass a number.
- **Secrets.** A secret that is empty or only whitespace (a blank line from a file) is no secret: it
  never verifies anything, alone or in a list (0.1.2: HMAC took `" "` as a key, and a delivery
  signed with it verified), and when none is left the call throws as with no secret. Whitespace
  around real text is part of the secret: pass it exactly as the dashboard shows it.
- **What it throws.** `StreamError` with code `bad-signature` (status 401) when the delivery does
  not verify: answer 401, and VoltKit retries at 5 s, 30 s, 2 min, 10 min and 30 min. An
  `\InvalidArgumentException` (no secret, an unknown option), a `\RangeException` or a `\TypeError`
  is a mistake in your setup: let it reach your error handler.
- **`Webhooks::verify`** takes the same arguments and returns `true` or `false` instead of the event.
  The header may be a string, a list of strings (joined with commas) or `null`; the header grammar is
  exact: at most 4096 characters, one `t` of up to 15 digits, each `v1` exactly 64 lower-case hex
  characters, unknown schemes skipped, anything else invalid.
- For tests, the clock is an option: `['now' => 1787000000]` (a fraction is fine) or a closure
  returning unix seconds. `NAN` or an infinity throws a `\RangeException`, anything but a number an
  `\InvalidArgumentException` (0.1.2: `NAN` switched the age check off, so a captured delivery
  verified forever).

Three things to get right: **answer first** (a slow handler is retried, so your latency becomes
duplicate deliveries); **deduplicate on `id`** (delivery is at-least-once by design); **do not assume
order** (a `participant.left` can arrive before the `participant.joined` it followed; pair them by
`identity` and `joinedAt`). Every event type and its fields are at https://voltkit.in/docs/webhooks.

### Rotating the signing secret

When you rotate the secret in the dashboard with an overlap (24 hours by default), VoltKit signs
every delivery with both the new and the previous secret until the overlap ends:
`t=<ts>,v1=<new>,v1=<previous>`. `parse` and `verify` accept a delivery when any `v1` matches any
secret you pass, so:

1. Deploy a handler that passes a list, `[getenv('VOLTKIT_RTC_WEBHOOK_SECRET'), getenv('VOLTKIT_RTC_WEBHOOK_SECRET_OLD')]`
   filtered of empty values. With the old one unset it verifies exactly as before.
2. Rotate. Move the current secret to `VOLTKIT_RTC_WEBHOOK_SECRET_OLD`, put the new one in
   `VOLTKIT_RTC_WEBHOOK_SECRET`, deploy before the overlap ends.
3. After the overlap, remove the old one.

## Testing

Pass your own transport to test your code without a network. The transport is handed a
`VoltKit\Rtc\Transport\Request` (`method`, `url`, `headers`, `body`, `timeoutMs`, `followRedirects`)
and returns a `VoltKit\Rtc\Transport\Response` (`status`, `headers`, `body`), or throws a
`TransportException` for a failure in transit:

```php
use VoltKit\Rtc\Transport\{Request, Response, TransportInterface};

final class FakeTransport implements TransportInterface
{
    public array $calls = [];

    public function send(Request $request): Response
    {
        $this->calls[] = "$request->method $request->url";
        return new Response(404, [], '{"error":"no-room","message":"room not found"}');
    }
}

$transport = new FakeTransport();
$rtc = new StreamClient(baseUrl: 'https://rtc.test', apiKey: 'sk_test', retries: 0, transport: $transport);
try {
    $rtc->createToken('show-42', 'user-99', 'viewer');
} catch (StreamError $e) {
    $e->isNotFound();      // true
}
$transport->calls;         // ['POST https://rtc.test/v1/rooms/show-42/tokens']
```

Answer with the service's error body, `{"error": "<code>", "message": "..."}`, and its status to
drive each branch of your error handling. Pass `retries: 0` so a failing test stays fast, or a
`sleeper` closure as the last constructor argument to observe the backoff without waiting.

## Broadcast

Not offered on VoltKit cloud today: on a deployment without a media server every one of these
calls fails with `broadcast-disabled` (`isBroadcastDisabled()`). They are here for parity with the
API and the other SDKs.

```php
use VoltKit\Rtc\PlaybackPolicy;
use VoltKit\Rtc\StreamAction;

$live = $rtc->createStream('show-42', PlaybackPolicy::Public);          // never retried, like createRoom
// -> ['streamId' => ..., 'streamKey' => ..., 'state' => 'idle', 'playbackUrls' => ['llhls' => ..., 'webrtc' => ...],
//     'publish' => ['whipUrl' => ..., 'token' => ..., 'expiresAt' => ...]]   publish is returned ONLY here

$pub = $rtc->createStreamToken('show-42', StreamAction::Publish);        // a fresh publish URL for an encoder
$play = $rtc->createStreamToken('show-42', 'play', identity: 'user-99', ttlSeconds: 3600);   // for a token-protected stream
$stream = $rtc->getStream('show-42');                                   // state, viewers, peakViewers; never publish
$streams = $rtc->listStreams();
$stream = $rtc->updateStream('show-42', playback: PlaybackPolicy::Token);
$rtc->deleteStream('show-42');                                          // takes it off air
```

`publish.whipUrl` carries the publish token: treat it like a password. `ttlSeconds` is capped at 24
hours. `viewers` counts the playback sessions the media server serves itself, not an audience
behind a CDN.

## Versions

| Version | What changed |
| --- | --- |
| 0.1.2 | Prepared 2026-10-31, not published. A 2xx that is not what the call needs is refused with `http-<status>`, not retried (a list never passes as an object); a bad `baseUrl` or `ratesBaseUrl` is refused when the client is made, whitespace around it dropped; neither URL in a stack trace; webhooks: whole seconds, a clock that is no finite number refused, a whitespace-only secret is none, `parse` tells `{}` from `[]`; the key's numeric HTML references with any number of leading zeros redacted, URL passwords holding `@ / ? #`; a refused redirect's body is not read; `Psr18Transport` turns redirects off on a Symfony client for requests carrying the key; the Packagist download leaves out the tests and tool configs (`CHANGELOG.md`) |
| 0.1.1 | Published 2026-10-28. The Music add-on in `getRates()`: `addons.music` (`price`, `cuPerParticipantSecond`, `unit`, `appliesTo`, `includedIn`, `id`, `label`; absent from an older price list); `music` is an add-on id, not a tier. A reply is read up to 4 MiB and 100,000 objects and arrays, past which it fails with `http-<status>` instead of exhausting `memory_limit` (a fatal error) in `json_decode`. The examples' 502 on an incomplete token reply and 1 MB body cap (`CHANGELOG.md`). No method changed |
| 0.1.0 | First release, to the shared server SDK specification; the same behaviour as `@voltkit-rtc/server` 0.4.8, checked against its vectors, and reviewed adversarially before release (`CHANGELOG.md`) |

The package follows semver. While 0.x, a minor bump may add API; nothing public is removed or
renamed without a deprecation release first. `StreamClient::VERSION` is the version at runtime, and
every request carries `User-Agent: voltkit-rtc-php/<version>`.

## Help

Documentation: https://voltkit.in/docs/php-sdk. Questions and bug reports: contact@voltkit.in.
Send the error's code and message, which never contain your secret, and never the secret itself.
