<?php

declare(strict_types=1);

namespace App\Nostr;

use App\Service\NostrKeyHelper;

/**
 * Stable keys for {@see Event} rows: magazine root/category indices, kind-0 profiles, and legacy kind-30004
 * curation keys still used by {@see \App\Service\Nip09DeletionApplier} to clean old MySQL rows.
 */
final class MagazineEventKeys
{
    public static function magazineCuration30004(string $npub, string $dTag): string
    {
        $hex = self::npubToHex($npub);
        if ($hex === '') {
            return '';
        }

        return 'mcur:'.$hex.':'.trim($dTag, " \0\x0B\t\n\r");
    }

    /**
     * Same logical row as {@see magazineCuration30004} when `pubkeyHex64` is the site author (from an `a` tag address).
     */
    public static function magazineCuration30004FromPubkeyHex(string $pubkeyHex64, string $dTag): string
    {
        $pk = strtolower(trim($pubkeyHex64));
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return '';
        }

        return 'mcur:'.$pk.':'.trim($dTag, " \0\x0B\t\n\r");
    }

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
            $h = (new NostrKeyHelper())->convertToHex($npub);
        } catch (\Throwable) {
            $h = '';
        }

        return (64 === \strlen($h) && ctype_xdigit($h)) ? strtolower($h) : '';
    }
}
