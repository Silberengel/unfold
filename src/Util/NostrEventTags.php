<?php

declare(strict_types=1);

namespace App\Util;

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
}
