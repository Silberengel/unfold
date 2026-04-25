<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\FeaturedAuthorListedRows;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Twig function for the left nav featured-author avatars (avoids Symfony UX component context quirks). */
final class SidebarFeaturedAuthorsExtension extends AbstractExtension
{
    public function __construct(
        private readonly FeaturedAuthorListedRows $featuredAuthorListedRows,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sidebar_featured_author_rows', function (int $limit = 12): array {
                return $this->featuredAuthorListedRows->buildSidebarRows($limit);
            }),
        ];
    }
}
