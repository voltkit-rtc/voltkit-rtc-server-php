<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;

/** Clients over a fake transport that never sleep between retries. */
trait MakesClients
{
    /** @var list<int> the waits the sleeper was handed, in milliseconds */
    protected array $waits = [];

    protected function client(
        FakeTransport $transport,
        int $retries = 2,
        string $apiKey = 'sk_test',
        string $baseUrl = 'https://x.test/',
        ?string $ratesBaseUrl = null,
        int $timeoutMs = 10000,
    ): StreamClient {
        $this->waits = [];
        // A static closure over a reference, not one bound to $this: printing the client (SecretsTest, the vectors'
        // clientPrinting) would otherwise print the test case, and with it the test runner's whole object graph.
        $waits = &$this->waits;
        return new StreamClient(
            $baseUrl,
            $apiKey,
            $ratesBaseUrl,
            $timeoutMs,
            $retries,
            $transport,
            sleeper: static function (int $ms) use (&$waits): void {
                $waits[] = $ms;
            },
        );
    }

    /** The StreamError a call failed with; the test fails if it resolved. */
    protected function failure(callable $call): StreamError
    {
        try {
            $call();
        } catch (StreamError $e) {
            return $e;
        }
        self::fail('resolved, but it should have failed with a StreamError');
    }

    /** Whether `$text` holds 8 characters in a row of `$secret`: a message cut mid-key leaks the part before the cut. */
    protected static function holdsPartOf(string $text, string $secret): bool
    {
        for ($i = 0; $i + 8 <= strlen($secret); $i++) {
            if (str_contains($text, substr($secret, $i, 8))) {
                return true;
            }
        }
        return strlen($secret) < 8 && $secret !== '' && str_contains($text, $secret);
    }
}
