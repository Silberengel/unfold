<?php

namespace App\Twig\Components\Organisms;

use App\Dto\FeaturedArticleCard;
use App\Repository\ArticleRepository;
use App\Service\MagazineIndexStore;
use Psr\Cache\InvalidArgumentException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class FeaturedList
{
    public string $category = '';

    public string $title = '';

    public array $list = [];

    public function __construct(
        private readonly MagazineIndexStore $store,
        private readonly ArticleRepository $articleRepository,
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

        $catIndex = $this->store->getCategory($slug);
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

        $articles = $this->articleRepository->findFeaturedCardsBySlugs($slugs);

        $slugMap = [];
        foreach ($articles as $article) {
            $articleSlug = trim((string) $article->getSlug());
            if ($articleSlug !== '') {
                if (!isset($slugMap[$articleSlug])) {
                    $slugMap[$articleSlug] = $article;
                } elseif (self::isNewer($article, $slugMap[$articleSlug])) {
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

    private static function isNewer(FeaturedArticleCard $a, FeaturedArticleCard $b): bool
    {
        $ca = $a->getCreatedAt();
        $cb = $b->getCreatedAt();
        if ($ca === null) {
            return false;
        }
        if ($cb === null) {
            return true;
        }

        return $ca > $cb;
    }
}
