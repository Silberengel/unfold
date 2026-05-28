<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Nostr\MagazineEventKeys;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Magazine Nostr index events (kind 30040) in MySQL {@see Event}.
 * Updated by {@see MagazineRefresher} (`app:prewarm` / cron).
 */
final class MagazineIndexStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $eventRepository,
        private readonly TenantContext $tenant,
    ) {
    }

    public function getRoot(string $npub, string $dTag): ?Event
    {
        if ($dTag === '') {
            return null;
        }
        $key = MagazineEventKeys::magazineRoot($this->tenant->getMagazineSlug(), $npub, $dTag);
        if ($key === '') {
            return null;
        }

        return $this->eventRepository->findOneByCoreRowKey($key);
    }

    public function getCategory(string $slug): ?Event
    {
        if ($slug === '') {
            return null;
        }
        $key = MagazineEventKeys::magazineCategory($this->tenant->getMagazineSlug(), $slug);

        return $this->eventRepository->findOneByCoreRowKey($key);
    }

    public function putRoot(string $npub, string $dTag, Event $event): void
    {
        if ($dTag === '') {
            return;
        }
        $key = MagazineEventKeys::magazineRoot($this->tenant->getMagazineSlug(), $npub, $dTag);
        if ($key === '') {
            return;
        }
        $this->replaceByCoreKey($key, Event::STORAGE_MAGAZINE_ROOT, $event);
    }

    public function putCategory(string $slug, Event $event): void
    {
        if ($slug === '') {
            return;
        }
        $key = MagazineEventKeys::magazineCategory($this->tenant->getMagazineSlug(), $slug);
        $this->replaceByCoreKey($key, Event::STORAGE_MAGAZINE_CATEGORY, $event);
    }

    public function deleteCategory(string $slug): void
    {
        if ($slug === '') {
            return;
        }
        $key = MagazineEventKeys::magazineCategory($this->tenant->getMagazineSlug(), $slug);
        $this->removeByCoreKey($key);
    }

    public function deleteRoot(string $npub, string $dTag): void
    {
        if ($dTag === '') {
            return;
        }
        $key = MagazineEventKeys::magazineRoot($this->tenant->getMagazineSlug(), $npub, $dTag);
        $this->removeByCoreKey($key);
    }

    private function replaceByCoreKey(string $coreKey, string $role, Event $incoming): void
    {
        $prev = $this->eventRepository->findOneByCoreRowKey($coreKey);
        if ($prev !== null && $prev->getId() === $incoming->getId()) {
            $prev->setKind($incoming->getKind());
            $prev->setPubkey($incoming->getPubkey());
            $prev->setContent($incoming->getContent());
            $prev->setCreatedAt($incoming->getCreatedAt());
            $prev->setTags($incoming->getTags());
            $prev->setSig($incoming->getSig());
            $prev->setCoreRowKey($coreKey);
            $prev->setStorageRole($role);
            if ($incoming->getEventId() !== null) {
                $prev->setEventId($incoming->getEventId());
            }
            $this->entityManager->flush();

            return;
        }
        if ($prev !== null) {
            $this->entityManager->remove($prev);
            $this->entityManager->flush();
        }
        $incoming->setCoreRowKey($coreKey);
        $incoming->setStorageRole($role);
        $this->entityManager->persist($incoming);
        $this->entityManager->flush();
    }

    private function removeByCoreKey(string $coreKey): void
    {
        $e = $this->eventRepository->findOneByCoreRowKey($coreKey);
        if ($e === null) {
            return;
        }
        $this->entityManager->remove($e);
        $this->entityManager->flush();
    }
}
