<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Nostr\MagazineEventKeys;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Community NKBIP-01 publication kind-30040 indices ({@see Event::STORAGE_PUBLICATION_INDEX}), not the site magazine tree.
 */
final class PublicationIndexStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $eventRepository,
        private readonly TenantContext $tenant,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function getByNpubAndD(string $npub, string $dTag): ?Event
    {
        $key = MagazineEventKeys::publicationIndexFromNpub($this->tenant->getMagazineSlug(), $npub, $dTag);
        if ($key === '') {
            return null;
        }

        return $this->eventRepository->findOneByCoreRowKey($key);
    }

    public function getByPubkeyHexAndD(string $pubkeyHex, string $dTag): ?Event
    {
        $key = MagazineEventKeys::publicationIndex($this->tenant->getMagazineSlug(), $pubkeyHex, $dTag);
        if ($key === '') {
            return null;
        }

        return $this->eventRepository->findOneByCoreRowKey($key);
    }

    public function put(string $npubOrHex, string $dTag, Event $event): void
    {
        $dTag = trim($dTag);
        if ($dTag === '') {
            return;
        }
        $key = str_starts_with($npubOrHex, 'npub')
            ? MagazineEventKeys::publicationIndexFromNpub($this->tenant->getMagazineSlug(), $npubOrHex, $dTag)
            : MagazineEventKeys::publicationIndex($this->tenant->getMagazineSlug(), $npubOrHex, $dTag);
        if ($key === '') {
            return;
        }
        $this->replaceByCoreKey($key, $event);
    }

    public function putFromWire(string $npubOrHex, string $dTag, Event $event): void
    {
        $this->put($npubOrHex, $dTag, $event);
    }

    public function deleteByPubkeyHexAndD(string $pubkeyHex, string $dTag): void
    {
        $key = MagazineEventKeys::publicationIndex($this->tenant->getMagazineSlug(), $pubkeyHex, $dTag);
        if ($key === '') {
            return;
        }
        $e = $this->eventRepository->findOneByCoreRowKey($key);
        if ($e === null) {
            return;
        }
        $this->entityManager->remove($e);
        $this->entityManager->flush();
    }

    /**
     * @return list<Event> Newest publication indices for this tenant
     */
    public function findNewestPaginated(int $limit, int $offset): array
    {
        $prefix = MagazineEventKeys::tenantPrefix($this->tenant->getMagazineSlug()).'pub:';

        return $this->eventRepository->createQueryBuilder('e')
            ->andWhere('e.storageRole = :role')
            ->andWhere('e.coreRowKey LIKE :pfx')
            ->setParameter('role', Event::STORAGE_PUBLICATION_INDEX)
            ->setParameter('pfx', $prefix.'%')
            ->orderBy('e.created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPublicationIndices(): int
    {
        $prefix = MagazineEventKeys::tenantPrefix($this->tenant->getMagazineSlug()).'pub:';

        return (int) $this->eventRepository->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.storageRole = :role')
            ->andWhere('e.coreRowKey LIKE :pfx')
            ->setParameter('role', Event::STORAGE_PUBLICATION_INDEX)
            ->setParameter('pfx', $prefix.'%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Event>
     */
    public function findAllStoredIndices(): array
    {
        $prefix = MagazineEventKeys::tenantPrefix($this->tenant->getMagazineSlug()).'pub:';

        return $this->eventRepository->createQueryBuilder('e')
            ->andWhere('e.storageRole = :role')
            ->andWhere('e.coreRowKey LIKE :pfx')
            ->setParameter('role', Event::STORAGE_PUBLICATION_INDEX)
            ->setParameter('pfx', $prefix.'%')
            ->getQuery()
            ->getResult();
    }

    private function replaceByCoreKey(string $coreKey, Event $incoming): void
    {
        $prev = $this->eventRepository->findOneByCoreRowKey($coreKey);
        if ($prev !== null) {
            $this->applyIncomingToStoredRow($prev, $incoming, $coreKey);
            $this->entityManager->flush();

            return;
        }

        $incoming->setCoreRowKey($coreKey);
        $incoming->setStorageRole(Event::STORAGE_PUBLICATION_INDEX);
        if ($incoming->getKind() !== KindsEnum::PUBLICATION_INDEX->value) {
            $incoming->setKind(KindsEnum::PUBLICATION_INDEX->value);
        }
        $this->assignStablePrimaryKey($coreKey, $incoming);
        $this->entityManager->persist($incoming);
        $this->entityManager->flush();
    }

    private function applyIncomingToStoredRow(Event $row, Event $incoming, string $coreKey): void
    {
        $nostrHex = $this->extractNostrEventIdHex($incoming);
        $row->setKind($incoming->getKind());
        $row->setPubkey($incoming->getPubkey());
        $row->setContent($incoming->getContent());
        $row->setCreatedAt($incoming->getCreatedAt());
        $row->setTags($incoming->getTags());
        $row->setSig($incoming->getSig());
        $row->setCoreRowKey($coreKey);
        $row->setStorageRole(Event::STORAGE_PUBLICATION_INDEX);
        if ($nostrHex !== '') {
            $row->setEventId($nostrHex);
        }
    }

    /**
     * {@see Event::$id} is the ORM primary key. Magazine rows for the same kind-30040 wire event
     * already use the Nostr event id; publication rows use a deterministic hash of core_row_key instead.
     */
    private function assignStablePrimaryKey(string $coreKey, Event $incoming): void
    {
        $nostrHex = $this->extractNostrEventIdHex($incoming);
        if ($nostrHex === '') {
            $incoming->setId(hash('sha256', $coreKey));

            return;
        }
        $incoming->setEventId($nostrHex);
        $existing = $this->eventRepository->find($nostrHex);
        if ($existing !== null && $existing->getCoreRowKey() !== $coreKey) {
            $incoming->setId(hash('sha256', $coreKey));

            return;
        }
        $incoming->setId($nostrHex);
    }

    private function extractNostrEventIdHex(Event $incoming): string
    {
        $id = strtolower(trim($incoming->getId()));
        if (64 === \strlen($id) && ctype_xdigit($id)) {
            return $id;
        }
        $eventId = $incoming->getEventId();
        if ($eventId === null) {
            return '';
        }
        $eventId = strtolower(trim($eventId));
        if (64 === \strlen($eventId) && ctype_xdigit($eventId)) {
            return $eventId;
        }

        return '';
    }
}
