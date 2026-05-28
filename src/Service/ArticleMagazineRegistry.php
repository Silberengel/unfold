<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Repository\ArticleMagazineRepository;

/**
 * Associates ingested long-form rows with the current magazine tenant on a shared database.
 */
final class ArticleMagazineRegistry
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ArticleMagazineRepository $articleMagazineRepository,
    ) {
    }

    public function link(Article $article): void
    {
        $this->articleMagazineRepository->link($this->tenant->getMagazineSlug(), $article);
    }
}
