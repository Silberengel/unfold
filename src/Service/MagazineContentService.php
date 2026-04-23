<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\EventStatusEnum;
use App\Repository\ArticleRepository;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Magazine index events for templates. Reads {@see MagazineIndexStore} first; on a cold cache or when
 * the last successful relay sync is older than {@see self::ROOT_REVALIDATE_SECONDS}, the service
 * calls {@see MagazineRefresher} so the root index (and nav) can pick up new categories.
 */
final class MagazineContentService
{
    /** Re-fetch root from relays at most this often so new `a` tags appear in the header. */
    private const ROOT_REVALIDATE_SECONDS = 300;

    public function __construct(
        private readonly MagazineIndexStore $store,
        private readonly MagazineRefresher $refresher,
        private readonly ParameterBagInterface $params,
        private readonly ArticleRepository $articleRepository,
        private readonly NostrClient $nostrClient,
    ) {
    }

    /**
     * "indices" for the home template: Nostr `a` tag rows for each category.
     *
     * @return list<array<int, string>>
     */
    public function getHomeCategoryIndexTags(): array
    {
        $npub = (string) $this->params->get('npub');
        $dTag = (string) $this->params->get('d_tag');
        if ($this->store->getRoot($npub, $dTag) === null) {
            $this->refresher->refreshFromRelays(20, []);
        } elseif ($this->shouldRevalidateRootFromRelay()) {
            $this->refresher->refreshFromRelays(20, []);
        }

        return $this->getHomeCategoryAIndexTagsFromStoreOnly();
    }

    /**
     * Category `a` tags from the persisted root only (no relay). Used after /ux/magazine-sync
     * has already called {@see MagazineRefresher::refreshFromRelays}.
     *
     * @return list<array<int, string>>
     */
    public function getHomeCategoryAIndexTagsFromStoreOnly(): array
    {
        return $this->categoryATagsFromStoredRoot();
    }

    /**
     * @return list<array<int, string>>
     */
    private function categoryATagsFromStoredRoot(): array
    {
        $npub = (string) $this->params->get('npub');
        $dTag = (string) $this->params->get('d_tag');
        $mag = $this->store->getRoot($npub, $dTag);

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
        $cats = array_filter($tags, static function (mixed $tag): bool {
            return \is_array($tag) && ($tag[0] ?? null) === 'a';
        });

        return array_values($cats);
    }

    private function shouldRevalidateRootFromRelay(): bool
    {
        $age = $this->refresher->getSecondsSinceLastRelayRun();
        if ($age === null) {
            return true;
        }

        return $age > self::ROOT_REVALIDATE_SECONDS;
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
     * Title from cached category index event tags, or the slug when missing.
     */
    public function getCategoryDisplayTitle(string $slug): string
    {
        if ($slug === '') {
            return '';
        }
        $catIndex = $this->store->getCategory($slug);
        if ($catIndex === null) {
            return $slug;
        }
        foreach ($catIndex->getTags() as $tag) {
            if (($tag[0] ?? null) === 'title' && isset($tag[1])) {
                return (string) $tag[1];
            }
        }

        return $slug;
    }

    /**
     * @return array{list: list<Article>, category: array{title: string, summary: string}}
     */
    public function getCategoryPageData(string $slug): array
    {
        $catIndex = $this->store->getCategory($slug);
        if ($catIndex === null) {
            $this->refresher->refreshFromRelays(20, [$slug]);
            $catIndex = $this->store->getCategory($slug);
        }
        $list = [];
        $coordinates = [];
        $category = [];
        if ($catIndex) {
            foreach ($catIndex->getTags() as $tag) {
                if ($tag[0] === 'title') {
                    $category['title'] = (string) $tag[1];
                }
                if ($tag[0] === 'summary') {
                    $category['summary'] = (string) $tag[1];
                }
                if ($tag[0] === 'a') {
                    $coordinates[] = $tag[1];
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
                    'pubkey' => (string) $parts[1],
                    'slug' => $slugPart,
                ];
            }
            $byAddress = $this->articleRepository->findByAuthorAndSlugIndexed($pairs);
            $missing = [];
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', (string) $coordinate, 3);
                if (\count($parts) < 3) {
                    continue;
                }
                $k = (string) $parts[1]."\0".trim((string) $parts[2]);
                if (!isset($byAddress[$k])) {
                    $missing[] = (string) $coordinate;
                }
            }
            if ($missing !== []) {
                $this->nostrClient->ingestMissingLongformForCategoryCoordinates($missing);
                $byAddress = $this->articleRepository->findByAuthorAndSlugIndexed($pairs);
            }
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', (string) $coordinate, 3);
                if (\count($parts) < 3) {
                    continue;
                }
                $k = (string) $parts[1]."\0".trim((string) $parts[2]);
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
}
