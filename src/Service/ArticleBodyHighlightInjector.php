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
 * Injects kind-9802 highlight ranges into the rendered article body by finding each event’s
 * `content` in the visible text (the `context` tag is ignored; the article is the full context).
 * Matches across inline elements (e.g. em, strong) by concatenating text in document order.
 * If a literal match fails, compares a normalized form (NBSP→space, strip U+00AD / ZW, etc.),
 * then maps the match back to the original HTML text (for e‑book style soft hyphens in 9802 content).
 */
final class ArticleBodyHighlightInjector
{
    private const ROOT_ID = '_article_hl';

    private DOMDocument $dom;

    private ?DOMElement $root = null;

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
        foreach ($sorted as $h) {
            $eid = \strtolower($h->getEventId());
            if (64 !== \strlen($eid) || !ctype_xdigit($eid)) {
                continue;
            }
            if ($this->tryInjectOneHighlight($this->root, $h, $eid)) {
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
        // getElementById is unreliable for HTML loaded without a DTD; use XPath, then a div scan, then a tree walk.
        $xp = new DOMXPath($this->dom);
        $nodes = $xp->query('//div[@id="'.self::ROOT_ID.'"]');
        if (false !== $nodes && $nodes->length > 0) {
            $first = $nodes->item(0);
            $this->root = $first instanceof DOMElement ? $first : null;
        } else {
            $this->root = null;
        }
        if (null === $this->root) {
            $de = $this->dom->documentElement;
            if ($de instanceof DOMElement && $de->getAttribute('id') === self::ROOT_ID) {
                $this->root = $de;
            }
        }
        if (null === $this->root) {
            $this->root = $this->findFirstDivById(self::ROOT_ID);
        }
        if (null === $this->root) {
            $this->root = $this->findElementByIdFallback(self::ROOT_ID);
        }
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

    private function tryInjectOneHighlight(DOMElement $root, ArticleHighlight $h, string $eid): bool
    {
        $resolved = $this->resolveInjectionNeedle($h);
        foreach ($this->needleSearchVariants($resolved) as $needle) {
            if ($needle === '') {
                continue;
            }
            if ($this->tryWrapInDocument($root, $needle, $eid)) {
                return true;
            }
        }

        return false;
    }

    private function resolveInjectionNeedle(ArticleHighlight $h): string
    {
        $c = \trim($h->getContent());
        if ($c !== '') {
            return $c;
        }

        return \trim(HighlightEventTags::contextFromTags($h->getTags()));
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
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            "\xE2\x80\x93" => '-',
            "\xE2\x80\x94" => '-',
        ]);
    }

    /**
     * Strips/rewrites typographic/linebreak chars so Nostr `content` (e.g. e‑book soft hyphens) can
     * match the article’s flat HTML text, then map positions back to the real string for {@see wrapTextSlice}.
     */
    private function stringForSearch(string $s): string
    {
        $L = \mb_strlen($s, 'UTF-8');
        $out = '';
        for ($i = 0; $i < $L; ++$i) {
            $ch = \mb_substr($s, $i, 1, 'UTF-8');
            $out .= $this->searchCharacterNormalized($ch);
        }

        return $out;
    }

    private function searchCharacterNormalized(string $ch): string
    {
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

        return $ch;
    }

    /**
     * @return list<int> length L+1; cuml[i] = "search" string length of prefix s[0..i) after per-char normalization
     */
    private function buildCumulativeSearchLens(string $s): array
    {
        $L = \mb_strlen($s, 'UTF-8');
        $cuml = [0];
        for ($i = 0; $i < $L; ++$i) {
            $ch = \mb_substr($s, $i, 1, 'UTF-8');
            $add = $this->searchCharacterNormalized($ch);
            $cuml[] = $cuml[$i] + \mb_strlen($add, 'UTF-8');
        }

        return $cuml;
    }

    /**
     * @return array{0: int, 1: int}  half-open [start, end) in mb char indices of $orig
     */
    private function mapSearchStringRangeToOrigStringRange(string $orig, int $nStart, int $nEnd): array
    {
        $L = \mb_strlen($orig, 'UTF-8');
        $cuml = $this->buildCumulativeSearchLens($orig);
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

    private function tryWrapInDocument(DOMElement $root, string $needle, string $eventId): bool
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
            $catS = $this->stringForSearch($cat);
            $needleS = $this->stringForSearch($needle);
            if ($needleS === '') {
                return false;
            }
            $pN = \mb_strpos($catS, $needleS, 0, 'UTF-8');
            if (false === $pN) {
                return false;
            }
            $nEnd = $pN + \mb_strlen($needleS, 'UTF-8');
            [$p, $pEnd] = $this->mapSearchStringRangeToOrigStringRange($cat, $pN, $nEnd);
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
                0 === $i
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

        return 'script' === $n
            || 'style' === $n
            || 'pre' === $n
            || 'textarea' === $n
            || 'code' === $n
            || 'mark' === $n;
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
            if ('mark' === $n) {
                $cl = (string) $p->getAttribute('class');
                if (\str_contains($cl, 'user-highlight__marker')) {
                    return false;
                }
            }
            $p = $p->parentNode;
        }

        return true;
    }

    private function wrapTextSlice(DOMText $textNode, int $uOffset, int $uLength, string $eventId, bool $firstInReadingOrder): bool
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
