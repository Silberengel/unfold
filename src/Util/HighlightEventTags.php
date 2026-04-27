<?php

declare(strict_types=1);

namespace App\Util;

/**
 * NIP-84 (kind 9802): `context` tag = full quote; the event’s `.content` = the highlighted part of
 * that quote. If there is no `context` tag (or it is empty), the passage to display is the same
 * as `.content` (entirely highlighted). In-article marks: {@see \App\Service\ArticleBodyHighlightInjector}.
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
            $tag = (array) $tag;
        }
        if (!\is_array($tag)) {
            return null;
        }
        \ksort($tag, \SORT_NUMERIC);
        $tag = \array_values($tag);
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
        return self::valuesFromNostrTagName($tags, 'context');
    }

    /**
     * Same shape as the `context` tag: one or more `textquoteselector` rows (used for excerpts only).
     */
    public static function textquoteselectorPassageFromTags(array $tags): string
    {
        return self::valuesFromNostrTagName($tags, 'textquoteselector');
    }

    /**
     * Full “quote” passage for cards: the `context` tag when present and non-empty, otherwise
     * the same string as the event’s `.content` (no surrounding quote beyond the highlight).
     */
    public static function fullPassageForHighlightDisplay(string $eventContent, array $tags): string
    {
        $ctx = \trim(self::contextFromTags($tags));
        if ($ctx !== '') {
            return $ctx;
        }

        return \trim((string) $eventContent);
    }

    /**
     * @param list<mixed> $tags
     */
    private static function valuesFromNostrTagName(array $tags, string $nameLower): string
    {
        $parts = [];
        foreach ($tags as $t) {
            $row = self::nostrTagRowToList($t);
            if (null === $row || \count($row) < 2) {
                continue;
            }
            $k = self::normalizeNostrTagKey($row[0]);
            if ($k !== $nameLower) {
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

    private static function normalizeNostrTagKey(string $k): string
    {
        $k = (string) \preg_replace('/^\x{FEFF}/u', '', $k);
        $k = \ltrim($k, "\0..\x1F");

        return \strtolower(\trim($k));
    }

    /**
     * Same character normalization as {@see \App\Service\ArticleBodyHighlightInjector} so
     * `content` can match the `context` tag when Unicode (NBSP, soft hyphen, etc.) differs — NIP-84
     * requires `content` to be a substring of the passage, but clients often diverge on code points.
     *
     * Newlines and Unicode line/paragraph separators are removed: Nostr `context` often contains
     * `\\n` between sentences, while the article DOM’s flattened text has no line breaks at block
     * boundaries, so they must not break matching.
     *
     * Smart punctuation (curly quotes, en/em dash, Unicode ellipsis) is folded to ASCII so the
     * article HTML from {@see \League\CommonMark\Extension\SmartPunct\SmartPunctExtension} still
     * matches highlight `content` copied with straight quotes from the source article.
     */
    public static function stringForSearch(string $s): string
    {
        $L = \mb_strlen($s, 'UTF-8');
        $out = '';
        for ($i = 0; $i < $L; ++$i) {
            $ch = \mb_substr($s, $i, 1, 'UTF-8');
            $out .= self::searchCharacterNormalized($ch);
        }

        return $out;
    }

    /**
     * @return list<int> length L+1; cuml[i] = "search" string length of prefix s[0..i) after per-char normalization
     */
    public static function buildCumulativeSearchLens(string $s): array
    {
        $L = \mb_strlen($s, 'UTF-8');
        $cuml = [0];
        for ($i = 0; $i < $L; ++$i) {
            $ch = \mb_substr($s, $i, 1, 'UTF-8');
            $add = self::searchCharacterNormalized($ch);
            $cuml[] = $cuml[$i] + \mb_strlen($add, 'UTF-8');
        }

        return $cuml;
    }

    /**
     * @return array{0: int, 1: int}  half-open [start, end) in mb char indices of $orig
     */
    public static function mapSearchStringRangeToOrigStringRange(string $orig, int $nStart, int $nEnd): array
    {
        $L = \mb_strlen($orig, 'UTF-8');
        $cuml = self::buildCumulativeSearchLens($orig);
        if (0 > $nStart || $nStart > $cuml[$L] || $nEnd < $nStart || $nEnd > $cuml[$L]) {
            return [0, 0];
        }
        $startO = -1;
        for ($i = 0; $i < $L; ++$i) {
            if ($cuml[$i + 1] > $nStart) {
                $startO = $i;
                break;
            }
        }
        if ($startO < 0) {
            return [0, 0];
        }
        $endO = $L;
        for ($e = 0; $e <= $L; ++$e) {
            if ($cuml[$e] >= $nEnd) {
                $endO = $e;
                break;
            }
        }

        return [$startO, $endO];
    }

    /**
     * Find `content` inside `context` (literal or after Unicode/Nostr normalization). Returns half-open
     * mb indices into $context, or null.
     *
     * $context and $content must be the same strings used for final HTML (trim + line ending
     * normalization) — see {@see buildHighlightedBodyHtml}.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function findContentSpanInContext(string $context, string $content): ?array
    {
        $q = $context;
        if ($q === '' || $content === '') {
            return null;
        }
        foreach (self::highlightContentSearchVariants($content) as $needle) {
            $needle = self::normalizeLineEndingsForHighlight($needle);
            if ($needle === '') {
                continue;
            }
            $p = \mb_strpos($q, $needle, 0, 'UTF-8');
            if (false !== $p) {
                $len = \mb_strlen($needle, 'UTF-8');

                return [$p, $p + $len];
            }
        }
        $qR = self::replaceTypographicQuotesForSearch($q);
        if ($qR !== $q) {
            foreach (self::highlightContentSearchVariants($content) as $needle) {
                $needle = self::normalizeLineEndingsForHighlight($needle);
                if ($needle === '') {
                    continue;
                }
                foreach ([$needle, self::replaceTypographicQuotesForSearch($needle)] as $nTry) {
                    if ($nTry === '') {
                        continue;
                    }
                    $p = \mb_strpos($qR, $nTry, 0, 'UTF-8');
                    if (false !== $p) {
                        $len = \mb_strlen($nTry, 'UTF-8');

                        return [$p, $p + $len];
                    }
                }
            }
        }
        $hS = self::stringForSearch($q);
        foreach (self::highlightContentSearchVariants($content) as $needle) {
            $needle = self::normalizeLineEndingsForHighlight($needle);
            if ($needle === '') {
                continue;
            }
            $nS = self::stringForSearch($needle);
            if ($nS === '') {
                continue;
            }
            $pN = \mb_strpos($hS, $nS, 0, 'UTF-8');
            if (false === $pN) {
                continue;
            }
            $nEnd = $pN + \mb_strlen($nS, 'UTF-8');
            [$a, $b] = self::mapSearchStringRangeToOrigStringRange($q, $pN, $nEnd);
            if ($b > $a) {
                return [$a, $b];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function highlightContentSearchVariants(string $content): array
    {
        if ($content === '') {
            return [];
        }
        $candidates = [
            $content,
            self::replaceTypographicQuotesForSearch($content),
        ];
        $t = \trim($content);
        if ($t !== '' && $t !== $content) {
            $candidates[] = $t;
        }
        if (\class_exists(\Normalizer::class)) {
            $c = \Normalizer::normalize($content, \Normalizer::FORM_C);
            if (\is_string($c) && $c !== '' && $c !== $content) {
                $candidates[] = $c;
            }
        }
        $out = [];
        $seen = [];
        foreach ($candidates as $n) {
            if ($n === '' || isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $out[] = $n;
        }

        return $out;
    }

    private static function replaceTypographicQuotesForSearch(string $s): string
    {
        return \strtr($s, [
            "\xC2\xA0" => ' ', // nbsp
            "\xE2\x80\x99" => "'",
            "\xE2\x80\x98" => "'",
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            "\xE2\x80\x93" => '-',
            "\xE2\x80\x94" => '-',
        ]);
    }

    private static function normalizeLineEndingsForHighlight(string $s): string
    {
        return \str_replace("\r\n", "\n", \str_replace("\r", "\n", $s));
    }

    private static function searchCharacterNormalized(string $ch): string
    {
        if ($ch === "\n" || $ch === "\r" || $ch === "\f" || $ch === "\v") {
            return '';
        }
        if ($ch === "\xC2\x85") { // U+0085 (NEL)
            return '';
        }
        if ($ch === "\xE2\x80\xA8" || $ch === "\xE2\x80\xA9") { // U+2028 LINE, U+2029 PARA separator
            return '';
        }
        if ($ch === "\xC2\xAD") { // U+00AD soft hyphen
            return '';
        }
        if ($ch === "\xE2\x80\x8B" // U+200B
            || $ch === "\xE2\x80\x8C" // U+200C
            || $ch === "\xE2\x80\x8D" // U+200D
            || $ch === "\xEF\xBB\xBF" // U+FEFF
        ) {
            return '';
        }
        if ($ch === "\xC2\xA0" // U+00A0
            || $ch === "\xE2\x80\xAF" // U+202F narrow no-break
        ) {
            return ' ';
        }
        // CommonMark SmartPunct / e-book typography → match Nostr `content` with ASCII punctuation
        if ($ch === "\xE2\x80\x99" || $ch === "\xE2\x80\x98") { // U+2019, U+2018
            return "'";
        }
        if ($ch === "\xE2\x80\x9C" || $ch === "\xE2\x80\x9D") { // U+201C, U+201D
            return '"';
        }
        if ($ch === "\xE2\x80\x93" || $ch === "\xE2\x80\x94") { // en dash, em dash
            return '-';
        }
        if ($ch === "\xE2\x80\xA6") { // U+2026 HORIZONTAL ELLIPSIS
            return '...';
        }

        return $ch;
    }

    /**
     * @param string $contextQuote  Passage: `context` tag, or the same as `$contentField` when there
     *                               is no `context` (caller should use {@see fullPassageForHighlightDisplay}).
     * @param string $contentField  The event’s `content` (highlighted substring of the passage).
     *
     * @return string safe HTML
     */
    public static function buildHighlightedBodyHtml(string $contextQuote, string $contentField): string
    {
        $q = \trim(self::normalizeLineEndingsForHighlight((string) $contextQuote));
        $hi = \trim(self::normalizeLineEndingsForHighlight((string) $contentField));
        if ($q === '' && $hi === '') {
            return '';
        }
        if ($q === '') {
            return '<mark class="'.self::HIGHLIGHT_MARK_CLASS.'">'.self::escapeWithNl2br($hi).'</mark>';
        }
        if ($hi === '') {
            return self::escapeWithNl2br($q);
        }
        if ($q === $hi) {
            return '<mark class="'.self::HIGHLIGHT_MARK_CLASS.'">'.self::escapeWithNl2br($q).'</mark>';
        }
        $span = self::findContentSpanInContext($q, $hi);
        if (null !== $span) {
            [$start, $end] = $span;
            $before = \mb_substr($q, 0, $start, 'UTF-8');
            $match = \mb_substr($q, $start, $end - $start, 'UTF-8');
            $after = \mb_substr($q, $end, null, 'UTF-8');

            return self::escapeWithNl2br($before).self::markHtml($match).self::escapeWithNl2br($after);
        }

        // Substring not found after normalization / variants: show the full context quote, then the highlight so the card is not empty.
        return self::escapeWithNl2br($q).'<p class="user-highlight__marker-orphan">'.self::markHtml($hi).'</p>';
    }

    /**
     * For narrow list layouts (e.g. home aside with {@see buildHighlightedBodyHtml} + line-clamp): if the
     * `content` is not at the start of the passage, drop the text before the highlight so the
     * clamped block begins at (or a few characters before) the mark and the user actually sees
     * the highlight.
     *
     * @param int $includeCharsOfContextBeforeHighlight  Extra characters to keep before the
     *                                                   highlight (0 = passage starts with `content`)
     */
    public static function buildHighlightedBodyHtmlForNarrowList(
        string $contextQuote,
        string $contentField,
        int $includeCharsOfContextBeforeHighlight = 0,
    ): string {
        $q = \trim(self::normalizeLineEndingsForHighlight((string) $contextQuote));
        $hi = \trim(self::normalizeLineEndingsForHighlight((string) $contentField));
        if ($q === '' && $hi === '') {
            return '';
        }
        if ($q === '' || $hi === '') {
            return self::buildHighlightedBodyHtml($q, $hi);
        }
        if ($q === $hi) {
            return self::buildHighlightedBodyHtml($q, $hi);
        }
        $span = self::findContentSpanInContext($q, $hi);
        if (null === $span) {
            return self::buildHighlightedBodyHtml($q, $hi);
        }
        [$st] = $span;
        if (0 === $st) {
            return self::buildHighlightedBodyHtml($q, $hi);
        }
        $lead = \max(0, $includeCharsOfContextBeforeHighlight);
        $offset = \max(0, $st - $lead);
        if (0 === $offset) {
            return self::buildHighlightedBodyHtml($q, $hi);
        }
        $q2 = \mb_substr($q, $offset, null, 'UTF-8');
        if ($q2 === '') {
            return self::buildHighlightedBodyHtml($q, $hi);
        }
        $html = self::buildHighlightedBodyHtml($q2, $hi);

        return self::omittedTextPrefixHtml().$html;
    }

    /**
     * Safe “earlier text omitted” marker before a truncated passage in list cards.
     */
    public static function omittedTextPrefixHtml(): string
    {
        return '<span class="user-highlight__elide" aria-hidden="true">&#8230;</span> ';
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
            if (self::normalizeNostrTagKey($row[0]) !== 'textquoteselector') {
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
