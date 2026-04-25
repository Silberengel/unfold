<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FeaturedAuthorRepository;
use swentel\nostr\Key\Key;

/**
 * NIP-05 / listed featured author rows (same shape as {@see \App\Controller\FeaturedAuthorsController}).
 * Sidebar: falls back to magazine index pubkeys when the `featured_author` list is still empty
 * (e.g. prewarm has not yet synced the table).
 */
final class FeaturedAuthorListedRows
{
    public function __construct(
        private readonly FeaturedAuthorRepository $featuredAuthorRepository,
        private readonly CacheService $cacheService,
        private readonly MagazineContentService $magazineContent,
    ) {
    }

    /**
     * Rows for the left nav: NIP-05–listed authors first, otherwise authors from the magazine index store.
     *
     * @return list<array{npub: string, pubkey: string, display_name: string, picture: string, local_part: string}>
     */
    public function buildSidebarRows(int $limit = 12): array
    {
        $fromDb = $this->buildListedByLocalPartPage($limit, 0);
        if ($fromDb !== []) {
            return $fromDb;
        }

        $keys = new Key();
        $authors = [];
        $hexes = $this->magazineContent->getAllDistinctCategoryAuthorPubkeyHexes();
        foreach (\array_slice($hexes, 0, $limit) as $hex) {
            try {
                $npub = $keys->convertPublicKeyToBech32($hex);
            } catch (\Throwable) {
                continue;
            }
            $authors[] = $this->rowFromNpub($npub, $hex, '');
        }

        return $authors;
    }

    /**
     * @return list<array{npub: string, pubkey: string, display_name: string, picture: string, local_part: string}>
     */
    public function buildListedByLocalPartPage(int $limit, int $offset = 0): array
    {
        $keys = new Key();
        $authors = [];
        foreach ($this->featuredAuthorRepository->findListedOrderByLocalPartPaginated($limit, $offset) as $fa) {
            try {
                $npub = $keys->convertPublicKeyToBech32($fa->getPubkeyHex());
            } catch (\Throwable) {
                continue;
            }
            $authors[] = $this->rowFromNpub($npub, $fa->getPubkeyHex(), $fa->getLocalPart());
        }

        return $authors;
    }

    /**
     * @return array{npub: string, pubkey: string, display_name: string, picture: string, local_part: string}
     */
    private function rowFromNpub(string $npub, string $pubkeyHex, string $localPart): array
    {
        $bundle = $this->cacheService->getMetadataBundle($npub);
        $author = $bundle['content'];
        $displayName = trim((string) ($author->display_name ?? $author->name ?? ''));
        $picture = trim((string) ($author->picture ?? ''));

        return [
            'npub' => $npub,
            'pubkey' => strtolower($pubkeyHex),
            'display_name' => $displayName,
            'picture' => $picture,
            'local_part' => $localPart,
        ];
    }
}
