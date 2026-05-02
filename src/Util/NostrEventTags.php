<?php

declare(strict_types=1);

namespace App\Util;

use App\Enum\KindsEnum;

/**
 * Tag rows from Nostr events may be JSON arrays, associative arrays, or object-shaped. Normalize
 * to a name-first list of string values (see NIP-01 tag structure).
 */
final class NostrEventTags
{
    /**
     * @return list<string>|null
     */
    public static function rowToStringList(mixed $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if (\is_object($row)) {
            $row = get_object_vars($row);
        }
        if (!\is_array($row) || $row === []) {
            return null;
        }

        return array_values(
            array_map(
                static fn (mixed $v): string => (string) $v,
                $row
            )
        );
    }

    public static function tagNameMatches(mixed $row, string $name): bool
    {
        $seq = self::rowToStringList($row);
        if ($seq === null || $seq === []) {
            return false;
        }

        return strtolower($seq[0] ?? '') === strtolower($name);
    }

    /**
     * Kind-30040 publication indices may nest further 30040 indices via {@code a} tags
     * (kind:pubkey:#d). Returns each nested index #d in tag order, deduped on first occurrence.
     *
     * @param iterable<mixed> $tagRows
     *
     * @return list<string>
     */
    public static function publicationIndexNestedDSlugs(iterable $tagRows): array
    {
        $out = [];
        $seen = [];
        foreach ($tagRows as $tag) {
            if (!self::tagNameMatches($tag, 'a')) {
                continue;
            }
            $seq = self::rowToStringList($tag);
            if ($seq === null || !isset($seq[1]) || (string) $seq[1] === '') {
                continue;
            }
            $parts = explode(':', (string) $seq[1], 3);
            if (\count($parts) < 3) {
                continue;
            }
            if ((int) ($parts[0] ?? 0) !== KindsEnum::PUBLICATION_INDEX->value) {
                continue;
            }
            $d = trim((string) $parts[2]);
            if ($d === '' || isset($seen[$d])) {
                continue;
            }
            $seen[$d] = true;
            $out[] = $d;
        }

        return $out;
    }

    /**
     * Like {@see publicationIndexNestedDSlugs} but only {@code a} coordinates whose pubkey matches
     * {@code $ownerHexLower} (hex).
     *
     * @param iterable<mixed> $tagRows
     *
     * @return list<string>
     */
    public static function publicationIndexNestedDSlugsForOwner(iterable $tagRows, string $ownerHexLower): array
    {
        $ownerHexLower = strtolower($ownerHexLower);
        $out = [];
        $seen = [];
        foreach ($tagRows as $tag) {
            if (!self::tagNameMatches($tag, 'a')) {
                continue;
            }
            $seq = self::rowToStringList($tag);
            if ($seq === null || !isset($seq[1]) || (string) $seq[1] === '') {
                continue;
            }
            $parts = explode(':', (string) $seq[1], 3);
            if (\count($parts) < 3) {
                continue;
            }
            if ((int) ($parts[0] ?? 0) !== KindsEnum::PUBLICATION_INDEX->value) {
                continue;
            }
            $pk = strtolower(trim((string) $parts[1]));
            if (!hash_equals($ownerHexLower, $pk)) {
                continue;
            }
            $d = trim((string) $parts[2]);
            if ($d === '' || isset($seen[$d])) {
                continue;
            }
            $seen[$d] = true;
            $out[] = $d;
        }

        return $out;
    }
}
