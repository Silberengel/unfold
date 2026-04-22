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
        // Same key as {@see DefaultController::index()}. If the relay returns nothing, throw from the
        // callback so Symfony does not persist `null` — otherwise categories vanish until TTL (~5 min).
        $cacheKey = 'magazine_root_v2_'.$dTag;
        try {
            $mag = $this->cache->get($cacheKey, function (ItemInterface $item) use ($npub, $dTag) {
                $item->expiresAfter(300);
                $mag = $this->nostrClient->getMagazineIndex($npub, $dTag);
                if ($mag === null) {
                    throw new \RuntimeException('Magazine root index not found for '.$dTag);
                }

                return $mag;
            });
        } catch (\Throwable) {
            $this->cats = [];

            return;
        }

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
