<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the Symfony HttpClientInterface that a Psr18Client or HttplugClient wraps: its options as given,
 * withOptions() a copy with more of them (Symfony 5.3 and later), request() answering a canned reply and recording
 * which copy served it, options and all. FakeSymfonyHttpClientBeforeFiveThree is the same without withOptions().
 */
final class FakeSymfonyHttpClient
{
    /** @var list<array{url: string, options: array<string, mixed>}> every request, in order, whichever copy served it */
    public static array $served = [];

    /** @param array<string, mixed> $options */
    public function __construct(public readonly array $options = ['max_redirects' => 20])
    {
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): self
    {
        return new self(array_merge($this->options, $options));
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$served[] = ['url' => $url, 'options' => $this->options];
        return new FakeResponse(200, ['Content-Type' => 'application/json'], self::reply($url));
    }

    /** What the call needs of a 2xx: the public rates in their shape, anything else an empty object. */
    public static function reply(string $url): string
    {
        return str_contains($url, '/rates/')
            ? '{"success":true,"data":{"version":4,"kind":"standard","effectiveAt":null,"tiers":[],"surcharges":[],'
                . '"addons":{},"idleTier":"audio_std","modes":[],"notes":[]}}'
            : '{}';
    }
}
