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
        // Store only: never block the response on relay I/O (cron/pre-warm updates the store).
        $this->cats = $this->magazineContent->getHomeCategoryAIndexTagsFromStoreOnly();
    }
}
