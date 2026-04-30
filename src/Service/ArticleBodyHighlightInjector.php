<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArticleHighlight;
use App\Util\HighlightEventTags;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
/**
 * Injects kind-9802 highlight marks into the rendered article body by searching the visible text
 * in NIP-84 order: event `content` (highlighted span) first, then the `context` tag when set, then
 * the full passage ({@see HighlightEventTags::fullPassageForHighlightDisplay}, same as `content`
 * when `context` is missing), then `textquoteselector`. The first string that matches the body wins.
 * Matches across inline elements (e.g. em, strong) by concatenating text in document order. Text
 * inside a prior `mark.user-highlight__marker` is still considered so a narrower 9802 can
 * be nested and receive its own fragment id (deep link from the landing aside).
 * If a literal match fails, compares a normalized form (NBSP→space, strip U+00AD / ZW, line breaks,
 * etc.) via {@see HighlightEventTags::stringForSearch}, then maps the match back to the original
 * HTML text (for e‑book style soft hyphens in 9802 content). CommonMark footnote callouts
 * (League CommonMark `sup#fnref…`) are ignored for matching so “realm 1 always” in the DOM does not
 * block a NIP-84 passage that says “realm always”.
 */
final class ArticleBodyHighlightInjector
{
    private const ROOT_ID = '_article_hl';

    private DOMDocument $dom;

    private ?DOMElement $root = null;

    public function __construct(
        private readonly HighlightAuthorMetadataProvider $highlightAuthorMetadata,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    /**
     * @param list<ArticleHighlight> $highlights
     *
     * @return array{html: string, injectedEventIds: list<string>}
     */
    public function inject(string $html, array $highlights): array
    {
        if ($highlights === [] || $html === '') {
            return ['html' => $html, 'injectedEventIds' => []];
        }
        $sorted = $highlights;
        usort(
            $sorted,
            static fn (ArticleHighlight $a, ArticleHighlight $b) => $a->getEventCreatedAt() <=> $b->getEventCreatedAt()
        );

        $this->loadDom($html);
        if (null === $this->root) {
            return ['html' => $html, 'injectedEventIds' => []];
        }

        $injected = [];
        $groups = $this->groupHighlightsForInjection($sorted);
        foreach ($groups as $group) {
            if ($group === []) {
                continue;
            }
            $added = $this->tryInjectHighlightGroup($this->root, $group);
            foreach ($added as $eid) {
                $injected[] = $eid;
            }
        }

        $out = '';
        foreach ($this->root->childNodes as $child) {
            $out .= (string) $this->dom->saveHTML($child);
        }

        return ['html' => $out, 'injectedEventIds' => $injected];
    }

    private function loadDom(string $html): void
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->root = null;
        if ($html === '') {
            return;
        }
        $enc = '<?xml encoding="UTF-8"?>'.'<div id="'.self::ROOT_ID.'">'.$html.'</div>';
        $prev = libxml_use_internal_errors(true);
        try {
            if (false === $this->dom->loadHTML(
                $enc,
                \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD
            )) {
                libxml_clear_errors();
            }
        } finally {
            libxml_use_internal_errors($prev);
            libxml_clear_errors();
        }
        $this->root = $this->resolveRootWrapperElement();
        if (null === $this->root) {
            // Some libxml/fragment combinations drop the root with HTML_NOIMPLIED; parse a plain wrapper
            $this->dom = new DOMDocument('1.0', 'UTF-8');
            $prevInner = libxml_use_internal_errors(true);
            try {
                $this->dom->loadHTML(
                    '<?xml encoding="UTF-8"?>'.'<div id="'.self::ROOT_ID.'">'.$html.'</div>',
                    \LIBXML_HTML_NODEFDTD
                );
                $this->root = $this->resolveRootWrapperElement();
            } finally {
                libxml_use_internal_errors($prevInner);
                libxml_clear_errors();
            }
        }
    }

    private function resolveRootWrapperElement(): ?DOMElement
    {
        $xp = new DOMXPath($this->dom);
        $nodes = $xp->query('//div[@id="'.self::ROOT_ID.'"]');
        if (false !== $nodes && $nodes->length > 0) {
            $first = $nodes->item(0);

            return $first instanceof DOMElement ? $first : null;
        }
        $de = $this->dom->documentElement;
        if ($de instanceof DOMElement && $de->getAttribute('id') === self::ROOT_ID) {
            return $de;
        }
        $d = $this->findFirstDivById(self::ROOT_ID);
        if (null !== $d) {
            return $d;
        }
        $el = $this->findElementByIdFallback(self::ROOT_ID);

        return $el instanceof DOMElement ? $el : null;
    }

    private function findFirstDivById(string $id): ?DOMElement
    {
        if ('' === $id) {
            return null;
        }
        $n = $this->dom->getElementsByTagName('div');
        for ($i = 0, $L = $n->length; $i < $L; ++$i) {
            $d = $n->item($i);
            if ($d instanceof DOMElement && $d->getAttribute('id') === $id) {
                return $d;
            }
        }

        return null;
    }

    private function findElementByIdFallback(string $id): ?DOMElement
    {
        if ('' === $id) {
            return null;
        }
        $stack = [];
        if (null === $this->dom->documentElement) {
            return null;
        }
        $stack[] = $this->dom->documentElement;
        while ($stack !== []) {
            $el = \array_pop($stack);
            if (! $el instanceof DOMElement) {
                continue;
            }
            if ($el->getAttribute('id') === $id) {
                return $el;
            }
            for ($c = $el->lastChild; $c; $c = $c->previousSibling) {
                if ($c instanceof DOMElement) {
                    $stack[] = $c;
                }
            }
        }

        return null;
    }

    /**
     * @param list<ArticleHighlight> $group same highlight text; oldest first
     *
     * @return list<string> event ids that were applied
     */
    private function tryInjectHighlightGroup(DOMElement $root, array $group): array
    {
        if ($group === []) {
            return [];
        }
        $first = $group[0];
        $eid = \strtolower($first->getEventId());
        if (64 !== \strlen($eid) || !ctype_xdigit($eid)) {
            return [];
        }
        $outEids = [];
        foreach ($group as $h) {
            $id = \strtolower($h->getEventId());
            if (64 === \strlen($id) && ctype_xdigit($id)) {
                $outEids[] = $id;
            }
        }
        if ($outEids === []) {
            return [];
        }
        $authorJson = $this->buildHighlightAuthorsJson($group);
        $bases = $this->injectionNeedleBasesInPriority($first);
        if ($bases === []) {
            return [];
        }
        foreach ($bases as $base) {
            foreach ($this->needleSearchVariants($base) as $needle) {
                if ($needle === '') {
                    continue;
                }
                if ($this->tryWrapInDocument($root, $needle, $eid, $authorJson)) {
                    $this->addFragmentIdAliasesForHighlightGroup($eid, $outEids);

                    return $outEids;
                }
            }
        }

        return [];
    }

    /**
     * One <mark> per passage group, with id highlight-{oldest eid}. The landing aside links each
     * 9802 by that row's event id, so we add zero-footprint #highlight-{id} spans for every other
     * event in the same group (same place in the text as the mark).
     *
     * @param list<string> $outEids lowercase 64-hex, includes $canonicalEid; first is the oldest
     */
    private function addFragmentIdAliasesForHighlightGroup(string $canonicalEid, array $outEids): void
    {
        if (\count($outEids) < 2) {
            return;
        }
        $mark = $this->getHighlightMarkElementById('highlight-'.$canonicalEid);
        if (null === $mark) {
            return;
        }
        $parent = $mark->parentNode;
        if (null === $parent) {
            return;
        }
        foreach ($outEids as $other) {
            if ($other === $canonicalEid) {
                continue;
            }
            if (64 !== \strlen($other) || !ctype_xdigit($other)) {
                continue;
            }
            if ($this->getHighlightMarkElementById('highlight-'.$other) !== null) {
                continue;
            }
            $span = $this->dom->createElement('span');
            if (false === $span) {
                continue;
            }
            $span->setAttribute('id', 'highlight-'.$other);
            $span->setAttribute('class', 'user-highlight__fragment-target');
            $span->setAttribute('aria-hidden', 'true');
            $span->appendChild($this->dom->createTextNode("\u{200B}"));
            $parent->insertBefore($span, $mark);
        }
    }

    private function getHighlightMarkElementById(string $id): ?DOMElement
    {
        if (null === $this->root || $id === '') {
            return null;
        }
        $el = $this->dom->getElementById($id);
        if ($el instanceof DOMElement) {
            return $el;
        }
        if (! \preg_match('/^highlight-[a-f0-9]{64}$/D', $id)) {
            return null;
        }
        $xp = new DOMXPath($this->dom);
        $q = '//*[@id="'.(string) $id.'"]';
        $nodes = $xp->query($q, $this->root);
        if (false === $nodes || 0 === $nodes->length) {
            return null;
        }
        $n = $nodes->item(0);

        return $n instanceof DOMElement ? $n : null;
    }

    /**
     * @param list<ArticleHighlight> $sorted by created_at asc
     *
     * @return list<list<ArticleHighlight>>
     */
    private function groupHighlightsForInjection(array $sorted): array
    {
        $buckets = [];
        foreach ($sorted as $h) {
            $primary = $this->primaryNeedleForGrouping($h);
            if ($primary === '') {
                continue;
            }
            $key = HighlightEventTags::stringForSearch($primary);
            if ($key === '') {
                $key = 'x'.\md5($primary);
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = [];
            }
            $buckets[$key][] = $h;
        }
        $groups = \array_values($buckets);
        \usort(
            $groups,
            static function (array $a, array $b): int {
                $ta = $a[0] instanceof ArticleHighlight ? $a[0]->getEventCreatedAt() : 0;
                $tb = $b[0] instanceof ArticleHighlight ? $b[0]->getEventCreatedAt() : 0;

                return $ta <=> $tb;
            }
        );

        return $groups;
    }

    /**
     * NIP-84: same highlighted passage → one mark, dedupe authors by npub, profile from cache.
     * JSON objects: e (event id hex), n (npub), a (display name), p (picture URL), t (created_at unix, optional).
     *
     * @param list<ArticleHighlight> $group
     */
    private function buildHighlightAuthorsJson(array $group): string
    {
        $byNpub = [];
        foreach ($group as $h) {
            $eidH = $h->getEventId();
            if (64 !== \strlen($eidH) || !ctype_xdigit($eidH)) {
                continue;
            }
            $pk = $h->getAuthorPubkey();
            if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
                continue;
            }
            try {
                $npub = $this->nostrKeyHelper->convertPublicKeyToBech32($pk);
            } catch (\Throwable) {
                continue;
            }
            if (isset($byNpub[$npub])) {
                continue;
            }
            $name = '';
            $pic = '';
            try {
                $meta = $this->highlightAuthorMetadata->getMetadata($npub);
                if (isset($meta->display_name) && \is_string($meta->display_name) && $meta->display_name !== '') {
                    $name = $meta->display_name;
                } elseif (isset($meta->name) && \is_string($meta->name) && $meta->name !== '') {
                    $name = $meta->name;
                }
                if (isset($meta->picture) && \is_string($meta->picture) && $meta->picture !== '') {
                    $pic = $meta->picture;
                } elseif (isset($meta->image) && \is_string($meta->image) && $meta->image !== '') {
                    $pic = $meta->image;
                }
            } catch (\Throwable) {
            }
            $created = $h->getEventCreatedAt();
            $row = [
                'e' => \strtolower($eidH),
                'n' => $npub,
                'a' => $name,
                'p' => $pic,
            ];
            if ($created > 0) {
                $row['t'] = $created;
            }
            $byNpub[$npub] = $row;
        }

        return \json_encode(\array_values($byNpub), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * Same priority as the card: event `content` (NIP-84 sub-span) first, then the `context` tag when
     * set, then {@see HighlightEventTags::fullPassageForHighlightDisplay} (so missing/empty `context`
     * is treated as “passage = `content`” before `textquoteselector`). Tries each in order until one
     * matches the rendered body.
     */
    private function primaryNeedleForGrouping(ArticleHighlight $h): string
    {
        $b = $this->injectionNeedleBasesInPriority($h);

        return $b[0] ?? '';
    }

    /**
     * @return list<string> unique non-empty strings, highest priority first
     */
    private function injectionNeedleBasesInPriority(ArticleHighlight $h): array
    {
        $rawContent = (string) $h->getContent();
        $tags = $h->getTags();
        $c = HighlightEventTags::trimNostrText($rawContent);
        $ctx = HighlightEventTags::trimNostrText(HighlightEventTags::contextFromTags($tags));
        $fullPassage = HighlightEventTags::trimNostrText(
            HighlightEventTags::fullPassageForHighlightDisplay($rawContent, $tags)
        );
        $tq = HighlightEventTags::trimNostrText(HighlightEventTags::textquoteselectorPassageFromTags($tags));
        $out = [];
        $seen = [];
        // NIP-84: `context` = full quote; `content` = highlighted span. Missing/empty `context` is
        // the same as “full passage = `content`” (entirely highlighted) — see fullPassageForHighlightDisplay.
        foreach ([$c, $ctx, $fullPassage, $tq] as $s) {
            if ($s === '' || isset($seen[$s])) {
                continue;
            }
            $seen[$s] = true;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * Nostr/Unicode vs rendered HTML: try a few equivalent strings for `mb_strpos` on the flattened text.
     *
     * @return list<string>
     */
    private function needleSearchVariants(string $base): array
    {
        if ($base === '') {
            return [];
        }
        $candidates = [
            $base,
            $this->replaceTypographicQuotes($base),
        ];
        $noLineBreaks = (string) \preg_replace('/\R/u', '', $base);
        if ($noLineBreaks !== $base && $noLineBreaks !== '') {
            $candidates[] = $noLineBreaks;
        }
        $nEnd = (string) \preg_replace('/[.!?…,;:]+$/u', '', $base);
        if ($nEnd !== $base && $nEnd !== '') {
            $candidates[] = $nEnd;
        }
        if (\class_exists(\Normalizer::class)) {
            $c = \Normalizer::normalize($base, \Normalizer::FORM_C);
            if (\is_string($c) && $c !== '' && $c !== $base) {
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

    private function replaceTypographicQuotes(string $s): string
    {
        return \strtr($s, [
            "\xC2\xA0" => ' ', // nbsp
            "\xE2\x80\x99" => "'",
            "\xE2\x80\x98" => "'",
            "\xE2\x80\x9C" => "\x22",
            "\xE2\x80\x9D" => "\x22",
            "\xE2\x80\x93" => '-',
            "\xE2\x80\x94" => '-',
        ]);
    }

    private function tryWrapInDocument(DOMElement $root, string $needle, string $eventId, string $authorJson = ''): bool
    {
        $textNodes = $this->collectTextNodes($root);
        if ($textNodes === []) {
            return false;
        }
        $cat = '';
        /** @var list<array{0: DOMText, 1: int, 2: int}> $segments */
        $segments = [];

        foreach ($textNodes as $tn) {
            $t = (string) $tn->data;
            $len = \mb_strlen($t, 'UTF-8');
            if ($len === 0) {
                continue;
            }
            $cat .= $t;
        }

        $p = \mb_strpos($cat, $needle, 0, 'UTF-8');
        $pEnd = false;
        if (false !== $p) {
            $pEnd = $p + \mb_strlen($needle, 'UTF-8');
        } else {
            // e.g. soft hyphens (U+00AD) or NBSP in the event `content` vs plain text in the article
            $catS = HighlightEventTags::stringForSearch($cat);
            $needleS = HighlightEventTags::stringForSearch($needle);
            if ($needleS === '') {
                return false;
            }
            $pN = \mb_strpos($catS, $needleS, 0, 'UTF-8');
            if (false === $pN) {
                return false;
            }
            $nEnd = $pN + \mb_strlen($needleS, 'UTF-8');
            [$p, $pEnd] = HighlightEventTags::mapSearchStringRangeToOrigStringRange($cat, $pN, $nEnd);
            if ($pEnd <= $p) {
                return false;
            }
        }
        $cursor = 0;
        foreach ($textNodes as $tn) {
            $t = (string) $tn->data;
            $nodeLen = \mb_strlen($t, 'UTF-8');
            if ($nodeLen === 0) {
                continue;
            }
            $nStart = $cursor;
            $nEnd = $cursor + $nodeLen;
            if ($pEnd <= $nStart) {
                break;
            }
            if ($p >= $nEnd) {
                $cursor = $nEnd;
                continue;
            }
            $oStart = \max($p, $nStart);
            $oEnd = \min($pEnd, $nEnd);
            if ($oStart < $oEnd) {
                $lStart = $oStart - $nStart;
                $lLen = $oEnd - $oStart;
                $segments[] = [$tn, $lStart, $lLen];
            }
            $cursor = $nEnd;
            if ($oEnd >= $pEnd) {
                break;
            }
        }

        if ($segments === []) {
            return false;
        }
        for ($i = \count($segments) - 1; $i >= 0; --$i) {
            [$n, $off, $nLen] = $segments[$i];
            if (! $this->wrapTextSlice(
                $n,
                $off,
                $nLen,
                $eventId,
                0 === $i,
                $authorJson
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<DOMText>
     */
    private function collectTextNodes(DOMElement $el): array
    {
        $out = [];
        for ($c = $el->firstChild; $c; $c = $c->nextSibling) {
            if ($c instanceof DOMText) {
                if ($this->isSafeTextContext($c)) {
                    $out[] = $c;
                }
            } elseif ($c instanceof DOMElement) {
                if ($this->shouldNotDescendInto($c)) {
                    continue;
                }
                foreach ($this->collectTextNodes($c) as $tn) {
                    $out[] = $tn;
                }
            }
        }

        return $out;
    }

    private function shouldNotDescendInto(DOMElement $c): bool
    {
        $n = $c->nodeName;
        if ('script' === $n
            || 'style' === $n
            || 'pre' === $n
            || 'textarea' === $n
            || 'code' === $n) {
            return true;
        }
        if ('div' === $n && $this->isFootnotesOrEndnotesElement($c)) {
            // End-of-article footnote list (League CommonMark): must not mix into the body search string
            // or after main content, which would desync “flat text” from NIP-84 passages.
            return true;
        }
        if ('sup' === $n && $this->isFootnoteCalloutElement($c)) {
            // Inline [^ref] callouts: skip the superscript so "realm" + "1" + " always" does not
            // break matching "realm always" from kind-9802 `content` (cards use raw Nostr, not the DOM).
            return true;
        }
        if ('mark' === $n) {
            $cl = (string) $c->getAttribute('class');

            return ! \str_contains($cl, 'user-highlight__marker');
        }

        return false;
    }

    private function isFootnoteCalloutElement(DOMElement $c): bool
    {
        $id = (string) $c->getAttribute('id');

        return $id !== '' && \str_starts_with($id, 'fnref');
    }

    private function isFootnotesOrEndnotesElement(DOMElement $c): bool
    {
        if (\str_contains((string) $c->getAttribute('class'), 'footnotes')
            || $c->getAttribute('role') === 'doc-endnotes') {
            return true;
        }

        return false;
    }

    private function isSafeTextContext(DOMText $textNode): bool
    {
        $p = $textNode->parentNode;
        while (null !== $p && $p->nodeType === XML_ELEMENT_NODE) {
            if (! $p instanceof DOMElement) {
                $p = $p->parentNode;
                continue;
            }
            $n = $p->nodeName;
            if ('script' === $n || 'style' === $n || 'pre' === $n || 'textarea' === $n) {
                return false;
            }
            if ('code' === $n) {
                return false;
            }
            if (('div' === $n && $this->isFootnotesOrEndnotesElement($p))
                || ('sup' === $n && $this->isFootnoteCalloutElement($p))) {
                return false;
            }
            if ('a' === $n && \str_contains((string) $p->getAttribute('class'), 'footnote-ref')) {
                return false;
            }
            $p = $p->parentNode;
        }

        return true;
    }

    private function wrapTextSlice(DOMText $textNode, int $uOffset, int $uLength, string $eventId, bool $firstInReadingOrder, string $authorJson = ''): bool
    {
        if ($uLength < 1) {
            return false;
        }
        $t = (string) $textNode->data;
        $nLen = \mb_strlen($t, 'UTF-8');
        if ($uOffset < 0 || $uOffset + $uLength > $nLen) {
            return false;
        }
        $before = $uOffset > 0 ? \mb_substr($t, 0, $uOffset, 'UTF-8') : '';
        $match = \mb_substr($t, $uOffset, $uLength, 'UTF-8');
        $restStart = $uOffset + $uLength;
        $after = $restStart < $nLen ? \mb_substr($t, $restStart, null, 'UTF-8') : '';

        $parent = $textNode->parentNode;
        if (null === $parent) {
            return false;
        }

        $ref = $textNode;
        if ($before !== '') {
            $parent->insertBefore($this->dom->createTextNode($before), $ref);
        }
        $mark = $this->dom->createElement('mark');
        if (! $mark) {
            return false;
        }
        $mark->setAttribute('class', 'user-highlight__marker');
        if ($firstInReadingOrder) {
            $mark->setAttribute('id', 'highlight-'.$eventId);
        }
        if ($authorJson !== '') {
            $mark->setAttribute('data-hl', $authorJson);
        }
        $mark->appendChild($this->dom->createTextNode($match));
        $parent->insertBefore($mark, $ref);
        if ($after === '') {
            $parent->removeChild($ref);
        } else {
            $ref->data = $after;
        }

        return true;
    }
}
