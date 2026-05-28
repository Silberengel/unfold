<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Upserts {@see Event} rows keyed by {@see Event::$coreRowKey} from Nostr wire payloads.
 */
final class NostrCoreEventWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $eventRepository,
        private readonly Nip30EmojiCatalogBuilder $nip30EmojiCatalogBuilder,
    ) {
    }

    /**
     * @param list<array{shortcode: string, url: string, set?: string}>|null $nip30Catalog profile rows only
     */
    public function upsertFromWire(string $coreKey, string $storageRole, object $rawWire, ?array $nip30Catalog = null): void
    {
        if ($coreKey === '') {
            return;
        }
        $entity = $this->wireToEventEntity($rawWire);
        if ($entity === null) {
            return;
        }
        $entity->setCoreRowKey($coreKey);
        $entity->setStorageRole($storageRole);
        if ($storageRole === Event::STORAGE_PROFILE_KIND0) {
            $entity->setNip30CustomEmoji($nip30Catalog ?? $this->nip30EmojiCatalogBuilder->buildMergedCatalog($rawWire, null, []));
        } else {
            $entity->setNip30CustomEmoji(null);
        }
        if ($entity->getEventId() === null) {
            $entity->setEventId($entity->getId());
        }
        $prev = $this->eventRepository->findOneByCoreRowKey($coreKey);
        if ($prev !== null && $prev->getId() === $entity->getId()) {
            $prev->setKind($entity->getKind());
            $prev->setPubkey($entity->getPubkey());
            $prev->setContent($entity->getContent());
            $prev->setCreatedAt($entity->getCreatedAt());
            $prev->setTags($entity->getTags());
            $prev->setSig($entity->getSig());
            $prev->setCoreRowKey($coreKey);
            $prev->setStorageRole($storageRole);
            if ($storageRole === Event::STORAGE_PROFILE_KIND0) {
                $prev->setNip30CustomEmoji($entity->getNip30CustomEmoji());
            } else {
                $prev->setNip30CustomEmoji(null);
            }
            if ($entity->getEventId() !== null) {
                $prev->setEventId($entity->getEventId());
            }
            $this->entityManager->flush();

            return;
        }
        if ($prev !== null) {
            $this->entityManager->remove($prev);
            $this->entityManager->flush();
        }
        $existingByPk = $this->eventRepository->find($entity->getId());
        if ($existingByPk !== null && $existingByPk->getCoreRowKey() !== $coreKey) {
            return;
        }
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    private function wireToEventEntity(object $raw): ?Event
    {
        try {
            $data = json_decode(json_encode($raw, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data)) {
            return null;
        }
        $id = (string) ($data['id'] ?? '');
        if (64 !== \strlen($id) || !ctype_xdigit($id)) {
            return null;
        }
        $e = new Event();
        $e->setId(strtolower($id));
        $e->setEventId(strtolower($id));
        $e->setKind((int) ($data['kind'] ?? 0));
        $e->setPubkey(strtolower((string) ($data['pubkey'] ?? '')));
        $e->setContent((string) ($data['content'] ?? ''));
        $e->setCreatedAt((int) ($data['created_at'] ?? 0));
        $tags = $data['tags'] ?? [];
        $e->setTags(\is_array($tags) ? $tags : []);
        $e->setSig((string) ($data['sig'] ?? ''));

        return $e;
    }
}
