<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArticleHighlight;
use App\Util\HighlightEventTags;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use swentel\nostr\Key\Key;

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

    public function __construct(
        private readonly CacheService $cacheService,
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
        $resolved = $this->resolveInjectionNeedle($first);
        foreach ($this->needleSearchVariants($resolved) as $needle) {
            if ($needle === '') {
                continue;
            }
            if ($this->tryWrapInDocument($root, $needle, $eid, $authorJson)) {
                return $outEids;
            }
        }

        return [];
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
            $resolved = $this->resolveInjectionNeedle($h);
            if ($resolved === '') {
                continue;
            }
            $key = HighlightEventTags::stringForSearch(\trim($resolved));
            if ($key === '') {
                $key = 'x'.\md5($resolved);
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
     *
     * @param list<ArticleHighlight> $group
     */
    private function buildHighlightAuthorsJson(array $group): string
    {
        $key = new Key();
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
                $npub = $key->convertPublicKeyToBech32($pk);
            } catch (\Throwable) {
                continue;
            }
            if (isset($byNpub[$npub])) {
                continue;
            }
            $name = '';
            $pic = '';
            try {
                $meta = $this->cacheService->getMetadata($npub);
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
            $byNpub[$npub] = [
                'e' => \strtolower($eidH),
                'n' => $npub,
                'a' => $name,
                'p' => $pic,
            ];
        }

        return \json_encode(\array_values($byNpub), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
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
