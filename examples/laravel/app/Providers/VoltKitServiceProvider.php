<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use VoltKit\Rtc\StreamClient;

/** One StreamClient for the application, built from config/services.php. */
final class VoltKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StreamClient::class, static function (mixed $app): StreamClient {
            assert($app instanceof \ArrayAccess);
            $config = $app[Repository::class];
            assert($config instanceof Repository);
            $baseUrl = $config->get('services.voltkit.rtc_url');
            $apiKey = $config->get('services.voltkit.rtc_secret');
            $ratesBaseUrl = $config->get('services.voltkit.api_url');
            if (!is_string($baseUrl) || !is_string($apiKey)) {
                throw new \RuntimeException(
                    'services.voltkit.rtc_url and rtc_secret are required (VOLTKIT_RTC_URL, VOLTKIT_RTC_SECRET)',
                );
            }
            // The constructor refuses a malformed value at boot, far from a user's first call.
            return new StreamClient($baseUrl, $apiKey, is_string($ratesBaseUrl) ? $ratesBaseUrl : null);
        });
    }
}
