<?php

declare(strict_types=1);

namespace App\Util;

/**
 * NIP-84 (kind 9802): {@see buildHighlightedBodyHtml} drives list/hover-card HTML (full `context`
 * with `content` marked when both exist). In-article marks are applied separately and only wrap
 * the `content` substring in the article body ({@see \App\Service\ArticleBodyHighlightInjector}).
 */
final class HighlightEventTags
{
    public const HIGHLIGHT_MARK_CLASS = 'user-highlight__marker';

    /**
     * The full passage from the `context` tag (one tag may split across many values in some clients).
     */
    public static function contextFromTags(array $tags): string
    {
        $parts = [];
        foreach ($tags as $t) {
            if (!\is_array($t) || \count($t) < 2) {
                continue;
            }
            if (strtolower((string) ($t[0] ?? '')) !== 'context') {
                continue;
            }
            for ($i = 1, $c = \count($t); $i < $c; ++$i) {
                $p = (string) ($t[$i] ?? '');
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
     * Card / aside body: with `context`, show the full quote and mark the `content` substring; with
     * empty `context`, wrap all of `content` in one <mark>.
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
            if (!\is_array($t) || \count($t) < 2) {
                continue;
            }
            if (strtolower((string) ($t[0] ?? '')) !== 'textquoteselector') {
                continue;
            }
            for ($i = 1, $c = \count($t); $i < $c; ++$i) {
                $p = \trim((string) ($t[$i] ?? ''));
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
