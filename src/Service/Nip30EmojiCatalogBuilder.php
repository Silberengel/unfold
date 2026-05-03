<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\NostrEventTags;

/**
 * NIP-30 custom emoji: {@code ["emoji", shortcode, image-url, optional emoji-set "a" coordinate]]}.
 * Merges definitions from kind 0, NIP-51 kind 10030 (emoji list), and NIP-38 kind 30315 (status) events.
 */
final class Nip30EmojiCatalogBuilder
{
    private const SHORTCODE_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * Kinds whose events may carry NIP-30 {@code emoji} tags on the wire.
     *
     * @return list<int>
     */
    public static function kindsWithEmojiTags(): array
    {
        return [0, 1, 7, 30_315];
    }

    public static function kindSupportsEmojiTags(int $kind): bool
    {
        return \in_array($kind, self::kindsWithEmojiTags(), true);
    }

    /**
     * @param list<object> $statuses30315 NIP-38 status events (append newer events later so duplicate shortcodes resolve to the latest)
     *
     * @return list<array{shortcode: string, url: string, set?: string}>
     */
    public function buildMergedCatalog(object $kind0Wire, ?object $kind10030Wire, array $statuses30315): array
    {
        /** @var array<string, array{shortcode: string, url: string, set?: string}> $byShort */
        $byShort = [];
        $this->mergeWireTagsIntoMap($byShort, $kind0Wire);
        if ($kind10030Wire !== null) {
            $this->mergeWireTagsIntoMap($byShort, $kind10030Wire);
        }
        foreach ($statuses30315 as $ev) {
            $this->mergeWireTagsIntoMap($byShort, $ev);
        }

        return array_values($byShort);
    }

    /**
     * @param array<int, mixed> $tags Raw event.tags (JSON column shape)
     *
     * @return list<array{shortcode: string, url: string, set?: string}>
     */
    public function catalogFromTagsOnly(array $tags): array
    {
        /** @var array<string, array{shortcode: string, url: string, set?: string}> $byShort */
        $byShort = [];
        $this->appendRawTagsIntoMap($tags, $byShort);

        return array_values($byShort);
    }

    /**
     * NIP-30 emoji tags on kind 0, 1, 7, or 30315 wires (for ingest paths other than profile prewarm).
     *
     * @return list<array{shortcode: string, url: string, set?: string}>
     */
    public function catalogFromWireIfNip30Kind(object $wire): array
    {
        $k = (int) ($wire->kind ?? -1);
        if (!self::kindSupportsEmojiTags($k)) {
            return [];
        }
        $tags = $wire->tags ?? null;
        if (!\is_array($tags)) {
            return [];
        }

        return $this->catalogFromTagsOnly($tags);
    }

    /**
     * @param array<string, array{shortcode: string, url: string, set?: string}> $byShort
     */
    private function mergeWireTagsIntoMap(array &$byShort, object $wire): void
    {
        $tags = $wire->tags ?? null;
        if (!\is_array($tags)) {
            return;
        }
        $this->appendRawTagsIntoMap($tags, $byShort);
    }

    /**
     * @param array<int, mixed>        $tags
     * @param array<string, array{shortcode: string, url: string, set?: string}> $byShort
     */
    private function appendRawTagsIntoMap(array $tags, array &$byShort): void
    {
        foreach ($tags as $row) {
            $parsed = $this->tryParseOneEmojiRow($row);
            if ($parsed !== null) {
                $byShort[$parsed['shortcode']] = $parsed;
            }
        }
    }

    /**
     * @return array{shortcode: string, url: string, set?: string}|null
     */
    private function tryParseOneEmojiRow(mixed $row): ?array
    {
        $seq = NostrEventTags::rowToStringList($row);
        if ($seq === null || $seq === []) {
            return null;
        }
        if (strtolower((string) $seq[0]) !== 'emoji') {
            return null;
        }
        $shortcode = isset($seq[1]) ? trim((string) $seq[1]) : '';
        $url = isset($seq[2]) ? trim((string) $seq[2]) : '';
        if ($shortcode === '' || $url === '' || 1 !== preg_match(self::SHORTCODE_PATTERN, $shortcode)) {
            return null;
        }
        if (!$this->looksLikeEmojiImageUrl($url)) {
            return null;
        }
        $item = ['shortcode' => $shortcode, 'url' => $url];
        if (isset($seq[3])) {
            $set = trim((string) $seq[3]);
            if ($set !== '' && self::looksLikeAddressableCoordinate($set)) {
                $item['set'] = $set;
            }
        }

        return $item;
    }

    private function looksLikeEmojiImageUrl(string $url): bool
    {
        if (\strlen($url) < 8 || \strlen($url) > 2048) {
            return false;
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return false;
        }

        return false === strpbrk($url, "\r\n\0");
    }

    private static function looksLikeAddressableCoordinate(string $s): bool
    {
        $parts = explode(':', $s, 3);

        return 3 === \count($parts)
            && ctype_digit(trim($parts[0], " \t\n\r\0\x0B"))
            && 64 === \strlen($parts[1])
            && ctype_xdigit($parts[1])
            && trim($parts[2]) !== '';
    }
}
