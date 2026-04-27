<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Nostr\MagazineEventKeys;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

readonly class CacheService implements HighlightAuthorMetadataProvider
{
    public function __construct(
        private NostrClient $nostrClient,
        private EntityManagerInterface $entityManager,
        private EventRepository $eventRepository,
        private LoggerInterface $logger,
        private NostrKeyHelper $nostrKeyHelper,
        private NostrNip65RelayUrls $nip65RelayUrls,
    ) {
    }

    public function getMetadata(string $npub): \stdClass
    {
        return $this->getMetadataBundle($npub)['content'];
    }

    /**
     * @return array{content: \stdClass, kind0_tags: list<list<string>>}
     */
    public function getMetadataBundle(string $npub): array
    {
        $authorHex = $this->npubToAuthorHex64($npub);
        if ($authorHex === null) {
            return $this->placeholderMetadataBundle($npub);
        }
        $row = $this->eventRepository->findOneByCoreRowKey(MagazineEventKeys::profileKind0($authorHex));
        if ($row !== null) {
            return $this->bundleFromKind0EventRow($row, $npub);
        }
        try {
            $ev = $this->nostrClient->getNpubMetadata($npub);
            if (!\is_object($ev)) {
                return $this->placeholderMetadataBundle($npub);
            }
            $this->replaceByCoreKey(
                MagazineEventKeys::profileKind0($authorHex),
                Event::STORAGE_PROFILE_KIND0,
                $ev
            );
            $tags = self::normalizeEventTagsList($ev->tags ?? null);
            $content = $this->decodeKind0ContentObject($ev);
            if ($this->isPlaceholderContent($content, $npub)) {
                $content = $this->namePlaceholderNpubObject($npub);
            }

            return ['content' => $content, 'kind0_tags' => $tags];
        } catch (\Exception $e) {
            $this->logger->warning('Profile metadata fetch failed; using npub placeholder.', [
                'npub' => $npub,
                'exception' => $e->getPrevious() ?? $e,
            ]);
        }

        return $this->placeholderMetadataBundle($npub);
    }

    /**
     * Prewarm: batch upsert of kind-0 profile rows in {@see Event}.
     *
     * @param list<string>            $authorPubkeyHex
     * @param array<string, object>  $wireByLowerHex from {@see NostrClient::fetchKind0WireEventsForAuthors} (keys are lowercase 64-hex)
     */
    public function putPrewarmMetadataBatch(array $authorPubkeyHex, array $wireByLowerHex): int
    {
        $n = 0;
        foreach ($authorPubkeyHex as $hex) {
            if (64 !== \strlen($hex) || !ctype_xdigit($hex)) {
                continue;
            }
            $h = strtolower($hex);
            if (!isset($wireByLowerHex[$h]) || !\is_object($wireByLowerHex[$h])) {
                continue;
            }
            $this->replaceByCoreKey(
                MagazineEventKeys::profileKind0($h),
                Event::STORAGE_PROFILE_KIND0,
                $wireByLowerHex[$h]
            );
            ++$n;
        }

        return $n;
    }

    public function getRelays($npub)
    {
        $authorHex = $this->npubToAuthorHex64($npub);
        if ($authorHex === null) {
            return [];
        }
        $key = MagazineEventKeys::relayList10002($authorHex);
        $row = $this->eventRepository->findOneByCoreRowKey($key);
        if ($row !== null) {
            return self::relayWssListFromNip65Tags($row->getTags());
        }
        $wire = $this->nostrClient->getNpubRelayList10002Wire($npub);
        if ($wire === null) {
            return [];
        }
        $this->replaceByCoreKey($key, Event::STORAGE_RELAY_LIST_10002, $wire);

        return $this->nip65RelayUrls->wssListFromKind10002Wire($wire);
    }

    /**
     * @return list<list<string>>
     */
    private static function normalizeEventTagsList(mixed $tags): array
    {
        if (!\is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $row) {
            if (!\is_array($row) && !\is_object($row)) {
                continue;
            }
            $seq = \is_object($row) ? get_object_vars($row) : $row;
            if ($seq === []) {
                continue;
            }
            $r = array_values(
                array_map(
                    static fn (mixed $v): string => (string) $v,
                    array_values($seq)
                )
            );
            if ($r !== [] && (string) ($r[0] ?? '') !== '') {
                $out[] = $r;
            }
        }

        return $out;
    }

    private function npubToAuthorHex64(string $npub): ?string
    {
        if (64 === \strlen($npub) && ctype_xdigit($npub)) {
            return strtolower($npub);
        }
        if (str_starts_with($npub, 'npub1')) {
            try {
                $h = $this->nostrKeyHelper->convertToHex($npub);
            } catch (\Throwable) {
                $h = '';
            }
            if (64 === \strlen((string) $h) && ctype_xdigit((string) $h)) {
                return strtolower($h);
            }
        }

        return null;
    }

    private function replaceByCoreKey(string $coreKey, string $storageRole, object $rawWire): void
    {
        $entity = $this->wireToEventEntity($rawWire);
        if ($entity === null) {
            return;
        }
        $entity->setCoreRowKey($coreKey);
        $entity->setStorageRole($storageRole);
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

    private function bundleFromKind0EventRow(Event $row, string $npub): array
    {
        $content = $this->decodeKind0ContentString($row->getContent());
        if (!\is_object($content) || $this->isPlaceholderContent($content, $npub)) {
            $content = $this->namePlaceholderNpubObject($npub);
        }

        return [
            'content' => $content,
            'kind0_tags' => self::normalizeEventTagsList($row->getTags()),
        ];
    }

    private function decodeKind0ContentObject(object $ev): \stdClass
    {
        return $this->decodeKind0ContentString((string) ($ev->content ?? ''));
    }

    private function decodeKind0ContentString(string $raw): \stdClass
    {
        try {
            $data = \json_decode($raw, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new \stdClass();
        }
        if (!\is_object($data)) {
            return new \stdClass();
        }

        return $data;
    }

    private function isPlaceholderContent(\stdClass $content, string $npub): bool
    {
        $n = (string) ($content->name ?? '');

        return $n === substr($npub, 0, 8).'…'.substr($npub, -4);
    }

    private function namePlaceholderNpubObject(string $npub): \stdClass
    {
        $c = new \stdClass();
        $c->name = substr($npub, 0, 8).'…'.substr($npub, -4);

        return $c;
    }

    private function placeholderMetadataBundle(string $npub): array
    {
        return [
            'content' => $this->namePlaceholderNpubObject($npub),
            'kind0_tags' => [],
        ];
    }

    /**
     * @param list<list<string>>|array $tags
     * @return list<string>
     */
    private static function relayWssListFromNip65Tags(array $tags): array
    {
        $relays = [];
        foreach ($tags as $tag) {
            if (!\is_array($tag) || !isset($tag[0], $tag[1])) {
                continue;
            }
            if ((string) $tag[0] === 'r') {
                $relays[] = (string) $tag[1];
            }
        }

        return array_filter(array_unique($relays), static function (string $relay) {
            return str_starts_with($relay, 'wss:') && !str_contains($relay, 'localhost');
        });
    }
}
