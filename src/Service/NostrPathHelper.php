<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use swentel\nostr\Key\Key;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Canonical /p/{npub}/d/{slug} links for long-form and helpers for templates.
 */
final class NostrPathHelper
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function npubFromPubkeyHex(string $pubkeyHex): string
    {
        return (new Key())->convertPublicKeyToBech32($pubkeyHex);
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

        return $this->router->generate('article', [
            'npub' => $this->npubFromPubkeyHex((string) $article->getPubkey()),
            'slug' => $slug,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
