<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArticleHighlight;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;

/**
 * Injects kind-9802 highlight ranges into the rendered article body by finding each event’s
 * {@see ArticleHighlight::getContent} in the visible text. Matches across inline elements
 * (e.g. em, strong) by concatenating text in document order.
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
            $needle = \trim($h->getContent());
            if ($needle === '') {
                continue;
            }
            $eid = \strtolower($h->getEventId());
            if (64 !== \strlen($eid) || !ctype_xdigit($eid)) {
                continue;
            }
            if ($this->tryWrapInDocument($this->root, $needle, $eid)) {
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
        // getElementById is unreliable for HTML loaded without a DTD; use XPath.
        $xp = new DOMXPath($this->dom);
        $nodes = $xp->query('//div[@id="'.self::ROOT_ID.'"]');
        if (false === $nodes || 0 === $nodes->length) {
            $this->root = $this->findElementByIdFallback(self::ROOT_ID);

            return;
        }
        $first = $nodes->item(0);
        $this->root = $first instanceof DOMElement ? $first : null;
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

    private function tryWrapInDocument(DOMElement $root, string $needle, string $eventId): bool
    {
        $textNodes = $this->collectTextNodes($root);
        if ($textNodes === []) {
            return false;
        }
        $cat = '';
        /** @var list<array{0: DOMText, 1: int, 2: int}> $segments */
        $segments = [];
        $nl = \mb_strlen($needle, 'UTF-8');
        if ($nl < 1) {
            return false;
        }

        foreach ($textNodes as $tn) {
            $t = (string) $tn->data;
            $len = \mb_strlen($t, 'UTF-8');
            if ($len === 0) {
                continue;
            }
            $cat .= $t;
        }

        $p = \mb_strpos($cat, $needle, 0, 'UTF-8');
        if (false === $p) {
            return false;
        }
        $pEnd = $p + $nl;
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
                if (\str_contains($cl, 'article-body-highlight')) {
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
        $mark->setAttribute('class', 'user-highlight__marker article-body-highlight');
        if ($firstInReadingOrder) {
            $mark->setAttribute('id', 'highlight-'.$eventId);
            $mark->setAttribute('tabindex', '0');
        }
        $mark->setAttribute('data-event-id', $eventId);
        $mark->setAttribute('data-article-body-highlight', '1');
        if (! $firstInReadingOrder) {
            $mark->setAttribute('data-article-body-highlight-continuation', '1');
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
