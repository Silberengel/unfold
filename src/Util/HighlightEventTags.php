<?php

declare(strict_types=1);

namespace App\Util;

/**
 * NIP-84 (kind 9802): optional `context` = full visible passage; `content` = highlighted range
 * (marked inside that passage when `context` exists, otherwise only `content` in a mark).
 * In-article marks: {@see \App\Service\ArticleBodyHighlightInjector}.
 */
final class HighlightEventTags
{
    public const HIGHLIGHT_MARK_CLASS = 'user-highlight__marker';

    /**
     * Turn one Nostr tag (array, associative array, or object from JSON) into an ordered list of
     * string cells. Relay clients and json_decode vary; without this, `context` tags are often skipped.
     *
     * @return list<string>|null empty tag rows become null
     */
    public static function nostrTagRowToList(mixed $tag): ?array
    {
        if (\is_object($tag)) {
            $tag = \array_values((array) $tag);
        }
        if (!\is_array($tag)) {
            return null;
        }
        $out = [];
        foreach ($tag as $cell) {
            $out[] = (string) $cell;
        }
        if ($out === []) {
            return null;
        }

        return $out;
    }

    /**
     * Canonical tag list for JSON storage (list of list of strings).
     *
     * @param list<mixed> $tags
     *
     * @return list<list<string>>
     */
    public static function normalizeTagsForStorage(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $row = self::nostrTagRowToList($tag);
            if (null !== $row && $row !== []) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * The full passage from the `context` tag (one tag may split across many values in some clients).
     */
    public static function contextFromTags(array $tags): string
    {
        $parts = [];
        foreach ($tags as $t) {
            $row = self::nostrTagRowToList($t);
            if (null === $row || \count($row) < 2) {
                continue;
            }
            if (strtolower($row[0]) !== 'context') {
                continue;
            }
            for ($i = 1, $c = \count($row); $i < $c; ++$i) {
                $p = $row[$i];
                if ($p !== '') {
                    $parts[] = $p;
                }
            }
        }
        if ($parts === []) {
            return '';
        }
        $joined = \implode(' ', $parts);

        return \mb_substr($joined, 0, 8000);
    }

    /**
     * With `context`, show the full quote and mark the `content` substring. With no `context`, wrap
     * all of `content` in one mark.
     *
     * @param string $contextQuote  Text from the `context` tag. Empty means no surrounding quote.
     * @param string $contentField  The event’s `content` (highlighted phrase).
     *
     * @return string safe HTML
     */
    public static function buildHighlightedBodyHtml(string $contextQuote, string $contentField): string
    {
        $q = (string) $contextQuote;
        $hi = (string) $contentField;
        if ($q === '' && $hi === '') {
            return '';
        }
        if ($q === '') {
            return '<mark class="'.self::HIGHLIGHT_MARK_CLASS.'">'.self::escapeWithNl2br($hi).'</mark>';
        }
        if ($hi === '') {
            return self::escapeWithNl2br($q);
        }
        $pos = \mb_strpos($q, $hi, 0, 'UTF-8');
        if ($pos !== false) {
            $len = \mb_strlen($hi, 'UTF-8');
            $before = \mb_substr($q, 0, $pos, 'UTF-8');
            $match = \mb_substr($q, $pos, $len, 'UTF-8');
            $after = \mb_substr($q, $pos + $len, null, 'UTF-8');

            return self::escapeWithNl2br($before).self::markHtml($match).self::escapeWithNl2br($after);
        }

        // Substring not found: show the full context quote, then the highlight line so the note is not empty.
        return self::escapeWithNl2br($q).'<p class="user-highlight__marker-orphan">'.self::markHtml($hi).'</p>';
    }

    public static function escapeWithNl2br(string $s): string
    {
        return \nl2br(\htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    private static function markHtml(string $innerText): string
    {
        return self::markHighlightSpanHtml($innerText);
    }

    /**
     * Single highlighted span (inner text escaped). Used in cards and by {@see buildHighlightedBodyHtml}.
     */
    public static function markHighlightSpanHtml(string $innerText): string
    {
        $e = \htmlspecialchars($innerText, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return '<mark class="'.self::HIGHLIGHT_MARK_CLASS.'">'.$e.'</mark>';
    }

    /**
     * Text from `textquoteselector` (legacy / client-specific), first non-empty segment.
     *
     * @param list<array<int, string>>|list<array> $tags
     */
    public static function excerptFromTextquoteselectorTags(array $tags): string
    {
        foreach ($tags as $t) {
            $row = self::nostrTagRowToList($t);
            if (null === $row || \count($row) < 2) {
                continue;
            }
            if (strtolower($row[0]) !== 'textquoteselector') {
                continue;
            }
            for ($i = 1, $c = \count($row); $i < $c; ++$i) {
                $p = \trim($row[$i]);
                if ($p !== '') {
                    return \mb_substr($p, 0, 400);
                }
            }
        }

        return '';
    }

    /**
     * List preview: prefer the event `content` (the highlight / note body), else `context` quote, else tq.
     */
    public static function excerptForFeed(string $content, array $tags): string
    {
        $c = \trim((string) $content);
        if ($c !== '') {
            return \mb_substr($c, 0, 400);
        }
        $ctx = \trim(self::contextFromTags($tags));
        if ($ctx !== '') {
            return \mb_substr($ctx, 0, 400);
        }
        $tq = \trim(self::excerptFromTextquoteselectorTags($tags));

        return $tq !== '' ? $tq : '';
    }
}
