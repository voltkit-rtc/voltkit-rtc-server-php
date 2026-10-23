<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Internal;

/**
 * The client's secrets: the net API key, and baseUrl and ratesBaseUrl as given, credentials and all,
 * which is where requests go; and the Redactor that knows the key.
 *
 * The values live in a static map keyed by the Secrets object that owns them, not in properties: a
 * dumper that walks an object's properties instead of asking __debugInfo(), as Symfony's VarDumper
 * behind Laravel's dd() and dump() does, or a reflection-based logger or an IDE's variable view,
 * finds nothing here, where a closure's bound variable was printed as `$apiKey: "sk_..."` (review
 * 2026-10-23). var_dump() and print_r() see __debugInfo(), var_export() an object with no
 * properties, serialize() is refused, and json_encode() of the client never reaches this object
 * (spec 2.4). An entry goes when its owner does.
 *
 * @internal
 */
final class Secrets
{
    /** @var ?\WeakMap<self, array{apiKey: string, baseUrl: string, ratesBaseUrl: ?string, redactor: Redactor}> */
    private static ?\WeakMap $vault = null;

    /**
     * @param string $apiKey the net key (ApiKey::net)
     * @param string $baseUrl the controller origin as given, a trailing slash removed
     * @param ?string $ratesBaseUrl the website API origin as given, or null when unset
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        #[\SensitiveParameter] string $baseUrl,
        #[\SensitiveParameter] ?string $ratesBaseUrl,
    ) {
        self::$vault ??= new \WeakMap();
        self::$vault[$this] = [
            'apiKey' => $apiKey,
            'baseUrl' => $baseUrl,
            'ratesBaseUrl' => $ratesBaseUrl,
            'redactor' => new Redactor($apiKey),
        ];
    }

    public function apiKey(): string
    {
        return $this->values()['apiKey'];
    }

    public function baseUrl(): string
    {
        return $this->values()['baseUrl'];
    }

    public function ratesBaseUrl(): ?string
    {
        return $this->values()['ratesBaseUrl'];
    }

    /** `$text` with the key in any form, and any URL's credentials, replaced by [redacted]. */
    public function redact(#[\SensitiveParameter] string $text): string
    {
        return $this->values()['redactor']->redact($text);
    }

    /** @return array{apiKey: string, baseUrl: string, ratesBaseUrl: ?string, redactor: Redactor} */
    private function values(): array
    {
        if (self::$vault === null || !isset(self::$vault[$this])) {
            throw new \LogicException('this VoltKit\Rtc\StreamClient has lost its secrets: make a new client');
        }
        return self::$vault[$this];
    }

    /** A copy would own no entry in the vault; a cloned client shares this object instead. */
    private function __clone()
    {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['apiKey' => Redactor::MARKER, 'baseUrl' => Redactor::MARKER, 'ratesBaseUrl' => Redactor::MARKER];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('a VoltKit\Rtc\StreamClient cannot be serialized: it holds the API key');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('a VoltKit\Rtc\StreamClient cannot be unserialized: it holds the API key');
    }
}
