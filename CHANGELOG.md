# Changelog

All notable changes to `voltkit/rtc-server` are listed here. The package follows semver; while
0.x, a minor bump may add API, and nothing public is removed or renamed without a deprecation
release first.

## 0.1.2 (2026-10-31)

From the testers' report of 2026-10-31 (PHP011-1 to PHP011-4, X-1, X-3) and the
lead's decisions D4, D5, D7, D8 and D9 of that round, at parity with `@voltkit-rtc/server` 0.4.10 and its shared
vectors. Each fix has a test that failed on 0.1.1; the vectors' new groups (`replies`, `options.baseUrls`,
`ids.notStrings`, `ids.numbers`, `webhooks.wholeSeconds`, `webhooks.badNow`, `constants.replyShape`) have loops in
`tests/Vectors/VectorsTest.php`.

- `Psr18Transport`: a 3xx the key never follows is decided at its headers and its body is not read, so a stalled body gives `redirected` at once instead of a retried `network` error (independent verification of 2026-10-31, A2). `CurlTransport` already did this.
- **A 2xx reply that is not what the call needs is refused** (PHP011-1, X-1, D4). `createToken` answered 200 `{}`
  returned `[]`, and a JSON list passed where an object belongs (`createRoom` on `[1,2]` returned
  `{"0":1,"1":2,"existed":false}`): `json_decode()` makes `{}` and `[]` the same empty array and `{"0":1}` a list.
  Each typed call now checks the reply, an object told from a list by the JSON itself (a decoding with objects kept
  as objects, gone before the value is decoded, so the memory a reply may take is unchanged): a non-empty string
  `token` (the three token calls), `roomId` (`createRoom`, `ensureRoom`), `id` (`sendMessage`), `streamId`
  (`createStream`, `getStream`, `updateStream`); an object `room` (`getRoom`); an array `rooms` (`listRooms`); a
  string `channel` (`getChatInfo`); an object (`getUsage`, `getBalance`); and for the list calls an object whose
  list, when present, is an array (missing is still `[]`). An empty body is no object. It fails with
  `http-<status>`, not retried, and `<METHOD> <path>: the reply is not the expected shape: "token" is not a
  non-empty string` (or `it is not an object`), quoting nothing of the reply; `existed` is added only after the
  check. Deletes and mutes take any JSON, as before; `getRates` keeps its own check. Unknown fields stay accepted,
  even one whose name starts with a NUL. The message `the reply is not a JSON object: ...` is gone. The examples
  answer 502 on such an error.
- **A bad `baseUrl` or `ratesBaseUrl` is refused when the client is made** (PHP011-4, D8). Whitespace at both ends
  and then one trailing `/` are dropped, as for the key, so `"http://127.0.0.1:19101/\n"` read from a file works;
  it made a client whose every call failed as a retried `network` error (`cURL error 3`). What is left must be an
  absolute `http(s)://` URL naming a host (letters, digits, `-`, `.`, `_`, `~`, or a bracketed IPv6 address: curl
  refuses any other character in one) and a port only from 1 to 65535, with no query or fragment, and only the
  characters `!` to `~` but the backslash: whitespace inside, a control character or a character outside ASCII is
  refused (an IDN host in its `xn--` form). Credentials in it are sent by curl, so they are accepted; curl takes the
  first `@` for their end, so with the curl transport a raw `@` (or a `%00`) inside them is refused and `%40` works.
  Each refusal says what is wrong, ends `(it is not shown here: it may hold credentials)`, quotes no part of the URL
  and has no cause attached. `ratesBaseUrl` with nothing left counts as unset, as `""` did.
- **Neither URL in a stack trace** (PHP011-2). `$baseUrl` and `$ratesBaseUrl`, and every parameter a URL passes
  through on the way to a refusal, are `#[\SensitiveParameter]`: with `zend.exception_ignore_args` off and PHP's
  default `zend.exception_string_param_max_len` of 15, `new StreamClient('deploy:hunter2@x.test', ...)` showed
  `deploy:hunter2@...` in `(string) $e`, and the whole URL in `$e->getTrace()`.
- **Webhooks** (PHP011-3, X-3, PHP011-4, D5). A clock (`now`) that is NaN or infinite throws `\RangeException`, one
  that is no number `\InvalidArgumentException`, before any HMAC: NaN switched the age check off, and a year-old
  delivery verified. Freshness compares whole seconds, `|floor(now) - t| > toleranceSeconds`, so a tolerance of 0
  accepts the current second (a fractional `now` was refused) and 300.999 s old passes the default. A secret that is
  only whitespace counts as absent, like an empty one: it never verifies anything, alone or beside a real one, and
  when none is left the "needs the signing secret" error applies (HMAC took `" "` as a key). `parse` tells an object
  from a list by the JSON: `[]` is refused, `{}` and `{"0":1}` are events.
- **Redaction** (D7, N049-5). The key in a numeric HTML reference with any number of leading zeros (`&#x005F;`,
  `&#0095;`, `&#00000095;`, and escaped once more: `&amp;#x005F;`, `%26%23x005F%3B`) is redacted. URL credentials:
  a password holding `@`, `/`, `?` or `#` is taken out whole (`https://user:hunter2/x@h` becomes
  `https://[redacted]@h`), and `https://h/a@b`, `https://h:8080/a@b` and `https://h?e=a@b` are left as they are.
  The pattern runs per word up to the word's last `@`, so a long page stays linear.
- **A refused redirect's body is not read** (spec 3.5, the PHP case of N049-4). `CurlTransport` read the body of a
  3xx it does not follow to its end, so one that stalled held the request until the deadline, which then failed as
  a retried `network` error; it stops at the headers now, closes the connection, and the call fails with
  `redirected` at once.
- **`Psr18Transport` and Symfony's client** (the report's "worth a look"). Symfony's `Psr18Client` and
  `HttplugClient` follow up to 20 redirects by default and keep every header but `Authorization` and `Cookie`
  across hosts, so a `baseUrl` that redirected handed `X-API-Key` to the other host. A request carrying the key now
  goes through their `withOptions(['max_redirects' => 0])` copy (Symfony 6.2 and later), and fails with
  `redirected`; the public rates still follow through the client as given. Another PSR-18 client must not follow
  redirects (Guzzle's `sendRequest()` never does). Tested against Symfony HttpClient 7.4 (a dev dependency now).
- **Ids** (D6, no change). An id is a `string`: PHP refuses `null`, an array or an object with a `\TypeError`
  before the method runs, and from a file with `strict_types` a number or a boolean too. From a file without it,
  PHP converts first: `42` is sent as `42`, `false` is `""` and refused with `bad-request`, `true` is `"1"`.
- **Packaging** (VK1031-11). Packagist serves GitHub's zipball, which ignores `archive.exclude`: `.gitattributes`
  now marks the tests and the tool configs `export-ignore`, and the package check builds that zipball.

## 0.1.1 (2026-10-28)

- The Music add-on in `getRates()`. `addons` (absent from a price list older than the add-on) carries
  `music`: `price` in credits per 1,000 participant-minutes while music plays (`"10.00"`),
  `cuPerParticipantSecond` as the exact integer (`"1000"`), `unit`, `appliesTo` (`audio_std`, `audio_hd`:
  the voice tiers it is billed on top of), `includedIn` (the video tiers, which include it), `id` and
  `label`; the phpstan type `RtcAddonRate`. Music is an add-on on standard and HD voice (₹10 per 1,000
  participant-minutes while music plays, enabled in the project's Settings → Media and quality); included
  with video. `music` is an add-on id, not a tier: a usage line with `tier: "music"` is the add-on's
  participant-minutes next to the participant's own tier line. Pinned by the shared vectors (the `getRates`
  reply carries `addons.music`). No method changed.
- Reply size caps (the SDK red team of 2026-10-28, finding 10). A reply is read up to 4 MiB
  (`StreamClient::MAX_REPLY_BYTES`) and no further, on a 2xx and on an error status alike, and one under it
  holding more than 100,000 objects and arrays (`StreamClient::MAX_REPLY_CONTAINERS`, each `{` and `[`
  counted) is refused before `json_decode` builds anything. Either fails with `http-<status>`, that status,
  retryable only for a 5xx or 429, and `<METHOD> <path>: the reply is too large: over 4 MiB, the most this
  SDK reads` (or `over 100,000 objects and arrays, the most this SDK decodes`); nothing of the reply is
  quoted. Until now a reply was read and decoded whole: a 10 MB reply of small values took `json_decode`
  past PHP's default `memory_limit` of 128 MB, a fatal error no `catch` handles (a 4 MiB reply of `[0],`
  needs about 243 MB; the costliest reply the limits let through about 85 MB). `CurlTransport` stops
  reading one byte past the cap and does not read a body whose `Content-Length` says more (a redirect it
  follows aside); a caller's `CURLOPT_RETURNTRANSFER`, `FILE`, `WRITEFUNCTION`, `WRITEHEADER` and
  `HEADERFUNCTION` are dropped so the cap holds. `Psr18Transport` reads the PSR-7 body in chunks up to the
  cap (not at all when its size is known to be past it) instead of casting it to a string, and a body that
  fails mid-read is a retryable `network` error. `Response` gains `tooLarge` (an optional constructor
  argument, default `false`) for a transport to say it stopped. A reply under the limits, error bodies
  included (the 300-character cut, the redaction), is handled exactly as before. The Go SDK's cap is 8 MiB;
  PHP's is lower because of `memory_limit`. Tests: `tests/Unit/ReplySizeTest.php`, the reply-cap cases in
  `tests/Transport/CurlTransportTest.php` and `tests/Unit/Psr18TransportTest.php`, and the red team's
  `tests/Unit/RedteamTest.php` (its "a 10 MB label decodes" now expects the cap's error, and a label filling
  a reply to exactly 4 MiB to decode).
- Examples: a token endpoint answers 502 on an incomplete token reply; request bodies are capped at 1 MB.
  `examples/plain/token-server.php` and the Laravel `RtcTokenController` answer 502 with a JSON error when
  `createToken` returns no `token` or `wsUrl` (they handed on nulls or empty strings with a 200), logging
  the room and never the reply. `token-server.php` and `webhook-receiver.php` read at most 1 MB of body and
  answer 413 to more, with a `Content-Length` or chunked; they read `php://input` whole before. Laravel's
  controllers keep the framework's limit: its middleware has decoded a JSON body before a controller runs.
  `tests/Examples/PlainExamplesTest.php` serves `examples/plain` with `php -S` against the loopback
  controller. No library change. From the testers' report of 2026-10-24 (EX-04 and EX-01, found in their
  own servers).

## 0.1.0 (2026-10-23)

First release, built to the shared server SDK specification (`voltkit-rtc/docs/SERVER-SDK-SPEC.md`)
and checked against the vectors generated from `@voltkit-rtc/server` 0.4.8:

- `StreamClient` with every call of the Node SDK: rooms (`createRoom`, `ensureRoom`, `createToken`,
  `getRoom`, `listRooms`, `listParticipants`, `deleteRoom`), chat (`createChatToken`, `getChatInfo`,
  `listMessages`, `sendMessage`, `deleteMessage`, `listChatMutes`, `muteChat`, `unmuteChat`),
  broadcast (`createStream`, `getStream`, `listStreams`, `updateStream`, `deleteStream`,
  `createStreamToken`), usage (`getUsage`, `getUsageSeries`) and billing (`getBalance`, `getRates`),
  returning the wire as arrays.
- `StreamClient::fromEnv()` reading `VOLTKIT_RTC_URL`, `VOLTKIT_RTC_SECRET` and `VOLTKIT_API_URL`.
- A per-attempt deadline covering headers and body, retries with jittered backoff on calls that
  are safe to repeat, creates and sends never repeated, deletes that lost their reply resolving when
  the retry finds the thing gone.
- `StreamError` with `getErrorCode()`, `getStatus()` (also `getCode()`), `isRetryable()` and the
  predicates `isNotFound()`, `isOutOfMinutes()`, `isQuota()`, `isCapacityExhausted()`,
  `isBroadcastDisabled()`, `isBillingUnavailable()`; `ErrorCodes::ALL`, `ErrorCodes::REST` and
  `ErrorCodes::info()` from the shared registry.
- The API key refused when it is whitespace, holds a character no header can carry, a backslash or
  a non-ASCII character; redacted from every message in any encoding a reply can hold, failing
  closed for a key over 256 characters; URL credentials redacted; a redirect never followed with the
  key; ids `""`, `"."` and `".."` refused before sending; the client printing nothing secret.
- `Webhooks::verify()` and `Webhooks::parse()`: the exact header grammar, raw bytes, a tolerance of
  300 s both ways, several secrets for a rotation, constant-time comparison.
- `CurlTransport` (default) and `Psr18Transport` (optional, PSR-18 + PSR-17); an optional PSR-3 logger.
- `User-Agent: voltkit-rtc-php/0.1.0`.

Fixed in the adversarial review before release (2026-10-23, `REVIEW-2026-10-23.md`; each with a test
that failed first and a mutation check):

- A `baseUrl` without a scheme (`api.voltkit.in`, `127.0.0.1:8080`) let curl guess `http://` and send
  the key in clear text; `baseUrl` and `ratesBaseUrl` must now be absolute `http(s)` URLs with a host,
  refused by the constructor otherwise.
- A dumper that reads properties instead of `__debugInfo()` (Symfony VarDumper behind Laravel's `dd()`
  and `dump()`, reflection) printed the key out of the closure that held it, and the URL passwords.
  The client's secrets now live in a `WeakMap` keyed by their holder, so the client's object graph
  holds none of them; printing a transport `Request` redacts URL credentials too.
- With `zend.exception_ignore_args` off, a `StreamError`'s trace carried the unredacted reply, the
  transport's exception and the rates origin as call arguments (so did `serialize($e)`). Errors are
  now built after those have been redacted and dropped.
- A reply built to defeat the redaction matcher (the key's first character a million times) took
  seconds and hundreds of megabytes per megabyte, a fatal under the default `memory_limit`; the
  matcher now indexes alternatives by first byte and caps its memo, so a megabyte costs milliseconds.
- `Psr18Transport` documentation: Symfony HttpClient's `Psr18Client` follows redirects by default and
  needs `max_redirects => 0`; Guzzle's PSR-18 path never follows but has no timeout by default.
- The word rule for keys over 256 characters measures in bytes, so nothing the Node SDK withholds is
  kept here; the retry wait is held at 2^31-1 ms instead of overflowing past 56 retries; `Webhooks`'s
  `now` option takes a number or a closure, no longer any function name; header parts are trimmed of
  the whitespace JavaScript's `trim()` removes.
- Examples: the token endpoints take the identity from the session (`$request->user()` in Laravel, a
  marked stand-in in plain PHP), never from the request body. `require-dev` allows PHPUnit 11 so
  PHP 8.2 can run the suite.
