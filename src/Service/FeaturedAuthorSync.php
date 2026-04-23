<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FeaturedAuthor;
use App\Repository\FeaturedAuthorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;

/**
 * Adds {@see FeaturedAuthor} rows for pubkeys found in magazine category indices; assigns
 * unique NIP-05 local-parts from kind-0 name when possible. Does not remove or re-list rows.
 */
final class FeaturedAuthorSync
{
    public function __construct(
        private readonly MagazineContentService $magazineContent,
        private readonly FeaturedAuthorRepository $featuredAuthorRepository,
        private readonly CacheService $cacheService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int Number of newly persisted authors
     */
    public function syncNewAuthorsFromMagazineCategories(): int
    {
        $pubkeys = $this->magazineContent->getAllDistinctCategoryAuthorPubkeyHexes();
        if ($pubkeys === []) {
            return 0;
        }

        $keys = new Key();
        $n = 0;
        foreach ($pubkeys as $hex) {
            if ($this->featuredAuthorRepository->findOneByPubkeyHex($hex) !== null) {
                continue;
            }
            $entity = new FeaturedAuthor();
            $entity->setPubkeyHex($hex);
            $base = $this->deriveBaseLocalPart($keys, $hex);
            $entity->setLocalPart($this->allocateUniqueLocalPart($base));
            $this->entityManager->persist($entity);
            ++$n;
        }
        if ($n > 0) {
            $this->entityManager->flush();
            $this->logger->info('featured_author.sync', ['new_count' => $n]);
        }

        return $n;
    }

    private function deriveBaseLocalPart(Key $keys, string $pubkeyHex): string
    {
        try {
            $npub = $keys->convertPublicKeyToBech32($pubkeyHex);
        } catch (\Throwable) {
            $npub = null;
        }
        if (!\is_string($npub) || $npub === '') {
            return 'author'.substr($pubkeyHex, 0, 8);
        }
        $name = '';
        try {
            $c = $this->cacheService->getMetadata($npub);
            $name = (string) ($c->display_name ?? $c->name ?? '');
        } catch (\Throwable) {
        }
        $base = $this->nip05LocalPartFromLabel($name);
        if ($base === '') {
            $base = 'author'.substr($pubkeyHex, 0, 8);
        }

        return $base;
    }

    /**
     * NIP-05: local-part uses only a–z, 0–9, -, _, .
     */
    private function nip05LocalPartFromLabel(string $raw): string
    {
        $s = strtolower(trim($raw));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (\is_string($t) && $t !== '') {
            $s = strtolower($t);
        }
        $s = preg_replace('/[^a-z0-9._-]+/', '', $s) ?? '';
        $s = trim((string) $s, '._-');
        if (\strlen($s) > 40) {
            $s = substr($s, 0, 40);
        }
        $s = trim($s, '._-');

        return $s;
    }

    private function allocateUniqueLocalPart(string $base): string
    {
        if ($base === '') {
            $base = 'author';
        }
        if (!$this->featuredAuthorRepository->isLocalPartTaken($base)) {
            return $base;
        }
        for ($i = 1; $i < 10_000; ++$i) {
            $c = $base.$i;
            if (!$this->featuredAuthorRepository->isLocalPartTaken($c)) {
                return $c;
            }
        }

        return $base.bin2hex(random_bytes(3));
    }
}
