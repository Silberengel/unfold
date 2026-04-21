<?php

namespace App\Twig\Components\Molecules;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CategoryLink
{
    public string $title = '';

    public string $slug = '';

    public function __construct(private CacheInterface $cache)
    {
    }

    public function mount($category): void
    {
        $coord = $category[1] ?? '';
        $parts = explode(':', (string) $coord, 3);
        $this->slug = $parts[2] ?? '';
        $this->title = $this->slug !== '' ? $this->slug : 'Category';

        try {
            $cat = $this->cache->get('magazine-' . $this->slug, function () {
                throw new \RuntimeException('Not found');
            });

            $tags = method_exists($cat, 'getTags') ? $cat->getTags() : [];

            $titleTags = array_filter($tags, static function ($tag): bool {
                return isset($tag[0]) && $tag[0] === 'title' && isset($tag[1]);
            });

            $first = array_key_first($titleTags);
            if ($first !== null) {
                $this->title = (string) $titleTags[$first][1];
            }
        } catch (\Throwable) {
            // Cache miss or unreadable index: keep slug-based fallback title
        }
    }
}
