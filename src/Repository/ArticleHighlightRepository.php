<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\ArticleHighlight;
use App\Enum\EventStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArticleHighlight>
 */
class ArticleHighlightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArticleHighlight::class);
    }

    /**
     * Newest highlights across published/archived long-form, for the home aside.
     *
     * @return list<ArticleHighlight>
     */
    public function findRecentForHome(int $limit = 36): array
    {
        if ($limit <= 0) {
            return [];
        }

        $qb = $this->createQueryBuilder('h')
            ->innerJoin('h.article', 'a')
            ->where('a.eventStatus IN (:st)')
            ->setParameter('st', [EventStatusEnum::PUBLISHED, EventStatusEnum::ARCHIVED])
            ->orderBy('h.eventCreatedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults($limit);

        /** @var list<ArticleHighlight> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @return list<ArticleHighlight>
     */
    public function findByArticle(Article $article): array
    {
        $id = $article->getId();
        if (null === $id || (int) $id < 1) {
            return [];
        }

        /** @var list<ArticleHighlight> $out */
        $out = $this->createQueryBuilder('h')
            ->where('h.article = :art')
            ->setParameter('art', $article)
            ->orderBy('h.eventCreatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $out;
    }
}
