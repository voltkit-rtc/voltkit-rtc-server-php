# Changelog

All notable changes to `voltkit/rtc-server` are listed here. The package follows semver; while
0.x, a minor bump may add API, and nothing public is removed or renamed without a deprecation
release first.

## 0.1.0 (prepared 2026-10-23, not yet published)

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
