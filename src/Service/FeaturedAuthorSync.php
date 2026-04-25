<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FeaturedAuthor;
use App\Repository\FeaturedAuthorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;

/**
 * Reconciles {@see FeaturedAuthor} rows with pubkeys found in magazine category `a` tags.
 * The listed set is derived from current category indices during prewarm.
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
     * @return array{added: int, relisted: int, unlisted: int, listed_total: int}
     */
    public function reconcileListedAuthorsFromMagazineCategories(): array
    {
        $pubkeys = $this->magazineContent->getAllDistinctCategoryAuthorPubkeyHexes();
        $target = [];
        foreach ($pubkeys as $hex) {
            $h = strtolower(trim($hex));
            if (64 === \strlen($h) && ctype_xdigit($h)) {
                $target[$h] = true;
            }
        }

        $existingByPubkey = [];
        foreach ($this->featuredAuthorRepository->findAll() as $row) {
            $existingByPubkey[strtolower($row->getPubkeyHex())] = $row;
        }
        $keys = new Key();
        $added = 0;
        $relisted = 0;
        $unlisted = 0;
        $changed = false;

        foreach (array_keys($target) as $hex) {
            $row = $existingByPubkey[$hex] ?? null;
            if ($row === null) {
                $entity = new FeaturedAuthor();
                $entity->setPubkeyHex($hex);
                $base = $this->deriveBaseLocalPart($keys, $hex);
                $entity->setLocalPart($this->allocateUniqueLocalPart($base));
                $entity->setIsListed(true);
                $this->entityManager->persist($entity);
                $existingByPubkey[$hex] = $entity;
                $added++;
                $changed = true;
                continue;
            }
            if (!$row->isListed()) {
                $row->setIsListed(true);
                $relisted++;
                $changed = true;
            }
        }

        foreach ($existingByPubkey as $hex => $row) {
            if (isset($target[$hex])) {
                continue;
            }
            if ($row->isListed()) {
                $row->setIsListed(false);
                $unlisted++;
                $changed = true;
            }
        }

        if ($changed) {
            $this->entityManager->flush();
            $this->logger->info('featured_author.sync', [
                'added' => $added,
                'relisted' => $relisted,
                'unlisted' => $unlisted,
                'listed_total' => \count($target),
            ]);
        }

        return [
            'added' => $added,
            'relisted' => $relisted,
            'unlisted' => $unlisted,
            'listed_total' => \count($target),
        ];
    }

    /**
     * @deprecated use {@see reconcileListedAuthorsFromMagazineCategories}
     */
    public function syncNewAuthorsFromMagazineCategories(): int
    {
        $st = $this->reconcileListedAuthorsFromMagazineCategories();

        return $st['added'];
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
