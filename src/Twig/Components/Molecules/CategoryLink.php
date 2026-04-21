<?php

namespace App\Twig\Components\Molecules;

use App\Service\NostrClient;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CategoryLink
{
    public string $title = '';

    public string $slug = '';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ParameterBagInterface $params,
        private readonly NostrClient $nostrClient,
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
        $npub = (string) $this->params->get('npub');
        // Same cache key/TTL as DefaultController::magCategory(); load from relay on miss (not read-only).
        // The cache callback must return data on miss; otherwise the homepage shows raw d-tags.
        try {
            $cat = $this->cache->get('magazine-' . $this->slug, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(300);
                $mag = $this->nostrClient->getMagazineIndex($npub, $this->slug);
                if ($mag === null) {
                    // Do not persist null: FeaturedList would get a cache hit and call getTags() on null.
                    throw new \RuntimeException('Category index not found for '.$this->slug);
                }

                return $mag;
            });
        } catch (\Throwable) {
            return;
        }

        if (!\is_object($cat) || !\method_exists($cat, 'getTags')) {
            return;
        }

        $tags = $cat->getTags();
        $titleTags = array_filter($tags, static function ($tag): bool {
            return isset($tag[0]) && $tag[0] === 'title' && isset($tag[1]);
        });
        $first = array_key_first($titleTags);
        if ($first !== null) {
            $this->title = (string) $titleTags[$first][1];
        }
    }
}
