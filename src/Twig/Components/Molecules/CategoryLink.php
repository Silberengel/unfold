<?php

namespace App\Twig\Components\Molecules;

use App\Service\MagazineIndexStore;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CategoryLink
{
    public string $title = '';

    public string $slug = '';

    public function __construct(
        private readonly MagazineIndexStore $store,
    ) {
    }

    public function mount($category): void
    {
        $coord = $category[1] ?? '';
        $parts = explode(':', (string) $coord, 3);
        $this->slug = $parts[2] ?? '';
        if ($this->slug === '') {
            $this->title = 'Category';

            return;
        }

        $this->title = $this->slug;
        $cat = $this->store->getCategory($this->slug);
        if (!\is_object($cat) || !\method_exists($cat, 'getTags')) {
            return;
        }

        $tags = $cat->getTags();
        $titleTags = array_filter($tags, static function (mixed $tag): bool {
            return \is_array($tag) && ($tag[0] ?? null) === 'title' && isset($tag[1]);
        });
        $first = array_key_first($titleTags);
        if ($first !== null) {
            $this->title = (string) $titleTags[$first][1];
        }
    }
}
