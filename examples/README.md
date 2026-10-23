# Examples

Two runnable programs for plain PHP and the same two as a Laravel controller. Each is configured by
environment variables and holds no secret of its own.

| Variable | Meaning |
| --- | --- |
| `VOLTKIT_RTC_URL` | Your API base URL from the project's Credentials tab, e.g. `https://api.voltkit.in` |
| `VOLTKIT_RTC_SECRET` | The project API secret. Server-side only |
| `VOLTKIT_API_URL` | Optional: the website API origin, for `getRates()` |
| `VOLTKIT_RTC_WEBHOOK_SECRETS` | The webhook signing secret (`whsec_...`), or two comma-separated during a rotation: the current one first |

## Plain PHP

From the package folder, after `composer install`:

```bash
export VOLTKIT_RTC_URL=https://api.voltkit.in VOLTKIT_RTC_SECRET=sk_... VOLTKIT_RTC_WEBHOOK_SECRETS=whsec_...
php -S 127.0.0.1:8899 -t examples/plain
```

- `POST http://127.0.0.1:8899/token-server.php` with `{"roomId":"show-42"}` mints a room token for the
  signed-in user with `createToken` and answers `{ token, wsUrl, expiresAt, role }`. The identity comes from
  the session, never from the body; the `X-Demo-User` header stands in for the session here. The role is this
  backend's decision (a placeholder: the owner of the show is the host, everyone else a viewer). Errors are
  mapped by code: `no-room` is 404, the quota codes 402, anything retryable 503 with `Retry-After`, the rest
  500 without details.
- `POST http://127.0.0.1:8899/webhook-receiver.php` is the endpoint to put in the dashboard (through a
  tunnel such as `ssh -R` or ngrok when testing from a laptop). It reads the raw body, verifies with
  every secret in `VOLTKIT_RTC_WEBHOOK_SECRETS`, answers 401 when the signature does not verify, 200 at
  once otherwise, deduplicates on the event id, and only then does its work.

Try them locally:

```bash
curl -s -X POST http://127.0.0.1:8899/token-server.php -H 'Content-Type: application/json' \
  -H 'X-Demo-User: 7' -d '{"roomId":"show-42"}'
```

Without a VoltKit project there is nothing to mint tokens from, but both programs are exercised
against a scratch controller by the repository's live smoke (`scripts/test/php-sdk-live.sh`): it serves
this folder with `php -S` and checks every branch of both endpoints.

## Laravel

`examples/laravel/` holds a token controller and a webhook controller, a route snippet and a
service-provider binding that builds one `StreamClient` from `config/services.php`. Copy the files
into your app; the README there says where each goes. The one thing to get right is the webhook
body: read `$request->getContent()`, the raw bytes, never `$request->all()`.
