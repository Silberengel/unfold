<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Nostr\MagazineEventKeys;
use Psr\Log\LoggerInterface;

/**
 * When a wire event is returned from a tenant-configured relay (community_relay, search_relays, profile_relays),
 * persist it so later reads can use MySQL first.
 */
class RelayFetchedEventPersister
{
    /** @var array<string, true> lowercase event id hex deduped per {@see beginResponse()} */
    private array $seenEventIds = [];

    public function __construct(
        private readonly NostrRelayListFactory $relayListFactory,
        private readonly ArticleFactory $articleFactory,
        private readonly LongformArticleIngest $longformArticleIngest,
        private readonly NostrCoreEventWriter $coreEventWriter,
        private readonly NostrWireEventMerge $wireMerge,
        private readonly PublicationFeature $publicationFeature,
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly PublicationMagazineFilter $publicationMagazineFilter,
        private readonly Nip30EmojiCatalogBuilder $nip30EmojiCatalogBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function beginResponse(): void
    {
        $this->seenEventIds = [];
    }

    public function persistFromTenantRelay(object $wire, string $relayUrl): void
    {
        if (!$this->relayListFactory->isTenantConfiguredRelay($relayUrl)) {
            return;
        }
        $eventId = strtolower(trim((string) ($wire->id ?? '')));
        if (64 !== \strlen($eventId) || !ctype_xdigit($eventId) || isset($this->seenEventIds[$eventId])) {
            return;
        }
        $this->seenEventIds[$eventId] = true;

        $kind = (int) ($wire->kind ?? 0);
        try {
            if (\in_array($kind, KindsEnum::articleBodyKindValues(), true)) {
                $this->ingestArticleBody($wire);

                return;
            }
            if ($kind === KindsEnum::PUBLICATION_INDEX->value && $this->publicationFeature->isEnabled()) {
                $this->ingestPublicationIndex($wire);

                return;
            }
            if ($kind === KindsEnum::METADATA->value) {
                $this->ingestKind0($wire);

                return;
            }
            if ($kind === KindsEnum::RELAY_LIST->value) {
                $this->ingestRelayList($wire);

                return;
            }
            if ($kind === KindsEnum::PAYMENT_TARGETS->value) {
                $this->ingestPayto($wire);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('relay_fetched_event.persist_failed', [
                'kind' => $kind,
                'event_id' => $eventId,
                'relay' => $relayUrl,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function ingestArticleBody(object $wire): void
    {
        $article = $this->articleFactory->createFromLongFormContentEvent($wire);
        $this->longformArticleIngest->ingest($article);
    }

    private function ingestPublicationIndex(object $wire): void
    {
        if ($this->publicationMagazineFilter->isSiteMagazineWire($wire)) {
            return;
        }
        $entity = $this->wireMerge->magazineEventToPublicationEntity($wire);
        if ($entity === null) {
            return;
        }
        $d = $entity->getSlug();
        if ($d === null || $d === '') {
            return;
        }
        $this->publicationIndexStore->put($entity->getPubkey(), $d, $entity);
    }

    private function ingestKind0(object $wire): void
    {
        $pk = strtolower((string) ($wire->pubkey ?? ''));
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return;
        }
        $catalog = $this->nip30EmojiCatalogBuilder->buildMergedCatalog($wire, null, []);
        $this->coreEventWriter->upsertFromWire(
            MagazineEventKeys::profileKind0($pk),
            Event::STORAGE_PROFILE_KIND0,
            $wire,
            $catalog,
        );
    }

    private function ingestRelayList(object $wire): void
    {
        $pk = strtolower((string) ($wire->pubkey ?? ''));
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return;
        }
        $this->coreEventWriter->upsertFromWire(
            MagazineEventKeys::relayList10002($pk),
            Event::STORAGE_RELAY_LIST_10002,
            $wire,
        );
    }

    private function ingestPayto(object $wire): void
    {
        $pk = strtolower((string) ($wire->pubkey ?? ''));
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return;
        }
        $d = $this->wireMerge->eventDTagValue($wire);
        if ($d === null || $d === '') {
            return;
        }
        $this->coreEventWriter->upsertFromWire(
            MagazineEventKeys::payto10133($pk, $d),
            Event::STORAGE_PAYTO_10133,
            $wire,
        );
    }
}
