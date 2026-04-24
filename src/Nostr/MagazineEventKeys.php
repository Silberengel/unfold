<?php

declare(strict_types=1);

namespace App\Nostr;

use swentel\nostr\Key\Key;

/**
 * Stable keys for {@see Event} rows: magazine root/category indices and kind-0 profiles in MySQL.
 */
final class MagazineEventKeys
{
    public static function magazineRoot(string $npub, string $rootDTag): string
    {
        $hex = self::npubToHex($npub);
        if ($hex === '') {
            return '';
        }

        return 'mr:'.$hex.':'.trim($rootDTag, " \0\x0B\t\n\r");
    }

    public static function magazineCategory(string $categoryDTag): string
    {
        return 'mc:'.trim($categoryDTag, " \0\x0B\t\n\r");
    }

    public static function profileKind0(string $authorPubkeyHex64): string
    {
        return 'pr:'.strtolower($authorPubkeyHex64);
    }

    public static function relayList10002(string $authorPubkeyHex64): string
    {
        return 'k10002:'.strtolower($authorPubkeyHex64);
    }

    /**
     * NIP-33 + NIP-A3: kind 10133, pubkey hex, d-tag from the address.
     */
    public static function payto10133(string $authorPubkeyHex64, string $dTag): string
    {
        $d = trim($dTag, " \0\x0B\t\n\r");

        return 'k10133:'.strtolower($authorPubkeyHex64).':'.$d;
    }

    private static function npubToHex(string $npub): string
    {
        if (64 === \strlen($npub) && ctype_xdigit($npub)) {
            return strtolower($npub);
        }
        try {
            $h = (new Key())->convertToHex($npub);
        } catch (\Throwable) {
            $h = '';
        }

        return (\is_string($h) && 64 === \strlen($h) && ctype_xdigit($h)) ? strtolower($h) : '';
    }
}
