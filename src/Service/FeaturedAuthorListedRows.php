<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FeaturedAuthorRepository;
use swentel\nostr\Key\Key;

/**
 * NIP-05 / listed featured author rows (same shape as {@see \App\Controller\FeaturedAuthorsController}).
 */
final class FeaturedAuthorListedRows
{
    public function __construct(
        private readonly FeaturedAuthorRepository $featuredAuthorRepository,
        private readonly CacheService $cacheService,
    ) {
    }

    /**
     * @return list<array{npub: string, pubkey: string, display_name: string, picture: string, local_part: string}>
     */
    public function buildListedByLocalPartPage(int $limit, int $offset = 0): array
    {
        $keys = new Key();
        $authors = [];
        foreach ($this->featuredAuthorRepository->findListedOrderByLocalPartPaginated($limit, $offset) as $fa) {
            $npub = $keys->convertPublicKeyToBech32($fa->getPubkeyHex());
            $bundle = $this->cacheService->getMetadataBundle($npub);
            $author = $bundle['content'];
            $displayName = trim((string) ($author->display_name ?? $author->name ?? ''));
            $picture = trim((string) ($author->picture ?? ''));
            $authors[] = [
                'npub' => $npub,
                'pubkey' => strtolower($fa->getPubkeyHex()),
                'display_name' => $displayName,
                'picture' => $picture,
                'local_part' => $fa->getLocalPart(),
            ];
        }

        return $authors;
    }
}
