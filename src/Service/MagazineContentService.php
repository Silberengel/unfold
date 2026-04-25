<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\FeaturedArticleCard;
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
     * @return array{
     *     list: list<Article>,
     *     category: array{title: string, summary: string},
     *     pagination: array{page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function getCategoryPageData(string $slug, int $page = 1, int $perPage = 25): array
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

        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $total = \count($list);
        $lastPage = max(1, (int) \ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'list' => \array_slice($list, $offset, $perPage),
            'category' => $category,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
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
            // If a category 30040 wasn't persisted during the refresh phase (relay errors/timeouts),
            // try one direct fetch here so long-form ingest and reports are not silently incomplete.
            $this->warmCategoryIndexIfMissing($catSlug);
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
     * Human-readable prewarm/audit data: what each cached category index (30040) lists and which
     * coordinates are unresolved in local MySQL `article`.
     *
     * @return array{
     *   categories: list<array{
     *     slug: string,
     *     title: string,
     *     event_id: string,
     *     listed_total: int,
     *     resolved_total: int,
     *     missing_total: int,
     *     entries: list<array{
     *       coordinate: string,
     *       status: 'resolved'|'missing',
     *       reason: string,
     *       article_title?: string,
     *       article_slug?: string
     *     }>
     *   }>,
     *   totals: array{categories: int, listed: int, resolved: int, missing: int}
     * }
     */
    public function buildCategoryArticleDbCoverageReport(): array
    {
        $categories = [];
        $totListed = 0;
        $totResolved = 0;
        $totMissing = 0;
        foreach ($this->getCategorySlugsFromStore() as $slug) {
            $this->warmCategoryIndexIfMissing($slug);
            $catIndex = $this->store->getCategory($slug);
            if ($catIndex === null) {
                $totMissing++;
                $categories[] = [
                    'slug' => $slug,
                    'title' => $slug,
                    'event_id' => '',
                    'listed_total' => 0,
                    'resolved_total' => 0,
                    'missing_total' => 1,
                    'entries' => [[
                        'coordinate' => 'category:'.$slug,
                        'status' => 'missing',
                        'reason' => 'category_index_unavailable',
                    ]],
                ];
                continue;
            }
            $title = $slug;
            $coords = [];
            foreach ($catIndex->getTags() as $tag) {
                $seq = NostrEventTags::rowToStringList($tag);
                if ($seq === null) {
                    continue;
                }
                $name = strtolower((string) ($seq[0] ?? ''));
                if ($name === 'title' && isset($seq[1]) && trim((string) $seq[1]) !== '') {
                    $title = trim((string) $seq[1]);
                }
                if ($name === 'a' && isset($seq[1]) && trim((string) $seq[1]) !== '') {
                    $coords[] = trim((string) $seq[1]);
                }
            }
            $coords = array_values(array_unique($coords));
            $pairs = [];
            foreach ($coords as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (\count($parts) < 3) {
                    continue;
                }
                $pub = strtolower(trim((string) $parts[1]));
                $d = trim((string) $parts[2]);
                if ($d === '' || 64 !== \strlen($pub) || !ctype_xdigit($pub)) {
                    continue;
                }
                $pairs[] = ['pubkey' => $pub, 'slug' => $d];
            }
            $byAddress = $this->articleRepository->findByAuthorAndSlugIndexed($pairs);
            $entries = [];
            $resolved = 0;
            $missing = 0;
            foreach ($coords as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (\count($parts) < 3) {
                    $entries[] = ['coordinate' => $coordinate, 'status' => 'missing', 'reason' => 'malformed_coordinate'];
                    $missing++;

                    continue;
                }
                $kind = (int) ($parts[0] ?? 0);
                if (!\in_array($kind, [30023, 30024], true)) {
                    $entries[] = ['coordinate' => $coordinate, 'status' => 'missing', 'reason' => 'unsupported_kind'];
                    $missing++;

                    continue;
                }
                $pub = strtolower(trim((string) $parts[1]));
                $d = trim((string) $parts[2]);
                if (64 !== \strlen($pub) || !ctype_xdigit($pub)) {
                    $entries[] = ['coordinate' => $coordinate, 'status' => 'missing', 'reason' => 'invalid_pubkey'];
                    $missing++;

                    continue;
                }
                if ($d === '') {
                    $entries[] = ['coordinate' => $coordinate, 'status' => 'missing', 'reason' => 'empty_identifier'];
                    $missing++;

                    continue;
                }
                $k = $pub."\0".$d;
                if (!isset($byAddress[$k])) {
                    $entries[] = ['coordinate' => $coordinate, 'status' => 'missing', 'reason' => 'article_not_in_db'];
                    $missing++;

                    continue;
                }
                $article = $byAddress[$k];
                $entries[] = [
                    'coordinate' => $coordinate,
                    'status' => 'resolved',
                    'reason' => 'ok',
                    'article_title' => (string) ($article->getTitle() ?? ''),
                    'article_slug' => (string) ($article->getSlug() ?? ''),
                ];
                $resolved++;
            }
            $listed = \count($coords);
            $totListed += $listed;
            $totResolved += $resolved;
            $totMissing += $missing;
            $categories[] = [
                'slug' => $slug,
                'title' => $title,
                'event_id' => $catIndex->getId(),
                'listed_total' => $listed,
                'resolved_total' => $resolved,
                'missing_total' => $missing,
                'entries' => $entries,
            ];
        }

        return [
            'categories' => $categories,
            'totals' => [
                'categories' => \count($categories),
                'listed' => $totListed,
                'resolved' => $totResolved,
                'missing' => $totMissing,
            ],
        ];
    }

    /**
     * @param array{
     *   categories: list<array{
     *     entries: list<array{coordinate: string, status: string, reason: string}>
     *   }>
     * } $report
     * @return list<string>
     */
    public function missingInDbCoordinatesFromCoverageReport(array $report): array
    {
        $out = [];
        foreach ($report['categories'] ?? [] as $cat) {
            foreach ($cat['entries'] ?? [] as $entry) {
                if (($entry['status'] ?? '') !== 'missing') {
                    continue;
                }
                if (($entry['reason'] ?? '') !== 'article_not_in_db') {
                    continue;
                }
                $coord = isset($entry['coordinate']) ? (string) $entry['coordinate'] : '';
                if ($coord !== '') {
                    $out[] = $coord;
                }
            }
        }

        return array_values(array_unique($out));
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

    /**
     * Interleaves up to four articles per home category in round-robin order (one “wall” mixing all topics).
     * Duplicate slugs across categories are skipped so each article appears at most once.
     *
     * @param list<array<int, string>> $categoryATags
     *
     * @return list<array{article: FeaturedArticleCard, categoryTitle: string}>
     */
    public function buildHomeMixedFeaturedWallTiles(array $categoryATags): array
    {
        $blocks = [];
        foreach ($categoryATags as $row) {
            $coord = $row[1] ?? '';
            if (!\is_string($coord) || $coord === '') {
                continue;
            }
            $b = $this->buildCategoryFeaturedBlock($coord);
            if ($b !== null && $b['cards'] !== []) {
                $blocks[] = $b;
            }
        }
        if ($blocks === []) {
            return [];
        }

        $pointers = array_fill(0, \count($blocks), 0);
        $seenSlugs = [];
        $out = [];

        while (true) {
            $roundAdded = false;
            for ($i = 0, $n = \count($blocks); $i < $n; ++$i) {
                while (isset($blocks[$i]['cards'][$pointers[$i]])) {
                    $card = $blocks[$i]['cards'][$pointers[$i]];
                    $slug = \trim((string) $card->getSlug());
                    if ($slug !== '' && isset($seenSlugs[$slug])) {
                        ++$pointers[$i];
                        continue;
                    }
                    if ($slug !== '') {
                        $seenSlugs[$slug] = true;
                    }
                    $out[] = [
                        'article' => $card,
                        'categoryTitle' => $blocks[$i]['title'],
                    ];
                    ++$pointers[$i];
                    $roundAdded = true;
                    break;
                }
            }
            if (!$roundAdded) {
                break;
            }
        }

        return $out;
    }

    /**
     * Same resolution as {@see \App\Twig\Components\Organisms\FeaturedList} (4 cards per category).
     *
     * @return null|array{title: string, cards: list<FeaturedArticleCard>}
     */
    private function buildCategoryFeaturedBlock(string $categoryCoord): ?array
    {
        $parts = explode(':', $categoryCoord, 3);
        if (\count($parts) < 3) {
            return null;
        }
        $slug = $parts[2];
        $catIndex = $this->store->getCategory($slug);
        if (!\is_object($catIndex) || !\method_exists($catIndex, 'getTags')) {
            return null;
        }

        $title = '';
        $slugs = [];
        foreach ($catIndex->getTags() as $tag) {
            if (($tag[0] ?? null) === 'title' && isset($tag[1])) {
                $title = (string) $tag[1];
            }
            if (($tag[0] ?? null) === 'a' && isset($tag[1])) {
                $segs = explode(':', (string) $tag[1], 3);
                $slugs[] = \trim((string) end($segs));
                if (\count($slugs) >= 5) {
                    break;
                }
            }
        }

        if ($title === '') {
            $title = $slug;
        }
        if ($slugs === []) {
            return null;
        }

        $articles = $this->articleRepository->findFeaturedCardsBySlugs($slugs);
        $slugMap = [];
        foreach ($articles as $article) {
            $articleSlug = \trim((string) $article->getSlug());
            if ($articleSlug !== '') {
                if (!isset($slugMap[$articleSlug])) {
                    $slugMap[$articleSlug] = $article;
                } elseif ($this->featuredCardIsNewer($article, $slugMap[$articleSlug])) {
                    $slugMap[$articleSlug] = $article;
                }
            }
        }
        $orderedList = [];
        foreach ($slugs as $articleSlug) {
            $articleSlug = \trim((string) $articleSlug);
            if ($articleSlug !== '' && isset($slugMap[$articleSlug])) {
                $orderedList[] = $slugMap[$articleSlug];
            }
        }
        $cards = \array_slice($orderedList, 0, 4);

        return ['title' => $title, 'cards' => $cards];
    }

    private function featuredCardIsNewer(FeaturedArticleCard $a, FeaturedArticleCard $b): bool
    {
        $ca = $a->getDisplayAt();
        $cb = $b->getDisplayAt();
        if ($ca === null) {
            return false;
        }
        if ($cb === null) {
            return true;
        }

        return $ca > $cb;
    }
}
