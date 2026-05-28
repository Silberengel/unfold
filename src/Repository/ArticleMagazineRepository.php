<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\ArticleMagazine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArticleMagazine>
 */
class ArticleMagazineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArticleMagazine::class);
    }

    public function link(string $magazineSlug, Article $article): void
    {
        $id = $article->getId();
        if ($id === null) {
            return;
        }
        $existing = $this->findOneBy([
            'magazineSlug' => $magazineSlug,
            'article' => $article,
        ]);
        if ($existing !== null) {
            return;
        }
        $this->getEntityManager()->persist(new ArticleMagazine($magazineSlug, $article));
        $this->getEntityManager()->flush();
    }
}
