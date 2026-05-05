<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\KindsEnum;
use App\Util\NostrEventTags;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrWireEvent;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Validates NIP-07–signed kind-30040 magazine batches from the site owner, publishes to article relays,
 * updates {@see MagazineIndexStore}, then ingests listed long-form coordinates into MySQL so the UI
 * can resolve new `a` tags without waiting for cron {@see NostrClient::ingestLongformForCategoryCoordinates}.
 */
final class MagazineHierarchyPublishService
{
    private const STALE_EVENT_MAX_AGE_SEC = 600;

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly MagazineIndexStore $store,
        private readonly NostrWireEventMerge $wireMerge,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, mixed> $rawEvents Decoded JSON event objects
     *
     * @return array{ok: true, published: int, stored: int, longform_ingest_addresses: int}|array{ok: false, error: string, code: int}
     */
    public function publishOwnerMagazineBatch(User $user, array $rawEvents): array
    {
        $npub = (string) $this->params->get('npub');
        $rootD = (string) $this->params->get('d_tag');
        $userNpub = $user->getNpub() ?? '';
        if ($userNpub === '' || !hash_equals($npub, $userNpub)) {
            return ['ok' => false, 'error' => 'Only the configured magazine npub may publish this batch.', 'code' => 403];
        }
        $ownerHex = strtolower($this->nostrKeyHelper->convertToHex($npub));
        if ($ownerHex === '' || 64 !== \strlen($ownerHex) || !ctype_xdigit($ownerHex)) {
            return ['ok' => false, 'error' => 'Invalid owner pubkey configuration.', 'code' => 500];
        }

        if ($rawEvents === []) {
            return ['ok' => false, 'error' => 'No events in batch.', 'code' => 400];
        }
        if (\count($rawEvents) > 200) {
            return ['ok' => false, 'error' => 'Too many events in one batch.', 'code' => 400];
        }

        /** @var array<string, NostrWireEvent> $byD */
        $byD = [];
        foreach ($rawEvents as $i => $raw) {
            if (!\is_array($raw)) {
                return ['ok' => false, 'error' => 'Invalid event at index '.$i.'.', 'code' => 400];
            }
            try {
                $json = json_encode($raw, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            } catch (\JsonException) {
                return ['ok' => false, 'error' => 'Invalid JSON for event at index '.$i.'.', 'code' => 400];
            }
            $wire = NostrWireEvent::fromVerified($json);
            if ($wire === null) {
                return ['ok' => false, 'error' => 'Invalid or unverifiable event at index '.$i.'.', 'code' => 400];
            }
            if ($wire->getKind() !== KindsEnum::PUBLICATION_INDEX->value) {
                return ['ok' => false, 'error' => 'Event at index '.$i.' must be kind 30040.', 'code' => 400];
            }
            if (!hash_equals($ownerHex, strtolower($wire->getPublicKey()))) {
                return ['ok' => false, 'error' => 'Event pubkey does not match the magazine owner.', 'code' => 403];
            }
            $now = time();
            if ($now - $wire->getCreatedAt() > self::STALE_EVENT_MAX_AGE_SEC || $wire->getCreatedAt() > $now + 60) {
                return ['ok' => false, 'error' => 'Event created_at out of range at index '.$i.'.', 'code' => 400];
            }
            $d = $this->extractSingleDTag($wire->getTags());
            if ($d === '') {
                return ['ok' => false, 'error' => 'Missing or duplicate #d tag at index '.$i.'.', 'code' => 400];
            }
            if (isset($byD[$d])) {
                return ['ok' => false, 'error' => 'Duplicate #d tag in batch: '.$d.'.', 'code' => 400];
            }
            $err = $this->validateTagsForWire($wire->getTags(), $ownerHex);
            if ($err !== null) {
                return ['ok' => false, 'error' => $err.' (event #d '.$d.')', 'code' => 400];
            }
            $byD[$d] = $wire;
        }

        $relays = $this->nostrClient->getArticleWriteRelayUrls();
        if ($relays === []) {
            return ['ok' => false, 'error' => 'No write relays configured.', 'code' => 500];
        }

        $published = 0;
        foreach ($byD as $wire) {
            $res = $this->nostrClient->publishEvent($wire, $relays);
            $okRelays = 0;
            foreach ($res as $relayRes) {
                if ($relayRes instanceof \Throwable) {
                    continue;
                }
                ++$okRelays;
            }
            if ($okRelays < 1) {
                $this->logger->warning('magazine_hierarchy.publish_failed_all_relays', [
                    'id' => $wire->getId(),
                ]);

                return ['ok' => false, 'error' => 'Publish failed on all relays for event '.$wire->getId().'.', 'code' => 502];
            }
            ++$published;
        }

        $stored = 0;
        foreach ($byD as $d => $wire) {
            $entity = $this->wireMerge->magazineEventToPublicationEntity(json_decode($wire->toJson(), true));
            if ($entity === null) {
                return ['ok' => false, 'error' => 'Could not map event to storage model (#d '.$d.').', 'code' => 500];
            }
            if (hash_equals($rootD, $d)) {
                $this->store->putRoot($npub, $rootD, $entity);
            } else {
                $this->store->putCategory($d, $entity);
            }
            ++$stored;
        }

        $longformAddresses = $this->collectLongformCoordinatesFromBatch($byD);
        if ($longformAddresses !== []) {
            try {
                $this->nostrClient->ingestLongformForCategoryCoordinates($longformAddresses);
            } catch (\Throwable $e) {
                $this->logger->warning('magazine_hierarchy.longform_ingest_after_publish_failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('magazine_hierarchy.published', [
            'published' => $published,
            'stored' => $stored,
            'longform_ingest_addresses' => \count($longformAddresses),
        ]);

        return [
            'ok' => true,
            'published' => $published,
            'stored' => $stored,
            'longform_ingest_addresses' => \count($longformAddresses),
        ];
    }

    /**
     * @param array<int, mixed> $tags
     */
    private function validateTagsForWire(array $tags, string $ownerHex): ?string
    {
        foreach ($tags as $row) {
            if (!NostrEventTags::tagNameMatches($row, 'a')) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($row);
            if ($seq === null || !isset($seq[1])) {
                return 'Malformed `a` tag';
            }
            $coord = trim((string) $seq[1]);
            $parts = explode(':', $coord, 3);
            if (\count($parts) < 3) {
                return 'Invalid `a` coordinate';
            }
            $kind = (int) $parts[0];
            $pk = strtolower(trim((string) $parts[1]));
            $id = trim((string) $parts[2]);
            if ($id === '' || 64 !== \strlen($pk) || !ctype_xdigit($pk)) {
                return 'Invalid `a` coordinate pubkey or identifier';
            }
            if ($kind === KindsEnum::PUBLICATION_INDEX->value && !hash_equals($ownerHex, $pk)) {
                return 'Nested 30040 `a` tags must use the magazine owner pubkey';
            }
            if (!\in_array($kind, [
                KindsEnum::PUBLICATION_INDEX->value,
                KindsEnum::LONGFORM->value,
                KindsEnum::LONGFORM_DRAFT->value,
            ], true)) {
                return 'Unsupported kind in `a` tag (only 30040, 30023, 30024)';
            }
        }

        return null;
    }

    /**
     * Long-form `a` coordinates from this publish batch (30023 / 30024) for immediate DB sync.
     *
     * @param array<string, NostrWireEvent> $byD
     *
     * @return list<string>
     */
    private function collectLongformCoordinatesFromBatch(array $byD): array
    {
        $out = [];
        foreach ($byD as $wire) {
            foreach ($wire->getTags() as $row) {
                if (!NostrEventTags::tagNameMatches($row, 'a')) {
                    continue;
                }
                $seq = NostrEventTags::rowToStringList($row);
                if ($seq === null || !isset($seq[1])) {
                    continue;
                }
                $coord = trim((string) $seq[1]);
                $parts = explode(':', $coord, 3);
                if (\count($parts) < 3) {
                    continue;
                }
                $kind = (int) $parts[0];
                if (!\in_array($kind, [KindsEnum::LONGFORM->value, KindsEnum::LONGFORM_DRAFT->value], true)) {
                    continue;
                }
                $pk = strtolower(trim((string) $parts[1]));
                $id = trim((string) $parts[2]);
                if ($id === '' || 64 !== \strlen($pk) || !ctype_xdigit($pk)) {
                    continue;
                }
                $out[] = $kind.':'.$pk.':'.$id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<int, mixed> $tags
     */
    private function extractSingleDTag(array $tags): string
    {
        $found = '';
        foreach ($tags as $row) {
            if (!NostrEventTags::tagNameMatches($row, 'd')) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($row);
            if ($seq === null || !isset($seq[1])) {
                continue;
            }
            $v = trim((string) $seq[1]);
            if ($v === '') {
                continue;
            }
            if ($found !== '') {
                return '';
            }
            $found = $v;
        }

        return $found;
    }
}
