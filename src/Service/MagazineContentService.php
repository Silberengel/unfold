<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\EventStatusEnum;
use App\Repository\ArticleRepository;
use App\Util\NostrEventTags;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Magazine index for templates. The store is filled by `app:prewarm` (cron) / CLI; missing 30040
 * snapshots can be loaded once per request from relays (see ensure* methods).
 */
final class MagazineContentService
{
    public function __construct(
        private readonly MagazineIndexStore $store,
        private readonly ParameterBagInterface $params,
        private readonly ArticleRepository $articleRepository,
        private readonly NostrClient $nostrClient,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @deprecated use {@see getHomeCategoryAIndexTagsFromStoreOnly} (identical; no blocking relay I/O)
     *
     * @return list<array<int, string>>
     */
    public function getHomeCategoryIndexTags(): array
    {
        return $this->getHomeCategoryAIndexTagsFromStoreOnly();
    }

    /**
     * Category `a` tags from the persisted root only (no relay). The store is filled by
     * `app:prewarm` / cron ({@see MagazineRefresher::refreshFromRelays}), not from HTTP.
     *
     * @return list<array<int, string>>
     */
    public function getHomeCategoryAIndexTagsFromStoreOnly(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->attributes->has('_magazine_home_a_tags')) {
            /** @var list<array<int, string>> */
            return $request->attributes->get('_magazine_home_a_tags');
        }
        $tags = $this->categoryATagsFromStoredRoot();
        if ($request !== null) {
            $request->attributes->set('_magazine_home_a_tags', $tags);
        }

        return $tags;
    }

    /**
     * @return list<array<int, string>>
     */
    private function categoryATagsFromStoredRoot(): array
    {
        $npub = (string) $this->params->get('npub');
        $dTag = (string) $this->params->get('d_tag');
        $mag = $this->store->getRoot($npub, $dTag);
        if ($mag === null) {
            $this->ensureRoot30040FromRelays($npub, $dTag);
            $mag = $this->store->getRoot($npub, $dTag);
        }

        return $this->categoryATagsFromMag($mag);
    }

    /**
     * @return list<array<int, string>>
     */
    private function categoryATagsFromMag(?Event $mag): array
    {
        if ($mag === null) {
            return [];
        }
        $tags = $mag->getTags();
        $cats = [];
        foreach ($tags as $tag) {
            if (!NostrEventTags::tagNameMatches($tag, 'a')) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($tag);
            if ($seq === null || !isset($seq[1]) || (string) $seq[1] === '') {
                continue;
            }
            $cats[] = ['a', (string) $seq[1]];
        }

        return $cats;
    }

    /**
     * Category path slugs from the persisted root index (third segment of each category `a` tag).
     *
     * @return list<string>
     */
    public function getCategorySlugsFromStore(): array
    {
        $tags = $this->getHomeCategoryAIndexTagsFromStoreOnly();
        $out = [];
        foreach ($tags as $row) {
            $coord = $row[1] ?? '';
            if (!\is_string($coord) || $coord === '') {
                continue;
            }
            $parts = explode(':', $coord, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $slug = trim((string) $parts[2]);
            if ($slug !== '') {
                $out[] = $slug;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Distinct author pubkeys (hex) from every category index `a` tag (kind:pubkey:identifier).
     *
     * @return list<string>
     */
    public function getAllDistinctCategoryAuthorPubkeyHexes(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->getCategorySlugsFromStore() as $slug) {
            $catIndex = $this->store->getCategory($slug);
            if ($catIndex === null) {
                continue;
            }
            foreach ($catIndex->getTags() as $tag) {
                if (!NostrEventTags::tagNameMatches($tag, 'a')) {
                    continue;
                }
                $seq = NostrEventTags::rowToStringList($tag);
                if ($seq === null || !isset($seq[1])) {
                    continue;
                }
                $parts = explode(':', (string) $seq[1], 3);
                if (\count($parts) < 2) {
                    continue;
                }
                $pk = strtolower((string) $parts[1]);
                if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
                    continue;
                }
                if (isset($seen[$pk])) {
                    continue;
                }
                $seen[$pk] = true;
                $out[] = $pk;
            }
        }

        return $out;
    }

    /**
     * Title from cached category index event tags, or the slug when missing.
     */
    public function getCategoryDisplayTitle(string $slug): string
    {
        if ($slug === '') {
            return '';
        }
        $this->warmCategoryIndexIfMissing($slug);
        $catIndex = $this->store->getCategory($slug);
        if ($catIndex === null) {
            return $slug;
        }
        foreach ($catIndex->getTags() as $tag) {
            if (!NostrEventTags::tagNameMatches($tag, 'title')) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($tag);
            if ($seq !== null && isset($seq[1])) {
                return (string) $seq[1];
            }
        }

        return $slug;
    }

    /**
     * Category listing from the persisted 30040 index and DB only. Does not call relays.
     * Rows come from MySQL only; run `app:prewarm` to sync new `a` tags and replaceable revisions.
     *
     * @return array{list: list<Article>, category: array{title: string, summary: string}}
     */
    public function getCategoryPageData(string $slug): array
    {
        $this->warmCategoryIndexIfMissing($slug);
        $catIndex = $this->store->getCategory($slug);
        $list = [];
        $coordinates = [];
        $category = [];
        if ($catIndex) {
            foreach ($catIndex->getTags() as $tag) {
                $seq = NostrEventTags::rowToStringList($tag);
                if ($seq === null) {
                    continue;
                }
                $name = strtolower($seq[0] ?? '');
                if ($name === 'title' && isset($seq[1])) {
                    $category['title'] = (string) $seq[1];
                }
                if ($name === 'summary' && isset($seq[1])) {
                    $category['summary'] = (string) $seq[1];
                }
                if ($name === 'a' && isset($seq[1])) {
                    $coordinates[] = (string) $seq[1];
                }
            }
        }

        if (!empty($coordinates)) {
            $pairs = [];
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', (string) $coordinate, 3);
                if (\count($parts) < 3) {
                    continue;
                }
                $slugPart = trim((string) $parts[2]);
                if ($slugPart === '') {
                    continue;
                }
                $pairs[] = [
                    'pubkey' => strtolower((string) $parts[1]),
                    'slug' => $slugPart,
                ];
            }
            $byAddress = $this->articleRepository->findByAuthorAndSlugIndexed($pairs);
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', (string) $coordinate, 3);
                if (\count($parts) < 3) {
                    continue;
                }
                $k = strtolower((string) $parts[1])."\0".trim((string) $parts[2]);
                if (isset($byAddress[$k])) {
                    $list[] = $byAddress[$k];
                }
            }
        }

        $category['title'] = $category['title'] ?? '';
        $category['summary'] = $category['summary'] ?? '';

        return [
            'list' => $list,
            'category' => $category,
        ];
    }

    /**
     * For every category in the store, fetch the latest Nostr long-form for each `a` tag so new
     * posts are ingested and NIP-33 replaceable updates refresh existing MySQL rows. Nostr I/O;
     * intended for {@see PrewarmCommand} / cron only.
     */
    public function ingestLongformForAllMagazineCategories(): int
    {
        $n = 0;
        foreach ($this->getCategorySlugsFromStore() as $catSlug) {
            $all = $this->findAllLongformCoordinatesForCategory($catSlug);
            if ($all === []) {
                continue;
            }
            $this->nostrClient->ingestLongformForCategoryCoordinates($all);
            $n += \count($all);
        }

        return $n;
    }

    /**
     * @return list<string> Nostr coordinates kind:pubkey:identifier
     */
    private function findAllLongformCoordinatesForCategory(string $slug): array
    {
        $catIndex = $this->store->getCategory($slug);
        if ($catIndex === null) {
            return [];
        }
        $out = [];
        foreach ($catIndex->getTags() as $tag) {
            if (!NostrEventTags::tagNameMatches($tag, 'a')) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($tag);
            if ($seq === null || !isset($seq[1]) || (string) $seq[1] === '') {
                continue;
            }
            $coordinate = (string) $seq[1];
            $parts = explode(':', $coordinate, 3);
            if (\count($parts) < 3 || trim((string) $parts[2]) === '') {
                continue;
            }
            $out[] = $coordinate;
        }

        return $out;
    }

    /**
     * Union of every article referenced by a category index (root 30040). Use this for magazine-wide
     * Atom and comment prewarm so "newest" tracks the magazine, not the generic community list.
     *
     * Dedupes by slug (newest {@see Article::getCreatedAt} wins). Only PUBLISHED/ARCHIVED rows.
     *
     * @return list<Article> Newest first
     */
    public function getAllMagazineCategoryArticlesForSyndication(): array
    {
        $bySlug = [];
        foreach ($this->getCategorySlugsFromStore() as $catSlug) {
            $data = $this->getCategoryPageData($catSlug);
            foreach ($data['list'] as $article) {
                $s = $article->getEventStatus();
                if ($s === null || ($s !== EventStatusEnum::PUBLISHED && $s !== EventStatusEnum::ARCHIVED)) {
                    continue;
                }
                $slug = \trim((string) $article->getSlug());
                if ($slug === '') {
                    continue;
                }
                $c = $article->getCreatedAt();
                if (!isset($bySlug[$slug])) {
                    $bySlug[$slug] = $article;

                    continue;
                }
                $prev = $bySlug[$slug]->getCreatedAt();
                if ($c !== null && (null === $prev || $c > $prev)) {
                    $bySlug[$slug] = $article;
                }
            }
        }
        $list = \array_values($bySlug);
        usort($list, static function (Article $a, Article $b): int {
            $ca = $a->getCreatedAt();
            $cb = $b->getCreatedAt();
            if ($ca === null && $cb === null) {
                return 0;
            }
            if ($ca === null) {
                return 1;
            }
            if ($cb === null) {
                return -1;
            }

            return $cb <=> $ca;
        });

        return $list;
    }

    /**
     * Ensures the category 30040 is in the store for this HTTP request (one relay pass per slug).
     * Safe to call from e.g. {@see \App\Twig\Components\Molecules\CategoryLink} before reading titles.
     */
    public function warmCategoryIndexIfMissing(string $slug): void
    {
        if ($this->store->getCategory($slug) !== null) {
            return;
        }
        $this->ensureCategory30040FromRelays($slug);
    }

    private function ensureRoot30040FromRelays(string $npub, string $dTag): void
    {
        $r = $this->requestStack->getCurrentRequest();
        if ($r !== null && $r->attributes->get('_magazine_root_ensured')) {
            return;
        }
        try {
            $e = $this->nostrClient->getMagazineIndex($npub, $dTag);
            if ($e !== null) {
                $this->store->putRoot($npub, $dTag, $e);
            }
        } catch (\Throwable) {
        }
        if ($r !== null) {
            $r->attributes->set('_magazine_root_ensured', true);
        }
    }

    private function ensureCategory30040FromRelays(string $slug): void
    {
        if (trim($slug) === '') {
            return;
        }
        if ($this->store->getCategory($slug) !== null) {
            return;
        }
        $r = $this->requestStack->getCurrentRequest();
        if ($r !== null) {
            $tried = $r->attributes->get('_magazine_category_fetch_tried', []);
            if (!\is_array($tried)) {
                $tried = [];
            }
            if (\in_array($slug, $tried, true)) {
                return;
            }
            $tried[] = $slug;
            $r->attributes->set('_magazine_category_fetch_tried', $tried);
        }
        $npub = (string) $this->params->get('npub');
        try {
            $e = $this->nostrClient->getMagazineIndex($npub, $slug);
            if ($e !== null) {
                $this->store->putCategory($slug, $e);
            }
        } catch (\Throwable) {
        }
    }
}
