<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Article;
use App\Service\NostrPathHelper;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NostrPathExtension extends AbstractExtension
{
    public function __construct(
        private readonly NostrPathHelper $nostrPathHelper,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('npub_from_hex', $this->npubFromHex(...)),
            new TwigFunction('article_path', $this->articlePath(...)),
        ];
    }

    public function npubFromHex(string $pubkeyHex): string
    {
        if (64 !== \strlen($pubkeyHex) || !ctype_xdigit($pubkeyHex)) {
            return '';
        }

        return $this->nostrPathHelper->npubFromPubkeyHex($pubkeyHex);
    }

    public function articlePath(Article $article): string
    {
        return $this->nostrPathHelper->articlePath($article);
    }
}
