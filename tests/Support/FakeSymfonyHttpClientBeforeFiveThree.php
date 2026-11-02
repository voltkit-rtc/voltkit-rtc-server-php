<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/** The wrapped client as Symfony had it before 5.3: request() and no withOptions(), so no copy can be made of it. */
final class FakeSymfonyHttpClientBeforeFiveThree
{
    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        FakeSymfonyHttpClient::$served[] = ['url' => $url, 'options' => ['max_redirects' => 20]];
        return new FakeResponse(200, ['Content-Type' => 'application/json'], FakeSymfonyHttpClient::reply($url));
    }
}
