<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\MagazineContentService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Footer
{
    /** @var list<array{slug: string, title: string}> */
    public array $categoriesForFeed = [];

    public function __construct(
        private readonly MagazineContentService $magazineContent,
    ) {
    }

    public function mount(): void
    {
        $this->categoriesForFeed = [];
        foreach ($this->magazineContent->getCategorySlugsFromStore() as $slug) {
            $this->categoriesForFeed[] = [
                'slug' => $slug,
                'title' => $this->magazineContent->getCategoryDisplayTitle($slug),
            ];
        }
    }
}
