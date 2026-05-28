<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\EventStatusEnum;
use App\Repository\ArticleRepository;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Top topics for the sidebar and topic browse pages.
 */
final class TopicIndexService
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly MagazineContentService $magazineContent,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Most relevant topic strings (default cap 10 in sidebar), scored by count + 5× featured (magazine home cards).
     *
     * @return list<string> topic labels (lowercase, no #)
     */
    public function getTopTopicLabels(int $limit = 10): array
    {
        $categoryATags = $this->magazineContent->getHomeCategoryAIndexTagsFromStoreOnly();
        $curatedSlugs = $this->magazineContent->collectCuratedArticleSlugsForTenant($categoryATags);
        if ($curatedSlugs === []) {
            return [];
        }

        $conn = $this->articleRepository->getEntityManager()->getConnection();
        $slugs = $this->magazineContent->collectFeaturedArticleSlugsForHome($categoryATags);
        $featured = [];
        foreach ($slugs as $s) {
            $s = \strtolower(\trim((string) $s));
            if ($s !== '') {
                $featured[$s] = true;
            }
        }

        $rows = $conn->fetchAllAssociative(
            'SELECT a.slug, a.topics FROM article a
             INNER JOIN article_magazine am ON am.article_id = a.id AND am.magazine_slug = :mag
             WHERE a.slug IN (:curated)
               AND a.topics IS NOT NULL
               AND a.content IS NOT NULL
               AND CHAR_LENGTH(a.content) > 250
               AND a.event_status IN (:st)',
            [
                'mag' => $this->tenant->getMagazineSlug(),
                'curated' => $curatedSlugs,
                'st' => [EventStatusEnum::PUBLISHED->value, EventStatusEnum::ARCHIVED->value],
            ],
            [
                'curated' => ArrayParameterType::STRING,
                'st' => ArrayParameterType::INTEGER,
            ],
        );

        $acc = [];
        foreach ($rows as $row) {
            $raw = $row['topics'] ?? null;
            if (\is_array($raw)) {
                $dec = $raw;
            } elseif (\is_string($raw) && $raw !== '') {
                $dec = json_decode($raw, true);
            } else {
                continue;
            }
            if (!\is_array($dec)) {
                continue;
            }
            $slug = \strtolower(\trim((string) ($row['slug'] ?? '')));
            $isFeat = $slug !== '' && isset($featured[$slug]);
            foreach ($dec as $t) {
                if (!\is_string($t) || $t === '') {
                    continue;
                }
                $k = \str_replace('#', '', \strtolower(\trim($t)));
                if ($k === '') {
                    continue;
                }
                if (!isset($acc[$k])) {
                    $acc[$k] = ['c' => 0, 'f' => 0];
                }
                ++$acc[$k]['c'];
                if ($isFeat) {
                    ++$acc[$k]['f'];
                }
            }
        }

        uasort(
            $acc,
            static function (array $a, array $b): int {
                $sa = $a['c'] + 5 * $a['f'];
                $sb = $b['c'] + 5 * $b['f'];
                if ($sa === $sb) {
                    return $b['c'] <=> $a['c'];
                }

                return $sb <=> $sa;
            }
        );

        $keys = array_keys($acc);

        return \array_slice($keys, 0, max(0, $limit));
    }
}
