<?php

declare(strict_types=1);

namespace App\Nostr;

use App\Entity\Event;
use nostriphant\NIP19\Bech32;

/**
 * NIP-33 / NIP-19 helpers: naddr for parameterized replaceable events (kind:pubkey:d).
 */
final class Nip19Addressable
{
    /**
     * NIP-33 replaceable kinds (30000–39999) use a `d` tag; encode as naddr, not nevent, for clients.
     */
    public static function isParameterizedReplaceableKind(int $kind): bool
    {
        return $kind >= 30_000 && $kind < 40_000;
    }

    /**
     * @param array<int, mixed> $tagRows
     */
    public static function dTagFromTagRows(array $tagRows): ?string
    {
        foreach ($tagRows as $row) {
            if (!\is_array($row) && !\is_object($row)) {
                continue;
            }
            if (\is_object($row)) {
                $row = (array) $row;
            }
            $row = array_values($row);
            if ($row === []) {
                continue;
            }
            if (strtolower((string) ($row[0] ?? '')) === 'd' && isset($row[1])) {
                $d = (string) $row[1];
                if ($d !== '') {
                    return $d;
                }
            }
        }

        return null;
    }

    public static function dTagFromEventEntity(Event $e): ?string
    {
        return self::dTagFromTagRows($e->getTags());
    }

    public static function naddrBech32(
        int $kind,
        string $pubkeyHex,
        string $dIdentifier,
        array $relays = [],
    ): string {
        $pubkeyHex = strtolower($pubkeyHex);
        if (64 !== \strlen($pubkeyHex) || !ctype_xdigit($pubkeyHex)) {
            throw new \InvalidArgumentException('Invalid pubkey hex for naddr.');
        }

        return (string) Bech32::naddr(
            kind: $kind,
            pubkey: $pubkeyHex,
            identifier: $dIdentifier,
            relays: $relays,
        );
    }
}
