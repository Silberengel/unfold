<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\MagazineContentService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Header
{
    public array $cats;

    public function __construct(
        private readonly MagazineContentService $magazineContent,
    ) {
        $this->cats = $this->magazineContent->getHomeCategoryIndexTags();
    }
}
