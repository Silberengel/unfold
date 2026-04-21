<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\NostrClient;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Header
{
    public array $cats;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ParameterBagInterface $params,
        private readonly NostrClient $nostrClient,
    ) {
        $dTag = (string) $this->params->get('d_tag');
        $npub = (string) $this->params->get('npub');
        // Same key as {@see DefaultController::index()} — must load the real index (not cache `null`).
        $cacheKey = 'magazine_root_'.$dTag;
        $mag = $this->cache->get($cacheKey, function (ItemInterface $item) use ($npub, $dTag) {
            $item->expiresAfter(300);

            return $this->nostrClient->getMagazineIndex($npub, $dTag);
        });

        if ($mag === null) {
            $this->cats = [];

            return;
        }

        $tags = $mag->getTags();

        $this->cats = array_filter($tags, static function ($tag): bool {
            return ($tag[0] ?? null) === 'a';
        });
    }
}
