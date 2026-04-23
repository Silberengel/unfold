<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event as MagazineNostrEvent;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Applies NIP-09 (kind 5) deletion requests to:
 * - MySQL: long-form articles ({@see KindsEnum::LONGFORM} 30023, {@see KindsEnum::LONGFORM_DRAFT} 30024)
 * - Magazine cache: publication indices ({@see KindsEnum::PUBLICATION_INDEX} 30040) in {@see MagazineIndexStore}
 *
 * Both are handled for `e` tags (with `k` when present) and for NIP-33 `a` tags.
 *
 * Relays are not authoritative; we only remove data we can validate (same pubkey as deletion request).
 * For cached 30040 category indices (keyed by `d` only), we require the stored event’s author
 * to match the deletion — not just an `a` tag whose own pubkey matches, so colliding `d` values
 * across authors cannot wipe another author’s cache entry.
 */
final class Nip09DeletionApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ArticleRepository $articleRepository,
        private readonly MagazineIndexStore $magazineIndexStore,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<object> $deletionEvents Kind-5 events from relays (e.g. {@see NostrClient::fetchKind5DeletionEventsForAuthors})
     *
     * @return array{articles_removed: int, magazine_roots: int, magazine_categories: int}
     */
    public function apply(array $deletionEvents): array
    {
        $articlesRemoved = 0;
        $articlesPendingFlush = 0;
        $roots = 0;
        $cats = 0;
        $seenArticleIds = [];

        foreach ($deletionEvents as $ev) {
            if (!\is_object($ev)) {
                continue;
            }
            if ((int) ($ev->kind ?? 0) !== KindsEnum::DELETION_REQUEST->value) {
                continue;
            }
            $deletionPubkey = (string) ($ev->pubkey ?? '');
            if (64 !== \strlen($deletionPubkey)) {
                continue;
            }

            [$eIds, $eKinds] = $this->parseETags($ev);
            $aAddrs = $this->parseATags($ev);

            foreach ($eIds as $i => $eId) {
                if (64 !== \strlen($eId)) {
                    continue;
                }
                $declared = $eKinds[$i] ?? null;
                if ($declared !== null
                    && !\in_array($declared, [
                        KindsEnum::LONGFORM->value,
                        KindsEnum::LONGFORM_DRAFT->value,
                        KindsEnum::PUBLICATION_INDEX->value,
                        1, // NIP-09 may include kind 1; we do not store notes, but must not treat k as “unknown”
                    ], true)) {
                    // Other kinds: we do not mirror in this app; skip.
                    continue;
                }
                if ($declared === 1) {
                    continue;
                }
                if ($this->removeArticleByEventIdIfValid($eId, $deletionPubkey, $declared, $seenArticleIds)) {
                    ++$articlesRemoved;
                    ++$articlesPendingFlush;
                    continue;
                }
                // No DB row: try kind 30040 magazine index by event id; also 30023/24 if not mirrored in DB.
                if ($declared === null || \in_array($declared, [
                    KindsEnum::LONGFORM->value,
                    KindsEnum::LONGFORM_DRAFT->value,
                    KindsEnum::PUBLICATION_INDEX->value,
                ], true)) {
                    $mag = $this->tryRemoveMagazine30040ByEventId($eId, $deletionPubkey);
                    if ($mag === 1) {
                        ++$roots;
                    } elseif ($mag === 2) {
                        ++$cats;
                    }
                }
            }

            foreach ($aAddrs as $addr) {
                $r = $this->removeByNip33Address($addr, $deletionPubkey, $seenArticleIds);
                $articlesRemoved += $r['articles'];
                $articlesPendingFlush += $r['articles'];
                $roots += $r['roots'];
                $cats += $r['cats'];
            }
        }

        if ($articlesPendingFlush > 0) {
            try {
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                $this->logger->error('Nip09DeletionApplier: flush failed', ['exception' => $e]);
            }
        }

        return [
            'articles_removed' => $articlesRemoved,
            'magazine_roots' => $roots,
            'magazine_categories' => $cats,
        ];
    }

    /** 0 = none, 1 = root cache, 2 = category cache */
    private function tryRemoveMagazine30040ByEventId(string $eventId, string $deletionPubkey): int
    {
        $npub = (string) $this->params->get('npub');
        $dTag = (string) $this->params->get('d_tag');
        if ($npub === '' || $dTag === '') {
            return 0;
        }
        $root = $this->magazineIndexStore->getRoot($npub, $dTag);
        if ($root === null) {
            return 0;
        }
        if ($this->eventIdMatches($root, $eventId) && $this->pubkeyEquals($root->getPubkey(), $deletionPubkey)) {
            $this->magazineIndexStore->deleteRoot($npub, $dTag);
            $this->logger->notice('NIP-09: removed cached magazine root index', [
                'event_id' => $eventId,
            ]);

            return 1;
        }
        foreach ($this->categorySlugsFromRoot($root) as $slug) {
            $cat = $this->magazineIndexStore->getCategory($slug);
            if ($cat === null) {
                continue;
            }
            if ($this->eventIdMatches($cat, $eventId) && $this->pubkeyEquals($cat->getPubkey(), $deletionPubkey)) {
                $this->magazineIndexStore->deleteCategory($slug);
                $this->logger->notice('NIP-09: removed cached magazine category index', [
                    'event_id' => $eventId,
                    'slug' => $slug,
                ]);

                return 2;
            }
        }

        return 0;
    }

    private function eventIdMatches(MagazineNostrEvent $e, string $eventId): bool
    {
        $a = strtolower($e->getId());
        $b = strtolower($eventId);

        return $a === $b;
    }

    private function pubkeyEquals(string $a, string $b): bool
    {
        if (64 !== \strlen($a) || 64 !== \strlen($b)) {
            return $a === $b;
        }

        return strtolower($a) === strtolower($b);
    }

    /**
     * @return list<string>
     */
    private function categorySlugsFromRoot(MagazineNostrEvent $root): array
    {
        $slugs = [];
        foreach ($root->getTags() as $tag) {
            if (($tag[0] ?? null) !== 'a' || !isset($tag[1])) {
                continue;
            }
            $parts = explode(':', (string) $tag[1], 3);
            if (\count($parts) < 3) {
                continue;
            }
            $s = trim((string) end($parts));
            if ($s !== '' && !\in_array($s, $slugs, true)) {
                $slugs[] = $s;
            }
        }

        return $slugs;
    }

    /**
     * @param array<string, true> $seenArticleIds
     */
    private function removeArticleByEventIdIfValid(
        string $eId,
        string $deletionPubkey,
        ?int $declaredKind,
        array &$seenArticleIds,
    ): bool {
        if (isset($seenArticleIds[$eId])) {
            return false;
        }
        $article = $this->articleRepository->findOneByEventId($eId);
        if ($article === null) {
            return false;
        }
        if (!$this->pubkeyEquals($article->getPubkey() ?? '', $deletionPubkey)) {
            $this->logger->debug('NIP-09: ignore e tag (pubkey mismatch)', [
                'event_id' => $eId,
            ]);

            return false;
        }
        $k = $article->getKind()?->value;
        if ($declaredKind !== null && $k !== null && $declaredKind !== $k) {
            return false;
        }
        if ($k !== null && !\in_array($k, [KindsEnum::LONGFORM->value, KindsEnum::LONGFORM_DRAFT->value], true)) {
            return false;
        }
        $this->entityManager->remove($article);
        $seenArticleIds[$eId] = true;
        $this->logger->notice('NIP-09: removed article from database', [
            'event_id' => $eId,
            'kind' => $k,
        ]);

        return true;
    }

    /**
     * NIP-33: `kind:pubkeyhex:d-identifier`
     *
     * @param array<string, true> $seenArticleIds
     *
     * @return array{articles: int, roots: int, cats: int}
     */
    private function removeByNip33Address(string $addr, string $deletionPubkey, array &$seenArticleIds): array
    {
        $out = ['articles' => 0, 'roots' => 0, 'cats' => 0];
        $parts = explode(':', $addr, 3);
        if (\count($parts) < 3) {
            return $out;
        }
        $kind = (int) $parts[0];
        $pk = (string) $parts[1];
        $d = trim((string) $parts[2]);
        if ($d === '' || !$this->pubkeyEquals($pk, $deletionPubkey)) {
            return $out;
        }

        if ($kind === KindsEnum::LONGFORM->value || $kind === KindsEnum::LONGFORM_DRAFT->value) {
            $article = $this->articleRepository->findOneBy(['pubkey' => $pk, 'slug' => $d]);
            if ($article !== null) {
                $eid = (string) ($article->getEventId() ?? '');
                $dedupeKey = $eid !== '' ? $eid : 'ps:'.$pk."\0".$d;
                if (!isset($seenArticleIds[$dedupeKey])) {
                    $this->entityManager->remove($article);
                    $seenArticleIds[$dedupeKey] = true;
                    ++$out['articles'];
                    $this->logger->notice('NIP-09: removed article (a tag)', [
                        'address' => $addr,
                    ]);
                }
            }

            return $out;
        }

        if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
            $npub = (string) $this->params->get('npub');
            $siteD = (string) $this->params->get('d_tag');
            $siteHex = '';
            if (str_starts_with($npub, 'npub1')) {
                try {
                    $h = (new Key())->convertToHex($npub);
                    if (64 === \strlen($h)) {
                        $siteHex = $h;
                    }
                } catch (\Throwable) {
                }
            }
            if ($npub !== '' && $siteD !== '' && $d === $siteD && $siteHex !== '' && $this->pubkeyEquals($pk, $siteHex)) {
                $this->magazineIndexStore->deleteRoot($npub, $siteD);
                ++$out['roots'];
                $this->logger->notice('NIP-09: removed magazine root (a tag)', ['address' => $addr]);
            } else {
                // Category cache is keyed by `d` only; the same d string can appear for different
                // authors' 30040 events. Only remove if the cached event was authored by this deletion.
                $cachedCat = $this->magazineIndexStore->getCategory($d);
                if ($cachedCat === null) {
                    $this->logger->debug('NIP-09: skip category delete (nothing cached for d)', [
                        'address' => $addr,
                        'd' => $d,
                    ]);
                } elseif (!$this->pubkeyEquals($cachedCat->getPubkey(), $deletionPubkey)) {
                    $this->logger->debug('NIP-09: skip category delete (cached index author != deletion author)', [
                        'address' => $addr,
                        'd' => $d,
                    ]);
                } else {
                    $this->magazineIndexStore->deleteCategory($d);
                    ++$out['cats'];
                    $this->logger->notice('NIP-09: removed magazine category (a tag)', [
                        'address' => $addr,
                        'd' => $d,
                    ]);
                }
            }
        }

        return $out;
    }

    /**
     * @return array{0: list<string>, 1: list<?int>} e-ids and parallel k kinds (NIP-09 example order)
     */
    private function parseETags(object $ev): array
    {
        $eIds = [];
        $kinds = [];
        foreach ($ev->tags ?? [] as $tag) {
            if (!\is_array($tag) || !isset($tag[0], $tag[1])) {
                continue;
            }
            if ($tag[0] === 'e') {
                $eIds[] = (string) $tag[1];
            }
            if ($tag[0] === 'k') {
                $kinds[] = (int) $tag[1];
            }
        }
        $pairs = [];
        for ($i = 0; $i < \count($eIds); ++$i) {
            $pairs[] = $kinds[$i] ?? null;
        }

        return [$eIds, $pairs];
    }

    /**
     * @return list<string> NIP-33 addresses
     */
    private function parseATags(object $ev): array
    {
        $a = [];
        foreach ($ev->tags ?? [] as $tag) {
            if (!\is_array($tag) || ($tag[0] ?? null) !== 'a' || !isset($tag[1])) {
                continue;
            }
            $a[] = (string) $tag[1];
        }

        return $a;
    }
}
