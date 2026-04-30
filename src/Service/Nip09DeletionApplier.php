<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event as MagazineNostrEvent;
use App\Enum\KindsEnum;
use App\Nostr\MagazineEventKeys;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Applies NIP-09 (kind 5) deletion requests to:
 * - MySQL: long-form articles ({@see KindsEnum::LONGFORM} 30023, {@see KindsEnum::LONGFORM_DRAFT} 30024)
 * - MySQL {@see Event} rows: kind 30040 magazine indices (root + category), kind 30004 home curation set,
 *   kind 0 profile, 10002 relay list, 10133 payto
 *
 * Handled for `e` tags (with `k` when present) and for NIP-33 `a` tags.
 *
 * Relays are not authoritative; we only remove data we can validate (same pubkey as deletion request).
 * For category 30040 rows (keyed by `d` only), we require the stored event’s author to match the
 * deletion author so colliding `d` values across authors cannot wipe another author’s index.
 */
final class Nip09DeletionApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ArticleRepository $articleRepository,
        private readonly MagazineIndexStore $magazineIndexStore,
        private readonly EventRepository $eventRepository,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    /**
     * @param list<object> $deletionEvents Kind-5 events from relays (e.g. {@see NostrClient::fetchKind5DeletionEventsForAuthors})
     *
     * @return array{articles_removed: int, magazine_roots: int, magazine_categories: int, magazine_curation_30004: int}
     */
    public function apply(array $deletionEvents): array
    {
        $articlesRemoved = 0;
        $articlesPendingFlush = 0;
        $roots = 0;
        $cats = 0;
        $curation30004 = 0;
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
                        KindsEnum::METADATA->value,
                        KindsEnum::RELAY_LIST->value,
                        KindsEnum::PAYMENT_TARGETS->value,
                        KindsEnum::CURATION_SET->value,
                    ], true)) {
                    continue;
                }
                if ($this->removeArticleByEventIdIfValid($eId, $deletionPubkey, $declared, $seenArticleIds)) {
                    ++$articlesRemoved;
                    ++$articlesPendingFlush;
                    continue;
                }
                if ($this->tryRemoveCoreEventRowByEventId($eId, $deletionPubkey, $declared)) {
                    continue;
                }
                if ($declared === null || \in_array($declared, [
                    KindsEnum::LONGFORM->value,
                    KindsEnum::LONGFORM_DRAFT->value,
                    KindsEnum::PUBLICATION_INDEX->value,
                    KindsEnum::CURATION_SET->value,
                ], true)) {
                    $mag = $this->tryRemoveMagazine30040ByEventId($eId, $deletionPubkey);
                    if ($mag === 1) {
                        ++$roots;
                    } elseif ($mag === 2) {
                        ++$cats;
                    } elseif ($this->tryRemoveStoredCuration30004ByEventId($eId, $deletionPubkey)) {
                        ++$curation30004;
                    }
                }
            }

            foreach ($aAddrs as $addr) {
                $r = $this->removeByNip33Address($addr, $deletionPubkey, $seenArticleIds);
                $articlesRemoved += $r['articles'];
                $articlesPendingFlush += $r['articles'];
                $roots += $r['roots'];
                $cats += $r['cats'];
                $curation30004 += $r['curation'];
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Nip09DeletionApplier: flush failed', ['exception' => $e]);
        }

        return [
            'articles_removed' => $articlesRemoved,
            'magazine_roots' => $roots,
            'magazine_categories' => $cats,
            'magazine_curation_30004' => $curation30004,
        ];
    }

    /**
     * Kind 0 / 10002 / 10133 rows in {@see Event} (profile, relay list, payto), by Nostr event id.
     */
    private function tryRemoveCoreEventRowByEventId(string $eventId, string $deletionPubkey, ?int $declared): bool
    {
        $eid = strtolower($eventId);
        $e = $this->eventRepository->find($eid);
        if ($e === null) {
            return false;
        }
        if (!$this->pubkeyEquals($e->getPubkey(), $deletionPubkey)) {
            return false;
        }
        $k = (int) $e->getKind();
        if ($declared !== null && $declared !== $k) {
            return false;
        }
        if (!\in_array($k, [
            KindsEnum::METADATA->value,
            KindsEnum::RELAY_LIST->value,
            KindsEnum::PAYMENT_TARGETS->value,
        ], true)) {
            return false;
        }
        if ($k === KindsEnum::METADATA->value) {
            if ($e->getStorageRole() !== null && $e->getStorageRole() !== MagazineNostrEvent::STORAGE_PROFILE_KIND0) {
                return false;
            }
        } elseif ($k === KindsEnum::RELAY_LIST->value) {
            if ($e->getStorageRole() !== null && $e->getStorageRole() !== MagazineNostrEvent::STORAGE_RELAY_LIST_10002) {
                return false;
            }
        } elseif ($k === KindsEnum::PAYMENT_TARGETS->value) {
            if ($e->getStorageRole() !== null && $e->getStorageRole() !== MagazineNostrEvent::STORAGE_PAYTO_10133) {
                return false;
            }
        }
        $this->entityManager->remove($e);
        $this->logger->notice('NIP-09: removed core event row', [
            'event_id' => $eid,
            'kind' => $k,
        ]);

        return true;
    }

    /** 0 = none, 1 = root row, 2 = category row */
    private function tryRemoveMagazine30040ByEventId(string $eventId, string $deletionPubkey): int
    {
        $eid = strtolower($eventId);
        $e = $this->eventRepository->find($eid);
        if ($e === null) {
            return 0;
        }
        if ((int) $e->getKind() !== KindsEnum::PUBLICATION_INDEX->value) {
            return 0;
        }
        if (!$this->pubkeyEquals($e->getPubkey(), $deletionPubkey)) {
            return 0;
        }
        if ($e->getStorageRole() === MagazineNostrEvent::STORAGE_MAGAZINE_ROOT) {
            $this->entityManager->remove($e);
            $this->logger->notice('NIP-09: removed magazine root index (event table)', [
                'event_id' => $eid,
            ]);

            return 1;
        }
        if ($e->getStorageRole() === MagazineNostrEvent::STORAGE_MAGAZINE_CATEGORY) {
            $this->entityManager->remove($e);
            $this->logger->notice('NIP-09: removed magazine category index (event table)', [
                'event_id' => $eid,
            ]);

            return 2;
        }

        return 0;
    }

    private function tryRemoveStoredCuration30004ByEventId(string $eventId, string $deletionPubkey): bool
    {
        $eid = strtolower($eventId);
        $e = $this->eventRepository->find($eid);
        if ($e === null) {
            return false;
        }
        if ((int) $e->getKind() !== KindsEnum::CURATION_SET->value) {
            return false;
        }
        if (!$this->pubkeyEquals($e->getPubkey(), $deletionPubkey)) {
            return false;
        }
        if ($e->getStorageRole() !== MagazineNostrEvent::STORAGE_MAGAZINE_CURATION_30004) {
            return false;
        }
        $this->entityManager->remove($e);
        $this->logger->notice('NIP-09: removed home curation 30004 row (event table)', [
            'event_id' => $eid,
        ]);

        return true;
    }

    private function pubkeyEquals(string $a, string $b): bool
    {
        if (64 !== \strlen($a) || 64 !== \strlen($b)) {
            return $a === $b;
        }

        return strtolower($a) === strtolower($b);
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
     * @return array{articles: int, roots: int, cats: int, curation: int}
     */
    private function removeByNip33Address(string $addr, string $deletionPubkey, array &$seenArticleIds): array
    {
        $out = ['articles' => 0, 'roots' => 0, 'cats' => 0, 'curation' => 0];
        $parts = explode(':', $addr, 3);
        if (\count($parts) < 3) {
            return $out;
        }
        $kind = (int) $parts[0];
        $pk = (string) $parts[1];
        $d = trim((string) $parts[2]);
        if (!$this->pubkeyEquals($pk, $deletionPubkey)) {
            return $out;
        }

        if ($kind === KindsEnum::METADATA->value) {
            if ($d !== '' && $d !== '0') {
                return $out;
            }
            $row = $this->eventRepository->findOneByCoreRowKey(MagazineEventKeys::profileKind0(strtolower($pk)));
            if ($row !== null && (int) $row->getKind() === KindsEnum::METADATA->value) {
                $this->entityManager->remove($row);
                $this->logger->notice('NIP-09: removed profile row (a tag)', ['address' => $addr]);
            }

            return $out;
        }

        if ($kind === KindsEnum::RELAY_LIST->value) {
            $row = $this->eventRepository->findOneByCoreRowKey(MagazineEventKeys::relayList10002(strtolower($pk)));
            if ($row !== null && (int) $row->getKind() === KindsEnum::RELAY_LIST->value) {
                $this->entityManager->remove($row);
                $this->logger->notice('NIP-09: removed relay list row (a tag)', ['address' => $addr]);
            }

            return $out;
        }

        if ($kind === KindsEnum::PAYMENT_TARGETS->value) {
            if ($d === '') {
                return $out;
            }
            $row = $this->eventRepository->findOneByCoreRowKey(MagazineEventKeys::payto10133(strtolower($pk), $d));
            if ($row !== null && (int) $row->getKind() === KindsEnum::PAYMENT_TARGETS->value) {
                $this->entityManager->remove($row);
                $this->logger->notice('NIP-09: removed payto 10133 row (a tag)', ['address' => $addr]);
            }

            return $out;
        }

        if ($kind === KindsEnum::LONGFORM->value || $kind === KindsEnum::LONGFORM_DRAFT->value) {
            if ($d === '') {
                return $out;
            }
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
                    $h = $this->nostrKeyHelper->convertToHex($npub);
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

        if ($kind === KindsEnum::CURATION_SET->value) {
            if ($d === '') {
                return $out;
            }
            $key = MagazineEventKeys::magazineCuration30004FromPubkeyHex($pk, $d);
            if ($key === '') {
                return $out;
            }
            $row = $this->eventRepository->findOneByCoreRowKey($key);
            if ($row !== null
                && (int) $row->getKind() === KindsEnum::CURATION_SET->value
                && $row->getStorageRole() === MagazineNostrEvent::STORAGE_MAGAZINE_CURATION_30004
                && $this->pubkeyEquals($row->getPubkey(), $deletionPubkey)) {
                $this->entityManager->remove($row);
                ++$out['curation'];
                $this->logger->notice('NIP-09: removed home curation 30004 (a tag)', ['address' => $addr]);
            }

            return $out;
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
