# Laravel

| File | Where it goes |
| --- | --- |
| `app/Providers/VoltKitServiceProvider.php` | `app/Providers/`, then add it to `bootstrap/providers.php` (Laravel 11+) or `config/app.php` |
| `app/Http/Controllers/RtcTokenController.php` | `app/Http/Controllers/` |
| `app/Http/Controllers/VoltKitWebhookController.php` | `app/Http/Controllers/` |
| `routes/api.php` | the two lines to add to your `routes/api.php` |

Configuration goes in `config/services.php`:

```php
'voltkit' => [
    'rtc_url' => env('VOLTKIT_RTC_URL'),
    'rtc_secret' => env('VOLTKIT_RTC_SECRET'),
    'api_url' => env('VOLTKIT_API_URL'),
    'webhook_secrets' => env('VOLTKIT_RTC_WEBHOOK_SECRETS'),   // "whsec_current" or "whsec_current,whsec_previous"
],
```

and the values in `.env`. The service provider binds one `StreamClient` as a singleton, so controllers
and jobs take it by constructor injection.

**Who gets the token.** The token controller sits behind `auth:sanctum` and mints for `$request->user()`,
never for an identity in the request body: a client that can name its own identity can take anyone's seat.
The role is the backend's decision too; `isHostOf()` is the placeholder for your own lookup.

**The webhook body.** The signature covers the exact bytes VoltKit sent, so the controller reads
`$request->getContent()`, the raw body, and never `$request->all()` or `$request->json()`, which decode
and re-encode. Laravel does not alter `getContent()`; no middleware change is needed. The header comes
from `$request->header('X-Stream-Signature')`. Exclude the route from CSRF verification: it is in
`routes/api.php` here, which has no CSRF middleware; in `routes/web.php` it would need `withoutMiddleware`.
