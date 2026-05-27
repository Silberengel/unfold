<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Collects npub1… bech32 identifiers from free text (markdown, HTML) for batched kind-0 prefetch.
 */
final class NpubBech32Extractor
{
    /**
     * @return list<string>
     */
    public static function extractFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (preg_match_all('/npub1[0-9a-z]+/i', $text, $matches) !== 1) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }
}
