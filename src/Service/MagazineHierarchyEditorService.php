<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event as PublicationEventEntity;
use App\Util\NostrEventTags;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Builds JSON-safe payloads for the owner-only magazine hierarchy editor (kind 30040 root + categories).
 */
final class MagazineHierarchyEditorService
{
    public function __construct(
        private readonly MagazineIndexStore $store,
        private readonly MagazineContentService $magazineContent,
        private readonly NostrClient $nostrClient,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly ParameterBagInterface $params,
    ) {
    }

    /**
     * @return array{
     *   owner_hex: string,
     *   root_d_tag: string,
     *   site_name: string,
     *   default_category_preserved_tags: list<list<string>>,
     *   nodes: list<array{
     *     d_tag: string,
     *     is_root: bool,
     *     depth: int,
     *     title: string,
     *     summary: string,
     *     content: string,
     *     preserved_tags: list<list<string>>,
     *     a_coordinates: list<string>,
     *     parent_d_tag: string|null
     *   }>
     * }
     */
    public function buildEditorPayload(): array
    {
        $npub = (string) $this->params->get('npub');
        $dTag = (string) $this->params->get('d_tag');
        $siteName = (string) $this->params->get('name');
        $ownerHex = strtolower($this->nostrKeyHelper->convertToHex($npub));

        $root = $this->store->getRoot($npub, $dTag);
        if ($root === null) {
            try {
                $fetched = $this->nostrClient->getMagazineIndex($npub, $dTag);
                if ($fetched !== null) {
                    $this->store->putRoot($npub, $dTag, $fetched);
                    $root = $fetched;
                }
            } catch (\Throwable) {
            }
        }

        $parentByChild = $this->buildCategoryParentByChildD($npub, $dTag, $ownerHex);

        $nodes = [];
        if ($root !== null) {
            $rootNode = $this->nodeFromEvent($root, $dTag, true, $ownerHex, $siteName);
            $rootNode['parent_d_tag'] = null;
            $nodes[] = $this->withDepth($rootNode, 0);
        } else {
            $rootNode = $this->emptyRootNode($dTag, $ownerHex, $siteName);
            $rootNode['parent_d_tag'] = null;
            $nodes[] = $this->withDepth($rootNode, 0);
        }

        foreach ($this->orderedCategorySlugsForEditor($npub, $dTag, $ownerHex) as $slug) {
            $slug = trim($slug);
            if ($slug === '') {
                continue;
            }
            $parentD = $parentByChild[$slug] ?? $dTag;
            $cat = $this->store->getCategory($slug);
            if ($cat !== null) {
                $catNode = $this->nodeFromEvent($cat, $slug, false, $ownerHex, $siteName);
                $catNode['parent_d_tag'] = $parentD;
                $nodes[] = $this->withDepth(
                    $catNode,
                    $this->depthForCategorySlug($slug, $dTag, $parentByChild),
                );
            } else {
                $emptyNode = $this->emptyCategoryNode($slug, $ownerHex, $siteName);
                $emptyNode['parent_d_tag'] = $parentD;
                $nodes[] = $this->withDepth(
                    $emptyNode,
                    $this->depthForCategorySlug($slug, $dTag, $parentByChild),
                );
            }
        }

        return [
            'owner_hex' => $ownerHex,
            'root_d_tag' => $dTag,
            'site_name' => $siteName,
            'nodes' => $nodes,
            'default_category_preserved_tags' => $this->defaultCategoryPreservedTags($ownerHex, $siteName),
        ];
    }

    /**
     * Category #d values in depth-first pre-order: each index is immediately followed by its nested
     * kind-30040 children (same order as {@code a} tags), matching the magazine tree rather than BFS.
     * Appends any store slugs not linked from the cached root, then orphan refs (unchanged behaviour).
     *
     * @return list<string>
     */
    private function orderedCategorySlugsForEditor(string $npub, string $rootD, string $ownerHex): array
    {
        $out = [];
        $seen = [];
        $rootEvent = $this->store->getRoot($npub, $rootD);
        if ($rootEvent !== null) {
            $dfs = function (string $slug) use (&$dfs, &$out, &$seen, $ownerHex, $rootD): void {
                $slug = trim($slug);
                if ($slug === '' || hash_equals($rootD, $slug) || isset($seen[$slug])) {
                    return;
                }
                $seen[$slug] = true;
                $out[] = $slug;
                $cat = $this->store->getCategory($slug);
                if ($cat === null) {
                    return;
                }
                foreach (NostrEventTags::publicationIndexNestedDSlugsForOwner($cat->getTags(), $ownerHex) as $child) {
                    $dfs($child);
                }
            };
            foreach (NostrEventTags::publicationIndexNestedDSlugsForOwner($rootEvent->getTags(), $ownerHex) as $child) {
                $dfs($child);
            }
        }
        foreach ($this->magazineContent->getCategorySlugsFromStore() as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
        }
        foreach ($this->collectOrphan30040SlugsReferencedInStore($npub, $rootD, $ownerHex, array_keys($seen)) as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
        }

        return $out;
    }

    /**
     * @param array{d_tag: string, is_root: bool, title: string, summary: string, content: string, preserved_tags: list<list<string>>, a_coordinates: list<string>} $node
     *
     * @return array{
     *   d_tag: string,
     *   is_root: bool,
     *   depth: int,
     *   title: string,
     *   summary: string,
     *   content: string,
     *   preserved_tags: list<list<string>>,
     *   a_coordinates: list<string>,
     *   parent_d_tag?: string|null
     * }
     */
    private function withDepth(array $node, int $depth): array
    {
        $node['depth'] = $depth;

        return $node;
    }

    /**
     * Maps each category #d to the parent index #d (root magazine #d for top-level categories).
     * First parent wins when multiple indices reference the same child.
     *
     * @return array<string, string>
     */
    private function buildCategoryParentByChildD(string $npub, string $rootD, string $ownerHex): array
    {
        $ownerHex = strtolower($ownerHex);
        $parentByChild = [];
        $rootEvent = $this->store->getRoot($npub, $rootD);
        if ($rootEvent !== null) {
            foreach (NostrEventTags::publicationIndexNestedDSlugsForOwner($rootEvent->getTags(), $ownerHex) as $childD) {
                if ($childD === '' || hash_equals($rootD, $childD)) {
                    continue;
                }
                if (!isset($parentByChild[$childD])) {
                    $parentByChild[$childD] = $rootD;
                }
            }
        }
        $listed = $this->magazineContent->getCategorySlugsFromStore();
        foreach ($listed as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            $cat = $this->store->getCategory($slug);
            if ($cat === null) {
                continue;
            }
            foreach (NostrEventTags::publicationIndexNestedDSlugsForOwner($cat->getTags(), $ownerHex) as $childD) {
                if ($childD === '' || hash_equals($rootD, $childD)) {
                    continue;
                }
                if (!isset($parentByChild[$childD])) {
                    $parentByChild[$childD] = $slug;
                }
            }
        }
        foreach ($this->collectOrphan30040SlugsReferencedInStore($npub, $rootD, $ownerHex, $listed) as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            $cat = $this->store->getCategory($slug);
            if ($cat === null) {
                continue;
            }
            foreach (NostrEventTags::publicationIndexNestedDSlugsForOwner($cat->getTags(), $ownerHex) as $childD) {
                if ($childD === '' || hash_equals($rootD, $childD)) {
                    continue;
                }
                if (!isset($parentByChild[$childD])) {
                    $parentByChild[$childD] = $slug;
                }
            }
        }

        return $parentByChild;
    }

    /**
     * 1 = category linked from the root index; larger = deeper nested kind-30040.
     */
    private function depthForCategorySlug(string $slug, string $rootD, array $parentByChild): int
    {
        if ($slug === $rootD) {
            return 0;
        }
        if (!isset($parentByChild[$slug])) {
            return 1;
        }
        $depth = 1;
        $current = $slug;
        for ($i = 0; $i < 64; ++$i) {
            $parent = $parentByChild[$current] ?? null;
            if ($parent === null) {
                return $depth;
            }
            if (hash_equals($rootD, $parent)) {
                return $depth;
            }
            $current = $parent;
            ++$depth;
        }

        return $depth;
    }

    /**
     * @return list<list<string>>
     */
    private function defaultCategoryPreservedTags(string $ownerHex, string $siteName): array
    {
        return [
            ['type', 'magazine'],
            ['l', 'en, ISO-639-1'],
            ['reading-direction', 'left-to-right, top-to-bottom'],
            ['published_by', $siteName],
            ['p', $ownerHex],
        ];
    }

    /**
     * @return array{
     *   d_tag: string,
     *   is_root: bool,
     *   title: string,
     *   summary: string,
     *   content: string,
     *   preserved_tags: list<list<string>>,
     *   a_coordinates: list<string>
     * }
     */
    private function emptyRootNode(string $dTag, string $ownerHex, string $siteName): array
    {
        return [
            'd_tag' => $dTag,
            'is_root' => true,
            'title' => '',
            'summary' => '',
            'content' => '',
            'preserved_tags' => $this->defaultCategoryPreservedTags($ownerHex, $siteName),
            'a_coordinates' => [],
        ];
    }

    /**
     * @return array{
     *   d_tag: string,
     *   is_root: bool,
     *   title: string,
     *   summary: string,
     *   content: string,
     *   preserved_tags: list<list<string>>,
     *   a_coordinates: list<string>
     * }
     */
    private function emptyCategoryNode(string $slug, string $ownerHex, string $siteName): array
    {
        return [
            'd_tag' => $slug,
            'is_root' => false,
            'title' => $slug,
            'summary' => '',
            'content' => '',
            'preserved_tags' => $this->defaultCategoryPreservedTags($ownerHex, $siteName),
            'a_coordinates' => [],
        ];
    }

    /**
     * @return array{
     *   d_tag: string,
     *   is_root: bool,
     *   title: string,
     *   summary: string,
     *   content: string,
     *   preserved_tags: list<list<string>>,
     *   a_coordinates: list<string>
     * }
     */
    private function nodeFromEvent(PublicationEventEntity $event, string $dTag, bool $isRoot, string $ownerHex, string $siteName): array
    {
        $title = '';
        $summary = '';
        $preserved = [];
        $aCoords = [];
        foreach ($event->getTags() as $row) {
            $seq = NostrEventTags::rowToStringList($row);
            if ($seq === null || $seq === []) {
                continue;
            }
            $name = strtolower((string) $seq[0]);
            if ($name === 'd') {
                continue;
            }
            if ($name === 'title') {
                $title = isset($seq[1]) ? (string) $seq[1] : '';

                continue;
            }
            if ($name === 'summary') {
                $summary = isset($seq[1]) ? (string) $seq[1] : '';

                continue;
            }
            if ($name === 'a') {
                $coord = isset($seq[1]) ? trim((string) $seq[1]) : '';
                if ($coord !== '') {
                    $aCoords[] = $coord;
                }

                continue;
            }
            $preserved[] = array_map(static fn (mixed $v): string => (string) $v, $seq);
        }
        if ($preserved === []) {
            $preserved = $this->defaultCategoryPreservedTags($ownerHex, $siteName);
        }

        return [
            'd_tag' => $dTag,
            'is_root' => $isRoot,
            'title' => $title,
            'summary' => $summary,
            'content' => $event->getContent(),
            'preserved_tags' => $preserved,
            'a_coordinates' => $aCoords,
        ];
    }

    /**
     * Category #d values that appear in a kind-30040 `a` tag on the root or any stored category
     * but are not returned by {@see MagazineContentService::getCategorySlugsFromStore()} (e.g. newly
     * linked before the next prewarm BFS).
     *
     * @param list<string> $alreadyListed
     *
     * @return list<string>
     */
    private function collectOrphan30040SlugsReferencedInStore(string $npub, string $rootD, string $ownerHex, array $alreadyListed): array
    {
        $have = array_fill_keys($alreadyListed, true);
        $out = [];
        $events = [];
        $r = $this->store->getRoot($npub, $rootD);
        if ($r !== null) {
            $events[] = $r;
        }
        foreach ($alreadyListed as $slug) {
            $c = $this->store->getCategory($slug);
            if ($c !== null) {
                $events[] = $c;
            }
        }
        foreach ($events as $event) {
            foreach (NostrEventTags::publicationIndexNestedDSlugsForOwner($event->getTags(), $ownerHex) as $childD) {
                if ($childD === '' || hash_equals($rootD, $childD) || isset($have[$childD])) {
                    continue;
                }
                $have[$childD] = true;
                $out[] = $childD;
            }
        }

        return $out;
    }
}
