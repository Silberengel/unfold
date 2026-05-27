<?php

declare(strict_types=1);

namespace App\Service;

use swentel\nostr\Key\Key;

/**
 * Shared {@link Key} wrapper for npub/nsec/hex conversions. Prefer injecting this service instead of
 * instantiating {@see Key} in controllers, commands, and services.
 */
final readonly class NostrKeyHelper
{
    private Key $key;

    public function __construct()
    {
        $this->key = new Key();
    }

    public function convertToHex(string $key): string
    {
        return $this->key->convertToHex($key);
    }

    public function convertPublicKeyToBech32(string $key): string
    {
        return $this->key->convertPublicKeyToBech32($key);
    }

}
