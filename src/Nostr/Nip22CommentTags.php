<?php

declare(strict_types=1);

namespace App\Nostr;


/**
 * Kind 1111 (NIP-22) tag rows for article threads — aligned with Jumble's
 * createCommentDraftEvent (a/A + e root + e parent for nested replies).
 */
final class Nip22CommentTags
{
    /**
     * Top-level comment under a kind 30023 (or 30024) article address.
     *
     * @return list<list<string>>
     */
    public static function forReplyToArticle(string $coordinate, string $articleAuthorPubkeyHex): array
    {
        $parts = explode(':', $coordinate, 2);
        $k = ctype_digit((string) $parts[0]) ? (string) (int) $parts[0] : '30023';

        return [
            ['A', $coordinate, ''],
            ['P', $articleAuthorPubkeyHex],
            ['K', $k],
            ['a', $coordinate, ''],
            ['k', $k],
            ['p', $articleAuthorPubkeyHex],
        ];
    }

    /**
     *_reply under another kind-1111 comment. Root A/E/P/K (and i/I) are taken from the parent
     * event's tags; the final e/k/p point at the immediate parent comment.
     *
     * @param list<array<int, string|int|float>>|array<array> $rawTags
     * @return list<list<string>>
     */
    public static function forReplyToComment(
        string $parentCommentIdHex,
        string $parentCommentAuthorPubkeyHex,
        int $parentCommentKind,
        array $rawTags
    ): array {
        if ($parentCommentKind !== 1111) {
            throw new \InvalidArgumentException('Parent must be NIP-22 kind 1111');
        }
        if (self::isInvalidHexId($parentCommentIdHex) || self::isInvalidHexPubkey($parentCommentAuthorPubkeyHex)) {
            throw new \InvalidArgumentException('Invalid parent id or pubkey');
        }

        $A = self::firstTag($rawTags, 'A', 'a');
        $E = self::firstTag($rawTags, 'E', 'e');
        $P = self::firstTag($rawTags, 'P', 'p');
        $K = self::firstTag($rawTags, 'K', 'k');
        $Iu = self::firstTag($rawTags, 'I', 'i');

        $out = [];
        if ($A !== null) {
            $out[] = $A;
        }
        if ($E !== null) {
            $out[] = self::ensureTagName($E, 'E');
        }
        if ($P !== null) {
            $out[] = self::ensureTagName($P, 'P');
        }
        if ($K !== null) {
            $out[] = self::ensureTagName($K, 'K');
        }
        if ($Iu !== null) {
            $out[] = self::ensureTagName($Iu, 'I');
            $out[] = self::ensureTagName($Iu, 'i');
        }

        $out[] = ['e', $parentCommentIdHex, '', $parentCommentAuthorPubkeyHex];
        $out[] = ['k', (string) $parentCommentKind];
        $out[] = ['p', $parentCommentAuthorPubkeyHex];

        return $out;
    }

    /**
     * @param list<array<array-key, mixed>> $rawTags
     * @return list<string>|null
     */
    private static function firstTag(array $rawTags, string $upper, string $lower): ?array
    {
        foreach ([$upper, $lower] as $n) {
            foreach ($rawTags as $row) {
                if (($row[0] ?? null) === null) {
                    continue;
                }
                if ((string) $row[0] === $n) {
                    $norm = array_map(
                        static fn (mixed $c): string => \is_string($c) || \is_int($c) || \is_float($c) ? (string) $c : '',
                        $row
                    );
                    if ($n === $lower) {
                        $norm[0] = $lower;
                    }
                    if ($n === $upper) {
                        $norm[0] = $upper;
                    }

                    return $norm;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $tag
     * @return list<string>
     */
    private static function ensureTagName(array $tag, string $name): array
    {
        $out = $tag;
        $out[0] = $name;

        return $out;
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
