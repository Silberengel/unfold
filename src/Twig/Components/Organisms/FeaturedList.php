<?php

namespace App\Twig\Components\Organisms;

use App\Repository\ArticleRepository;
use App\Service\NostrClient;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class FeaturedList
{
    public string $category = '';

    public string $title = '';

    public array $list = [];

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ArticleRepository $articleRepository,
        private readonly NostrClient $nostrClient,
        private readonly ParameterBagInterface $params,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function mount($category): void
    {
        $this->list = [];
        $this->title = '';

        $coord = $category[1] ?? '';
        $this->category = (string) $coord;
        $parts = explode(':', $this->category, 3);
        if (\count($parts) < 3) {
            return;
        }

        $slug = $parts[2];
        $npub = (string) $this->params->get('npub');

        try {
            $catIndex = $this->cache->get('magazine-' . $slug, function (ItemInterface $item) use ($npub, $slug) {
                $item->expiresAfter(300);
                $mag = $this->nostrClient->getMagazineIndex($npub, $slug);
                if ($mag === null) {
                    throw new \RuntimeException('Category index not found for '.$slug);
                }

                return $mag;
            });
        } catch (\Throwable) {
            return;
        }

        if (!\is_object($catIndex) || !\method_exists($catIndex, 'getTags')) {
            return;
        }

        $slugs = [];
        foreach ($catIndex->getTags() as $tag) {
            if (($tag[0] ?? null) === 'title' && isset($tag[1])) {
                $this->title = (string) $tag[1];
            }
            if (($tag[0] ?? null) === 'a' && isset($tag[1])) {
                $segs = explode(':', (string) $tag[1], 3);
                $slugs[] = trim((string) end($segs));
                if (\count($slugs) >= 5) {
                    break;
                }
            }
        }

        if ($this->title === '') {
            $this->title = $slug;
        }

        if ($slugs === []) {
            return;
        }

        $articles = $this->articleRepository->findBySlugsCriteria($slugs);

        $slugMap = [];
        foreach ($articles as $article) {
            $articleSlug = trim((string) $article->getSlug());
            if ($articleSlug !== '') {
                if (!isset($slugMap[$articleSlug])) {
                    $slugMap[$articleSlug] = $article;
                } elseif ($article->getCreatedAt() > $slugMap[$articleSlug]->getCreatedAt()) {
                    $slugMap[$articleSlug] = $article;
                }
            }
        }

        $orderedList = [];
        foreach ($slugs as $articleSlug) {
            $articleSlug = trim((string) $articleSlug);
            if ($articleSlug !== '' && isset($slugMap[$articleSlug])) {
                $orderedList[] = $slugMap[$articleSlug];
            }
        }

        $this->list = array_slice($orderedList, 0, 4);
    }
}
