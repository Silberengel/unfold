<?php

declare(strict_types=1);

namespace App\Nostr;

/**
 * Kind 1 (NIP-01 + NIP-10): only {@code e} (thread references) and {@code p} (thread participants) tags.
 * Marked {@code e} use {@code ["e", id, relay, marker, pubkey]}; relay may be "".
 * If the thread root id cannot be inferred, a single marked {@code e} with "reply" references the direct parent.
 */
final class Nip10Kind1ArticleReplyTags
{
    /**
     * @param list<array<int, string|int|float>>|array<array> $parentRawTags
     *
     * @return list<list<string>>
     */
    public static function forReplyToKind1(
        string $parentEventIdHex,
        string $parentAuthorPubkeyHex,
        array $parentRawTags,
        string $articleCoordinate,
        ?string $articleEventHexId
    ): array {
        $parentEventIdHex = strtolower($parentEventIdHex);
        $parentAuthorPubkeyHex = strtolower($parentAuthorPubkeyHex);
        if (self::isInvalidHexId($parentEventIdHex) || self::isInvalidHexPubkey($parentAuthorPubkeyHex)) {
            throw new \InvalidArgumentException('Invalid parent id or pubkey');
        }

        $rootId = self::inferRootEventId($parentRawTags, $articleEventHexId);

        $parts = explode(':', $articleCoordinate, 3);
        $articleAuthorHex = \count($parts) >= 2 ? strtolower((string) $parts[1]) : '';
        if ($articleAuthorHex !== '' && (64 !== \strlen($articleAuthorHex) || !ctype_xdigit($articleAuthorHex))) {
            $articleAuthorHex = '';
        }

        $articleEventNorm = self::normEventId($articleEventHexId);
        $tags = [];

        if (self::isInvalidHexId($rootId)) {
            $tags[] = ['e', $parentEventIdHex, '', 'reply', $parentAuthorPubkeyHex];
        } else {
            $rootAuthorPk = self::pubkeyForRootEvent(
                $rootId,
                $parentEventIdHex,
                $parentAuthorPubkeyHex,
                $parentRawTags,
                $articleAuthorHex,
                $articleEventNorm
            );
            // NIP-10: direct reply to the thread root uses only a single "root" e tag, not root+reply to the same id.
            if (hash_equals($rootId, $parentEventIdHex)) {
                $tags[] = ['e', $rootId, '', 'root', $rootAuthorPk];
            } else {
                $tags[] = ['e', $rootId, '', 'root', $rootAuthorPk];
                $tags[] = ['e', $parentEventIdHex, '', 'reply', $parentAuthorPubkeyHex];
            }
        }

        $seenP = [];
        $addP = static function (string $pk) use (&$tags, &$seenP): void {
            if (64 !== \strlen($pk) || !ctype_xdigit($pk) || isset($seenP[$pk])) {
                return;
            }
            $seenP[$pk] = true;
            $tags[] = ['p', $pk];
        };
        // NIP-10: p tags = author of E being replied to, then all p tags from E (in no particular order; we use author first).
        $addP($parentAuthorPubkeyHex);
        foreach (self::collectPFromParent($parentRawTags) as $pk) {
            $addP($pk);
        }

        return $tags;
    }

    /**
     * @param list<array<array-key, mixed>> $rawTags
     */
    private static function pubkeyForRootEvent(
        string $rootId,
        string $parentEventIdHex,
        string $parentAuthorPubkeyHex,
        array $parentRawTags,
        string $articleAuthorHex,
        ?string $articleEventNorm
    ): string {
        if (hash_equals($rootId, $parentEventIdHex)) {
            return $parentAuthorPubkeyHex;
        }
        if ($articleEventNorm !== null && hash_equals($rootId, $articleEventNorm) && $articleAuthorHex !== '') {
            return $articleAuthorHex;
        }
        $fromParent = self::eTagPubkeyForEventId($parentRawTags, $rootId);
        if ($fromParent !== '') {
            return $fromParent;
        }

        return '';
    }

    /**
     * Optional 5th field on marked e tags: pubkey of the author of the referenced event (NIP-10).
     *
     * @param list<array<array-key, mixed>> $rawTags
     */
    private static function eTagPubkeyForEventId(array $rawTags, string $eventIdHex): string
    {
        $want = strtolower($eventIdHex);
        foreach ($rawTags as $row) {
            if (\count($row) < 2) {
                continue;
            }
            if (strtolower((string) ($row[0] ?? '')) !== 'e') {
                continue;
            }
            $id = self::normEventId($row[1] ?? null);
            if ($id === null || !hash_equals($want, $id)) {
                continue;
            }
            if (\count($row) >= 5) {
                $pk = self::normPubkey($row[4] ?? null);
                if ($pk !== '') {
                    return $pk;
                }
            }
        }

        return '';
    }

    /**
     * @param list<array<array-key, mixed>> $rawTags
     *
     * @return list<string>
     */
    private static function collectPFromParent(array $rawTags): array
    {
        $out = [];
        foreach ($rawTags as $row) {
            if (\count($row) < 2) {
                continue;
            }
            if (strtolower((string) ($row[0] ?? '')) !== 'p') {
                continue;
            }
            $pk = self::normPubkey($row[1] ?? null);
            if ($pk !== '') {
                $out[] = $pk;
            }
        }

        return $out;
    }

    private static function normPubkey(mixed $v): string
    {
        if (!\is_string($v) && !\is_int($v) && !\is_float($v)) {
            return '';
        }
        $h = strtolower(trim((string) $v));
        if (64 !== \strlen($h) || !ctype_xdigit($h)) {
            return '';
        }

        return $h;
    }

    /**
     * @param list<array<array-key, mixed>> $rawTags
     */
    private static function inferRootEventId(array $rawTags, ?string $articleEventHexId): string
    {
        $articleHex = self::normEventId($articleEventHexId);

        $isE = static function (mixed $row): bool {
            if (!\is_array($row) || ($row[0] ?? null) === null) {
                return false;
            }
            $n = strtolower((string) $row[0]);

            return $n === 'e';
        };

        foreach ($rawTags as $row) {
            if (!$isE($row) || \count($row) < 2) {
                continue;
            }
            if (($row[3] ?? '') === 'root') {
                $id = self::normEventId($row[1] ?? null);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        if ($articleHex !== null) {
            foreach ($rawTags as $row) {
                if (!$isE($row) || \count($row) < 2) {
                    continue;
                }
                $id = self::normEventId($row[1] ?? null);
                if ($id !== null && hash_equals($id, $articleHex)) {
                    return $id;
                }
            }
        }

        $eIds = [];
        foreach ($rawTags as $row) {
            if (!$isE($row) || \count($row) < 2) {
                continue;
            }
            $id = self::normEventId($row[1] ?? null);
            if ($id !== null) {
                $eIds[] = $id;
            }
        }
        if ($eIds === [] && $articleHex !== null) {
            return $articleHex;
        }
        if (\count($eIds) === 1) {
            return $eIds[0];
        }
        if ($eIds !== []) {
            if ($articleHex !== null) {
                foreach ($eIds as $id) {
                    if (hash_equals($id, $articleHex)) {
                        return $id;
                    }
                }
            }

            return $eIds[0];
        }
        if ($articleHex !== null) {
            return $articleHex;
        }

        return '';
    }

    private static function normEventId(mixed $v): ?string
    {
        if (!\is_string($v) && !\is_int($v) && !\is_float($v)) {
            return null;
        }
        $h = strtolower(trim((string) $v));
        if (64 !== \strlen($h) || !ctype_xdigit($h)) {
            return null;
        }

        return $h;
    }

    private static function isInvalidHexId(string $h): bool
    {
        return 64 !== \strlen($h) || !ctype_xdigit($h);
    }

    private static function isInvalidHexPubkey(string $h): bool
    {
        return 64 !== \strlen($h) || !ctype_xdigit($h);
    }
}
