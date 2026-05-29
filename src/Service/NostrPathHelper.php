<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Canonical /p/{npub}/d/{slug} links for long-form and helpers for templates.
 */
final class NostrPathHelper
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function npubFromPubkeyHex(string $pubkeyHex): string
    {
        return $this->nostrKeyHelper->convertPublicKeyToBech32($pubkeyHex);
    }

    public function articlePath(Article $article): string
    {
        $slug = (string) ($article->getSlug() ?? '');
        if ($slug === '' || $article->getPubkey() === null) {
            return '';
        }
        $npub = $this->npubFromPubkeyHex((string) $article->getPubkey());

        return $this->router->generate('article', [
            'npub' => $npub,
            'slug' => $slug,
        ]);
    }

    public function articleAbsoluteUrl(Article $article): string
    {
        $slug = (string) ($article->getSlug() ?? '');
        if ($slug === '' || $article->getPubkey() === null) {
            return '';
        }

        try {
            return $this->router->generate('article', [
                'npub' => $this->npubFromPubkeyHex((string) $article->getPubkey()),
                'slug' => $slug,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return '';
        }
    }

    public function publicationAbsoluteUrl(string $npub, string $slug): string
    {
        $npub = trim($npub);
        $slug = trim($slug);
        if ($npub === '' || $slug === '' || str_contains($slug, '/')) {
            return '';
        }

        try {
            return $this->router->generate('publication', [
                'npub' => $npub,
                'slug' => $slug,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return '';
        }
    }
}
