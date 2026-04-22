<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
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
            $this->refresher->refreshFromRelays(8, []);
        } elseif ($this->shouldRevalidateRootFromRelay()) {
            $this->refresher->refreshFromRelays(8, []);
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
     * @return array{list: list<Article>, category: array{title: string, summary: string}}
     */
    public function getCategoryPageData(string $slug): array
    {
        $catIndex = $this->store->getCategory($slug);
        if ($catIndex === null) {
            $this->refresher->refreshFromRelays(8, [$slug]);
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
            $slugs = array_map(static function ($coordinate) {
                $parts = explode(':', (string) $coordinate, 3);

                return trim((string) end($parts));
            }, $coordinates);
            $slugs = array_values(array_filter($slugs, static fn (string $s): bool => $s !== ''));
            $articles = $this->articleRepository->findBySlugsCriteria($slugs);
            $slugMap = [];
            foreach ($articles as $item) {
                $s = trim((string) $item->getSlug());
                if ($s !== '') {
                    if (!isset($slugMap[$s])) {
                        $slugMap[$s] = $item;
                    } else {
                        $existingItem = $slugMap[$s];
                        if ($item->getCreatedAt() > $existingItem->getCreatedAt()) {
                            $slugMap[$s] = $item;
                        }
                    }
                }
            }
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', (string) $coordinate, 3);
                $slugKey = trim((string) end($parts));
                if ($slugKey !== '' && isset($slugMap[$slugKey])) {
                    $list[] = $slugMap[$slugKey];
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
}
