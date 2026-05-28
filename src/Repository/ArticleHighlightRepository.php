<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\ArticleHighlight;
use App\Enum\EventStatusEnum;
use App\Service\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArticleHighlight>
 */
class ArticleHighlightRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($registry, ArticleHighlight::class);
    }

    /**
     * Newest highlights across published/archived long-form, for the home aside.
     * At most one highlight is returned per article so a single heavily-highlighted
     * article cannot flood the sidebar.  The most recent highlight for each article
     * wins (ORDER BY eventCreatedAt DESC).
     *
     * @return list<ArticleHighlight>
     */
    public function findRecentForHome(int $limit = 100): array
    {
        if ($limit <= 0) {
            return [];
        }

        // Fetch a larger pool so that after per-article deduplication we still
        // have enough items to fill the sidebar.  Cap the raw fetch at 2 000 to
        // avoid unbounded memory use on busy sites.
        $fetchLimit = min($limit * 20, 2000);

        $qb = $this->createQueryBuilder('h')
            ->innerJoin('h.article', 'a')
            ->innerJoin(
                'App\Entity\ArticleMagazine',
                'am',
                'WITH',
                'am.article = a AND am.magazineSlug = :mag'
            )
            ->where('a.eventStatus IN (:st)')
            ->setParameter('mag', $this->tenant->getMagazineSlug())
            ->setParameter('st', [EventStatusEnum::PUBLISHED, EventStatusEnum::ARCHIVED])
            ->orderBy('h.eventCreatedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults($fetchLimit);

        /** @var list<ArticleHighlight> $rows */
        $rows = $qb->getQuery()->getResult();

        // Keep only the first (= most recent) highlight per article.
        $seen = [];
        $out = [];
        foreach ($rows as $h) {
            $article = $h->getArticle();
            $articleId = $article?->getId();
            if ($articleId === null) {
                continue;
            }
            if (isset($seen[$articleId])) {
                continue;
            }
            $seen[$articleId] = true;
            $out[] = $h;
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Returns highlights for this NIP-33 address (kind + pubkey + slug), not only the current
     * {@see Article} row id — `article` can have multiple DB rows for the same slug (revisions).
     *
     * @return list<ArticleHighlight>
     */
    public function findByArticle(Article $article): array
    {
        $id = $article->getId();
        if (null === $id || (int) $id < 1) {
            return [];
        }

        $pubkey = (string) $article->getPubkey();
        if ('' === $pubkey) {
            return [];
        }
        $slug = trim((string) $article->getSlug());
        if ('' === $slug) {
            return [];
        }

        $qb = $this->createQueryBuilder('h')
            ->innerJoin('h.article', 'a')
            // Hex pubkeys are case-insensitive; utf8mb4_bin would otherwise miss rows.
            ->where('LOWER(a.pubkey) = LOWER(:pubkey)')
            ->andWhere('a.slug = :slug')
            ->setParameter('pubkey', $pubkey)
            ->setParameter('slug', $slug)
            ->orderBy('h.eventCreatedAt', 'DESC');

        // Do not filter on `a.kind`: replaceable long-form can leave several `article` rows per slug
        // with different kind (or NULL vs 30023). Highlights are still tied to the same NIP-33
        // address; filtering by the *current* row's kind dropped rows synced to an older revision.

        /** @var list<ArticleHighlight> $out */
        $out = $qb->getQuery()->getResult();

        return $out;
    }
}
