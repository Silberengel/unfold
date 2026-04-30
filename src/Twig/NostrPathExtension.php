<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Article;
use App\Service\NostrKeyHelper;
use App\Service\NostrPathHelper;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NostrPathExtension extends AbstractExtension
{
    public function __construct(
        private readonly NostrPathHelper $nostrPathHelper,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('npub_from_hex', $this->npubFromHex(...)),
            new TwigFunction('pubkey_hex_from_npub', $this->pubkeyHexFromNpub(...)),
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

    /**
     * Lowercase 64-hex pubkey for {@see ArticleCardCoverExtension::articleCardCover} and article URLs.
     */
    public function pubkeyHexFromNpub(string $npub): string
    {
        $n = trim($npub);
        if ($n === '') {
            return '';
        }
        try {
            $hex = $this->nostrKeyHelper->convertToHex($n);
            if (64 !== \strlen($hex) || !ctype_xdigit($hex)) {
                return '';
            }

            return strtolower($hex);
        } catch (Throwable) {
            return '';
        }
    }

    public function articlePath(Article $article): string
    {
        return $this->nostrPathHelper->articlePath($article);
    }
}
