<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event as PublicationEventEntity;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;

/**
 * Main integration point for swentel/nostr against configured relays: long-form fetch, kind-0 profile
 * metadata, article discussion and comment publish relay lists, magazine 30040 / highlight 9802 ingest,
 * and related REQ flows. Tuned via `community_relay`, `search_relays`, `profile_relays`, and
 * `nostr_relay_request_timeout_sec` (see `config/unfold.yaml`). Shared building blocks:
 * {@see NostrRelayRequestFactory} (timeouts), {@see NostrRelayQuery} (REQ + response fan-in),
 * {@see NostrRelayFanoutTransport} (sequential vs parallel multi-relay REQ), {@see NostrRelayTransport}
 * (wss + Mercury HTTP), {@see NostrRelayListFactory}
 * (config relay lists, merge/dedupe, {@link RelaySet} for profile fetches, Nostr Land + aggr),
 * {@see NostrAuthorRelayCache} (cached NIP-65 kind-10002 author relay lists),
 * {@see NostrWireEventMerge} (NIP-33 / kind-0 merge, #d tags, npub→hex for wire objects),
 * {@see NostrArticleDiscussionSupport} (article thread REQ filters and tag classifiers),
 * {@see NostrKind5DeletionFilter} (NIP-09 kind-5 relevance for stored row kinds),
 * {@see NostrNip65RelayUrls} (NIP-65 `r` → wss list from kind-10002 wire),
 * {@see NostrLongformArticleStore} (DB upsert for long-form / NIP-23 article rows),
 * {@see RelayFetchedEventPersister} (cache wire events from tenant-configured relays into MySQL).
 */
class NostrClient
{
    /**
     * Hard cap on unique relay URLs for article discussion. More relays do not help much (indexers duplicate)
     * but each URL can spawn a CLI worker ({@see NostrRelayFanoutTransport::sendParallelWorkers}) → Apache/CPU load.
     */
    private const MAX_DISCUSSION_RELAY_URLS = 8;

    /**
     * Kind-9802 highlight ingest ({@see fetchHighlightEventsForArticle} / prewarm): main + article + profile
     * + author NIP-65, deduped. Higher than {@see MAX_DISCUSSION_RELAY_URLS} so profile relays are not dropped.
     */
    private const MAX_HIGHLIGHT_RELAY_URLS = 32;

    private RelaySet $communityRelaySet;

    private RelaySet $searchRelaySet;

    /** @var list<string> */
    private array $communityRelayUrls;

    /** @var list<string> */
    private array $searchRelayUrls;

    /**
     * @param NostrRelayRequestFactory  $relayRequestFactory Per-relay WebSocket I/O cap (see `nostr_relay_request_timeout_sec` in `config/unfold.yaml`)
     * @param NostrRelayFanoutTransport $relayFanout         Multi-relay sequential/parallel + wire logging
     * @param NostrRelayTransport       $relayTransport      wss + Mercury HTTP fan-out
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ArticleFactory $articleFactory,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly NostrRelayRequestFactory $relayRequestFactory,
        private readonly NostrRelayQuery $nostrRelayQuery,
        private readonly NostrRelayFanoutTransport $relayFanout,
        private readonly NostrRelayTransport $relayTransport,
        private readonly MercuryHttpRelayClient $mercuryClient,
        private readonly NostrRelayListFactory $relayListFactory,
        private readonly NostrAuthorRelayCache $authorRelayCache,
        private readonly NostrWireEventMerge $wireMerge,
        private readonly NostrArticleDiscussionSupport $articleDiscussion,
        private readonly NostrKind5DeletionFilter $kind5DeletionFilter,
        private readonly NostrNip65RelayUrls $nip65RelayUrls,
        private readonly NostrLongformArticleStore $longformArticleStore,
        private readonly PublicationFeature $publicationFeature,
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly PublicationMagazineFilter $publicationMagazineFilter,
        private readonly LongformArticleIngest $longformArticleIngest,
    ) {
        $this->communityRelaySet = $this->relayListFactory->getCommunityRelaySet();
        $this->searchRelaySet = $this->relayListFactory->getSearchRelaySet();
        $this->communityRelayUrls = $this->relayListFactory->getCommunityRelayUrlList();
        $this->searchRelayUrls = $this->relayListFactory->getSearchRelayUrlList();
    }

    /**
     * community_relay + search_relays (deduplicated) for publishing user comments.
     *
     * @return list<string>
     */
    public function getArticleWriteRelayUrls(): array
    {
        return $this->relayListFactory->getPublishRelayUrlList();
    }

    /**
     * Relays to publish a kind-1111 reply: site defaults plus NIP-65 outbox (`write` + unmarked `r`)
     * for the publishing pubkey, article author, and (when nested) parent author.
     *
     * @param string $articleCoordinate         kind:pubkey:identifier
     * @param string $parentEventAuthorHex      64-char hex of the event being replied to
     * @param string $publisherPubkeyHex        64-char hex of the logged-in commenter
     *
     * @return list<string>
     */
    public function getRelayUrlsForCommentPublish(
        string $articleCoordinate,
        string $parentEventAuthorHex,
        string $publisherPubkeyHex = '',
    ): array {
        $base = $this->relayListFactory->getPublishRelayUrlList();
        $parts = explode(':', $articleCoordinate, 3);
        $articlePk = \count($parts) >= 2 ? strtolower((string) $parts[1]) : '';
        if (64 !== \strlen($articlePk) || !ctype_xdigit($articlePk)) {
            $articlePk = '';
        }
        $parentPk = strtolower(trim($parentEventAuthorHex));
        if (64 !== \strlen($parentPk) || !ctype_xdigit($parentPk)) {
            $parentPk = '';
        }
        $publisherPk = strtolower(trim($publisherPubkeyHex));
        if (64 !== \strlen($publisherPk) || !ctype_xdigit($publisherPk)) {
            $publisherPk = '';
        }

        $pubkeys = [];
        if ($publisherPk !== '') {
            $pubkeys[] = $publisherPk;
        }
        if ($articlePk !== '') {
            $pubkeys[] = $articlePk;
        }
        if ($parentPk !== '' && $parentPk !== $articlePk) {
            $pubkeys[] = $parentPk;
        }
        $pubkeys = array_values(array_unique($pubkeys));

        $seen = array_fill_keys($base, true);
        $out = $base;
        foreach ($pubkeys as $pk) {
            foreach ($this->authorRelayCache->getAuthorNip65OutboxRelaysList($pk) as $wss) {
                if ($wss === '' || isset($seen[$wss])) {
                    continue;
                }
                $seen[$wss] = true;
                $out[] = $wss;
            }
        }

        return $out;
    }

    /**
     * Suffix to segregate HTTP caches: aggr is only used for some logged-in readers, so results differ.
     *
     * @return string empty when aggr is not used, else a short token
     */
    public function getNostrLandAggrReaderCacheSuffix(): string
    {
        return $this->relayListFactory->getNostrLandAggrReaderCacheSuffix();
    }

    /**
     * Batched kind-0 fetch: one REQ per chunk; returns latest wire event per author (for DB persistence).
     *
     * @param list<string> $authorPubkeyHex
     * @return array<string, object> Keyed by lowercase 64-hex pubkey
     */
    public function fetchKind0WireEventsForAuthors(array $authorPubkeyHex, int $authorsPerRequest = 50): array
    {
        $bundles = $this->fetchProfilePrewarmWireBundlesForAuthors($authorPubkeyHex, $authorsPerRequest);
        $byPub = [];
        foreach ($bundles as $pk => $bundle) {
            $byPub[$pk] = $bundle['kind0'];
        }

        return $byPub;
    }

    /**
     * Prewarm: kind 0 + NIP-51 kind 10030 (emoji list) + NIP-38 kind 30315 (status), one REQ per chunk.
     *
     * @param list<string> $authorPubkeyHex
     *
     * @return array<string, array{kind0: object, emoji_list: ?object, statuses: list<object>}> keyed by lowercase 64-hex pubkey (only authors with a kind-0 hit in this response)
     */
    public function fetchProfilePrewarmWireBundlesForAuthors(
        array $authorPubkeyHex,
        int $authorsPerRequest = 50,
        ?int $relayTimeoutSec = null,
    ): array {
        $authorPubkeyHex = \array_values(\array_unique(\array_filter(
            $authorPubkeyHex,
            static fn (string $h): bool => 64 === \strlen($h),
        )));
        if ($authorPubkeyHex === []) {
            return [];
        }
        $authorsPerRequest = max(1, min(200, $authorsPerRequest));
        $chunks = array_chunk($authorPubkeyHex, $authorsPerRequest);
        $bundles = [];
        foreach ($chunks as $chunk) {
            $chunkLower = [];
            foreach ($chunk as $h) {
                $chunkLower[strtolower($h)] = true;
            }
            $events = $this->nostrRelayQuery->processResponse(
                $this->nostrRelayQuery->sendNostrQuery(
                    $this->relayListFactory->getProfileMetadataQueryRelayUrlList(),
                    [
                        KindsEnum::METADATA,
                        KindsEnum::EMOJI_LIST,
                        KindsEnum::USER_STATUS,
                    ],
                    ['authors' => $chunk],
                    $relayTimeoutSec,
                ),
                static fn ($ev) => $ev,
            );
            $kind0Only = [];
            foreach ($events as $ev) {
                if (\is_object($ev) && (int) ($ev->kind ?? 0) === KindsEnum::METADATA->value) {
                    $kind0Only[] = $ev;
                }
            }
            $byAddr0 = $this->wireMerge->mergeKind0EventsByReplaceableAddress($kind0Only);
            $kind0ByPk = [];
            foreach ($byAddr0 as $addr => $ev) {
                $pk = \substr((string) $addr, 2);
                if (64 === \strlen($pk) && ctype_xdigit($pk)) {
                    $kind0ByPk[strtolower($pk)] = $ev;
                }
            }
            $k10030 = KindsEnum::EMOJI_LIST->value;
            $k30315 = KindsEnum::USER_STATUS->value;
            /** @var array<string, object> $by10030 */
            $by10030 = [];
            /** @var array<string, list<object>> $by30315 */
            $by30315 = [];
            foreach ($events as $ev) {
                if (!\is_object($ev)) {
                    continue;
                }
                $k = (int) ($ev->kind ?? 0);
                $pk = strtolower((string) ($ev->pubkey ?? ''));
                if (64 !== \strlen($pk) || !ctype_xdigit($pk) || !isset($chunkLower[$pk])) {
                    continue;
                }
                if ($k === $k10030) {
                    if (!isset($by10030[$pk]) || $this->wireMerge->wireEventSupersedes($ev, $by10030[$pk])) {
                        $by10030[$pk] = $ev;
                    }
                } elseif ($k === $k30315) {
                    $by30315[$pk][] = $ev;
                }
            }
            foreach ($by30315 as $pk => $list) {
                \usort($list, function (object $a, object $b): int {
                    return $this->wireMerge->magazineEventCreatedAt($b) <=> $this->wireMerge->magazineEventCreatedAt($a);
                });
                $by30315[$pk] = \array_slice($list, 0, 28);
            }
            foreach ($kind0ByPk as $pk => $k0) {
                $bundles[$pk] = [
                    'kind0' => $k0,
                    'emoji_list' => $by10030[$pk] ?? null,
                    'statuses' => $by30315[$pk] ?? [],
                ];
            }
        }

        return $bundles;
    }

    /**
     * NIP-09 kind 5 deletion requests in $since..$until (unix), batched by author pubkey (hex).
     *
     * @param (callable(int, int, int): void)|null $afterChunk 1-based index, total chunks, pubkeys in chunk
     * @param list<string>        $authorPubkeyHex
     * @return list<stdClass>     Deduplicated by event `id` (highest {@see created_at} kept)
     */
    public function fetchKind5DeletionEventsForAuthors(
        array $authorPubkeyHex,
        int $since,
        int $until,
        int $authorsPerRequest = 40,
        ?callable $afterChunk = null,
    ): array {
        $authorPubkeyHex = \array_values(\array_unique(\array_filter(
            $authorPubkeyHex,
            static fn (string $h): bool => 64 === \strlen($h),
        )));
        if ($authorPubkeyHex === [] || $since >= $until) {
            return [];
        }
        $authorsPerRequest = max(1, min(100, $authorsPerRequest));
        $byId = [];
        $chunks = array_chunk($authorPubkeyHex, $authorsPerRequest);
        $numChunks = \count($chunks);
        foreach ($chunks as $i => $chunk) {
            $request = $this->nostrRelayQuery->createNostrRequest(
                defaultRelaySet: $this->searchRelaySet,
                kinds: [KindsEnum::DELETION_REQUEST],
                filters: [
                    'authors' => $chunk,
                    'since' => $since,
                    'until' => $until,
                ],
            );
            $t0 = microtime(true);
            $events = $this->nostrRelayQuery->processResponse(
                $this->nostrRelayQuery->sendCreatedRequest($request, $this->searchRelayUrls),
                static fn (object $event) => $event,
            );
            $this->logger->info('nostr.nip09.kind5_chunk', [
                'authors' => \count($chunk),
                'raw_events' => \count($events),
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
            if ($afterChunk !== null) {
                $afterChunk(1 + (int) $i, $numChunks, \count($chunk));
            }
            foreach ($events as $ev) {
                if (!\is_object($ev) || (int) ($ev->kind ?? 0) !== KindsEnum::DELETION_REQUEST->value) {
                    continue;
                }
                if (!$this->kind5DeletionFilter->isRelevantToStoredDbData($ev)) {
                    continue;
                }
                $id = (string) ($ev->id ?? '');
                if (64 !== \strlen($id)) {
                    continue;
                }
                $t = (int) ($ev->created_at ?? 0);
                if (isset($byId[$id]) && $t <= (int) ($byId[$id]->created_at ?? 0)) {
                    continue;
                }
                $byId[$id] = $ev;
            }
        }

        return array_values($byId);
    }

    /**
     * NIP-A3 kind 10133: payment target events; NIP kind-range 10_000–19_999 is replaceable by
     * (kind, pubkey), so multi-relay results are merged to the live revision per
     * {@see wireEventSupersedes} (at most one event for this author).
     *
     * @return list<object>
     */
    public function getKind10133PaymentTargetEventsForNpub(string $npub, int $limit = 20): array
    {
        $authorHex = $this->wireMerge->authorIdentToHexLower($npub);
        if ($authorHex === null) {
            return [];
        }
        $relaysTried = $this->relayListFactory->capSequentialRelaysForProfileFetches($this->relayListFactory->getProfileMetadataQueryRelayUrlList());
        $relaysTriedStr = implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $relaysTried));
        try {
            $events = $this->nostrRelayQuery->processResponse(
                $this->nostrRelayQuery->sendNostrQuery(
                    $relaysTried,
                    [KindsEnum::PAYMENT_TARGETS],
                    ['authors' => [$authorHex], 'limit' => max(1, min(50, $limit))],
                ),
                static fn ($ev) => $ev,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('nostr.kind10133.fetch_failed', [
                'npub' => $npub,
                'relays' => $relaysTriedStr,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
        if ($events === []) {
            return [];
        }

        return $this->wireMerge->mergeNip33ParameterizedWireEvents($events);
    }

    public function publishEvent(Event $event, array $relays): array
    {
        $eventMessage = new EventMessage($event);
        $results = [];
        foreach ($relays as $relayUrl) {
            if (!\is_string($relayUrl) || $relayUrl === '') {
                continue;
            }
            if (str_starts_with($relayUrl, 'http://') || str_starts_with($relayUrl, 'https://')) {
                $results[$relayUrl] = $this->mercuryClient->publish($relayUrl, $event);
                continue;
            }
            if (!str_starts_with($relayUrl, 'wss:')) {
                continue;
            }
            try {
                $relaySet = new RelaySet();
                $relaySet->addRelay(new Relay($relayUrl));
                $relaySet->setMessage($eventMessage);
                $this->relayRequestFactory->applySocketTimeoutToRelaySet($relaySet);
                $sent = $relaySet->send();
                if (\array_key_exists($relayUrl, $sent)) {
                    $results[$relayUrl] = $sent[$relayUrl];
                } else {
                    $results[$relayUrl] = $sent;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('nostr.publish.relay_failed', [
                    'relay' => $relayUrl,
                    'error' => $e->getMessage(),
                    'exception_class' => \get_class($e),
                ]);
                $results[$relayUrl] = $e;
            }
        }

        return $results;
    }

    /**
     * Backfill long-form (NIP-23) in time windows so relay responses and PHP stay bounded (avoids
     * OOM on year-wide queries with many relays). ~60 days per step (≈2 months).
     */
    private const LONGFORM_BACKFILL_CHUNK_SECONDS = 5184000; // 60 days

    /**
     * Long-form Content
     * NIP-23
     */
    public function getLongFormContent($from = null, $to = null): void
    {
        $toTs = $to !== null ? (int) $to : time();
        $fromTs = $from !== null ? (int) $from : strtotime('-1 week');
        if ($fromTs >= $toTs) {
            return;
        }

        $chunk = self::LONGFORM_BACKFILL_CHUNK_SECONDS;
        for ($windowFrom = $fromTs; $windowFrom < $toTs; $windowFrom += $chunk) {
            $windowTo = min($windowFrom + $chunk, $toTs);
            $this->ingestArticleBodiesForTimeWindow($windowFrom, $windowTo, KindsEnum::longformKindValues(), $this->communityRelayUrls);
            if ($this->publicationFeature->isEnabled()) {
                $this->ingestPublicationIndicesForTimeWindow($windowFrom, $windowTo);
                $this->ingestArticleBodiesForTimeWindow($windowFrom, $windowTo, [
                    KindsEnum::PUBLICATION_CONTENT->value,
                    KindsEnum::WIKI_ARTICLE->value,
                ], $this->communityRelayUrls);
            }
            $this->ingestArticleBodiesForTimeWindow($windowFrom, $windowTo, KindsEnum::longformKindValues(), $this->searchRelayUrls);
            $this->entityManager->clear();
        }
    }

    /**
     * @param list<int> $kinds
     * @param list<string> $relayUrls
     */
    private function ingestArticleBodiesForTimeWindow(int $since, int $until, array $kinds, array $relayUrls): void
    {
        if ($kinds === [] || $relayUrls === []) {
            return;
        }
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds($kinds);
        $filter->setSince($since);
        $filter->setUntil($until);
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        $wrappers = $this->nostrRelayQuery->processResponse(
            $this->nostrRelayQuery->sendToUrls($relayUrls, $requestMessage),
            function (object $event) {
            $w = new \stdClass();
            $w->event = $event;

            return $w;
        });
        if ($wrappers !== []) {
            $this->saveLongFormContent($wrappers);
        }
    }

    public function ingestPublicationIndicesForTimeWindow(int $since, int $until): int
    {
        if (!$this->publicationFeature->isEnabled()) {
            return 0;
        }
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([KindsEnum::PUBLICATION_INDEX->value]);
        $filter->setSince($since);
        $filter->setUntil($until);
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);
        $events = $this->nostrRelayQuery->processResponse(
            $this->nostrRelayQuery->sendToUrls($this->communityRelayUrls, $requestMessage),
            static fn (object $event) => $event,
        );
        $stored = 0;
        foreach ($this->wireMerge->mergeNip33ParameterizedWireEvents($events) as $wire) {
            if ($this->publicationMagazineFilter->isSiteMagazineWire($wire)) {
                continue;
            }
            $entity = $this->wireMerge->magazineEventToPublicationEntity($wire);
            if ($entity === null) {
                continue;
            }
            $d = $entity->getSlug();
            if ($d === null || $d === '') {
                continue;
            }
            try {
                $this->publicationIndexStore->put($entity->getPubkey(), $d, $entity);
                ++$stored;
            } catch (\Throwable $e) {
                $this->logger->warning('ingestPublicationIndices: store failed', [
                    'd' => $d,
                    'pubkey' => $entity->getPubkey(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $stored;
    }

    public function fetchAndStorePublicationIndex(string $npub, string $dTag, ?int $relayTimeoutSec = null): ?PublicationEventEntity
    {
        $urls = $this->relayListFactory->getCommunityRelayUrlList();
        $relaysForLog = implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $urls));
        $entity = $this->queryMagazineIndex($npub, $dTag, $this->communityRelayUrls, $relaysForLog, $relayTimeoutSec);
        if ($entity === null) {
            return null;
        }
        if ($this->publicationMagazineFilter->isSiteMagazineIndex($entity)) {
            return null;
        }
        $this->publicationIndexStore->put($npub, $dTag, $entity);

        return $entity;
    }

    /**
     * @throws \Exception
     */
    public function getLongFormFromNaddr($slug, $relayList, $author, $kind): void
    {
        if (empty($relayList)) {
            $topAuthorRelays = $this->authorRelayCache->getTopReputableRelaysForAuthor($author);
            $authorRelaySet = $this->relayListFactory->createRelaySetMergedWithArticleList($topAuthorRelays);
            $relaysTried = $this->plannedRelayUrlsForSet($topAuthorRelays);
        } else {
            $authorRelaySet = $this->relayListFactory->createRelaySetMergedWithArticleList($relayList);
            $relaysTried = $this->plannedRelayUrlsForSet($relayList);
        }
        $relaysTriedStr = implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $relaysTried));

        $authorHex = $this->wireMerge->authorIdentToHexLower($author);
        if ($authorHex === null) {
            $this->logger->warning('nostr.longform_naddr.invalid_author', ['author' => $author]);

            return;
        }

        try {
            $events = $this->nostrRelayQuery->processResponse(
                $this->nostrRelayQuery->sendCreatedRequest(
                    $this->nostrRelayQuery->createNostrRequest(
                        defaultRelaySet: $this->searchRelaySet,
                        kinds: [$kind],
                        filters: [
                            'authors' => [$authorHex],
                            'tag' => ['#d', [$slug]],
                        ],
                        relaySet: $authorRelaySet,
                    ),
                    $relaysTried,
                ),
                function ($event) {
                return $event;
            });

            if (!empty($events)) {
                $kindI = (int) $kind;
                $event = $this->wireMerge->isNip33ParameterizedKind($kindI)
                    ? $this->wireMerge->pickLatestNip33ParameterizedForQuery($events, $kindI, $authorHex, (string) $slug)
                    : null;
                if ($event === null) {
                    $event = $events[0];
                }
                $wrapper = new \stdClass();
                $wrapper->type = 'EVENT';
                $wrapper->event = $event;
                $this->saveLongFormContent([$wrapper]);
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error querying relays (%s): %s', $relaysTriedStr, $e->getMessage()), [
                'error' => $e->getMessage(),
                'relays' => $relaysTried,
            ]);
            throw new \Exception('Error querying relays', 0, $e);
        }
    }

    /**
     * Get event by its ID
     *
     * @param string $eventId The event ID
     * @param array $relays Optional array of relay URLs to query
     * @return object|null The event or null if not found
     * @throws \Exception
     */
    public function getEventById(string $eventId, array $relays = []): ?object
    {
        $this->logger->info('Getting event by ID', ['event_id' => $eventId, 'relays' => $relays]);

        $relayUrls = empty($relays)
            ? $this->searchRelayUrls
            : $this->relayListFactory->mergeSearchRelayUrlList($relays);
        $relaySet = empty($relays) ? $this->searchRelaySet : $this->relayListFactory->createRelaySetMergedWithSearchList($relays);

        // Create request using the helper method
        $request = $this->nostrRelayQuery->createNostrRequest(
            defaultRelaySet: $this->searchRelaySet,
            kinds: [], // Leave empty to accept any kind
            filters: ['ids' => [$eventId]],
            relaySet: $relaySet
        );

        // Process the response
        $events = $this->nostrRelayQuery->processResponse(
            $this->nostrRelayQuery->sendCreatedRequest($request, $relayUrls),
            function ($event) {
            $this->logger->debug('Received event', ['event' => $event]);

            return $event;
        });

        if (empty($events)) {
            return null;
        }

        // Return the first matching event
        return $events[0];
    }

    /**
     * Batch-fetch kind-1 events by id (e.g. discussion / tooling). Merges article relay set first, then
     * profile-only relays for any still missing ids.
     *
     * @param list<string> $eventIdHexes
     *
     * @return array<string, object> lowercase event id hex → wire event (kind 1)
     */
    public function getKind1EventsByIdsIndexed(array $eventIdHexes): array
    {
        $want = [];
        foreach ($eventIdHexes as $raw) {
            $id = strtolower(trim((string) $raw));
            if (64 === \strlen($id) && ctype_xdigit($id)) {
                $want[$id] = true;
            }
        }
        $idList = array_keys($want);
        if ($idList === []) {
            return [];
        }
        $idList = \array_slice($idList, 0, 100);

        $articleUrls = $this->relayListFactory->mergeSearchRelayUrlList([]);
        $byId = $this->queryKind1EventsByIdsFromRelayUrls($idList, $articleUrls);
        $missing = array_values(array_diff($idList, array_keys($byId)));
        if ($missing !== []) {
            $profileExtra = $this->relayListFactory->getProfileRelayUrlsExcludedFromSearchRelays();
            if ($profileExtra !== []) {
                $extra = $this->queryKind1EventsByIdsFromRelayUrls($missing, $profileExtra);
                foreach ($extra as $id => $ev) {
                    if (!isset($byId[$id])) {
                        $byId[$id] = $ev;
                    }
                }
            }
        }

        return $byId;
    }

    /**
     * @param list<string> $eventIdHexes
     * @param list<string> $relayUrls
     *
     * @return array<string, object>
     */
    private function queryKind1EventsByIdsFromRelayUrls(array $eventIdHexes, array $relayUrls): array
    {
        if ($eventIdHexes === [] || $relayUrls === []) {
            return [];
        }
        $request = $this->nostrRelayQuery->createNostrRequest(
            defaultRelaySet: $this->searchRelaySet,
            relaySet: $this->relayListFactory->relaySetFromDistinctUrlList($relayUrls),
            kinds: [KindsEnum::TEXT_NOTE],
            filters: ['ids' => $eventIdHexes],
        );
        $events = $this->nostrRelayQuery->processResponse(
            $this->nostrRelayQuery->sendCreatedRequest($request, $relayUrls),
            static fn (object $event) => $event,
        );
        $out = [];
        foreach ($events as $e) {
            if (!\is_object($e)) {
                continue;
            }
            $id = strtolower((string) ($e->id ?? ''));
            if (64 !== \strlen($id) || !ctype_xdigit($id)) {
                continue;
            }
            if ((int) ($e->kind ?? 0) !== KindsEnum::TEXT_NOTE->value) {
                continue;
            }
            if (!isset($out[$id])) {
                $out[$id] = $e;
            }
        }

        return $out;
    }

    /**
     * Fetch event by naddr
     *
     * @param array $decoded Decoded naddr data
     * @return object|null The event or null if not found
     * @throws \Exception
     */
    public function getEventByNaddr(array $decoded): ?object
    {
        $this->logger->info('Getting event by naddr', ['decoded' => $decoded]);

        // Extract required fields from decoded data
        $kind = $decoded['kind'] ?? 30023; // Default to long-form content
        $pubkey = $decoded['pubkey'] ?? '';
        $identifier = $decoded['identifier'] ?? '';
        $relays = $decoded['relays'] ?? [];

        if (empty($pubkey) || empty($identifier)) {
            return null;
        }
        $authorHex = $this->wireMerge->authorIdentToHexLower($pubkey);
        if ($authorHex === null) {
            return null;
        }

        // Try author's relays first
        $authorRelays = empty($relays) ? $this->authorRelayCache->getTopReputableRelaysForAuthor($authorHex) : $relays;
        $authorRelayUrls = $this->plannedRelayUrlsForSet($authorRelays);
        $relaySet = $this->relayListFactory->createRelaySetMergedWithArticleList($authorRelays);

        // Create request using the helper method
        $request = $this->nostrRelayQuery->createNostrRequest(
            defaultRelaySet: $this->searchRelaySet,
            kinds: [$kind],
            filters: [
                'authors' => [$authorHex],
                'tag' => ['#d', [$identifier]],
            ],
            relaySet: $relaySet,
        );

        // Process the response
        $events = $this->nostrRelayQuery->processResponse(
            $this->nostrRelayQuery->sendCreatedRequest($request, $authorRelayUrls),
            function ($event) {
            return $event;
        },
        );

        if (!empty($events)) {
            return $events[0];
        }

        // Try default relays as fallback
        $request = $this->nostrRelayQuery->createNostrRequest(
            defaultRelaySet: $this->searchRelaySet,
            kinds: [$kind],
            filters: [
                'authors' => [$authorHex],
                'tag' => ['#d', [$identifier]],
            ],
        );

        $events = $this->nostrRelayQuery->processResponse(
            $this->nostrRelayQuery->sendCreatedRequest($request, $this->searchRelayUrls),
            static fn (object $e) => $e,
        );

        return !empty($events) ? $events[0] : null;
    }

    /**
     * Fetch a note by its ID
     *
     * @param string $noteId The note ID
     * @return object|null The note or null if not found
     * @throws \Exception
     */
    public function getNoteById(string $noteId): ?object
    {
        return $this->getEventById($noteId);
    }

    private function saveLongFormContent(mixed $filtered): void
    {
        $events = [];
        foreach ($filtered as $wrapper) {
            if (isset($wrapper->event) && \is_object($wrapper->event)) {
                $events[] = $wrapper->event;
            }
        }
        foreach ($this->wireMerge->mergeNip33ParameterizedWireEvents($events) as $event) {
            $article = $this->articleFactory->createFromLongFormContentEvent($event);
            $this->saveEachArticleToTheDatabase($article);
        }
    }

    /**
     * Merged NIP-65 (kind 10002) event for the author, or null.
     */
    public function getNpubRelayList10002Wire($npub): ?object
    {
        $authorHex = $this->wireMerge->authorIdentToHexLower($npub);
        if ($authorHex === null) {
            return null;
        }
        $request = $this->nostrRelayQuery->createNostrRequest(
            defaultRelaySet: $this->searchRelaySet,
            kinds: [KindsEnum::RELAY_LIST],
            filters: ['authors' => [$authorHex]],
            relaySet: $this->searchRelaySet
        );
        $response = $this->nostrRelayQuery->processResponse(
            $this->nostrRelayQuery->sendCreatedRequest($request, $this->searchRelayUrls),
            function ($received) {
            return $received;
        },
        );
        if (empty($response)) {
            return null;
        }
        $merged = $this->wireMerge->mergeNip33ParameterizedWireEvents($response);
        $k10002 = (int) KindsEnum::RELAY_LIST->value;
        foreach ($merged as $e) {
            if ((int) ($e->kind ?? 0) === $k10002) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function getNpubRelays($npub): array
    {
        $use = $this->getNpubRelayList10002Wire($npub);
        if ($use === null) {
            return [];
        }

        return $this->nip65RelayUrls->wssListFromKind10002Wire($use);
    }

    /**
     * NIP-65 outbox relays for publish fan-out.
     *
     * @return list<string>
     */
    public function getNpubOutboxRelays(mixed $npub): array
    {
        $use = $this->getNpubRelayList10002Wire($npub);
        if ($use === null) {
            return [];
        }

        return $this->nip65RelayUrls->outboxWssListFromKind10002Wire($use);
    }

    /**
     * NIP-22 kind 1111 thread, legacy kind 1 replies (pre-NIP-22 clients), and quote/repost-style references.
     * Kind 9802 highlights are excluded; they are stored in `article_highlight` via {@see fetchHighlightEventsForArticle()}.
     *
     * @param string               $coordinate      kind:pubkey:d-identifier (e.g. longform address)
     * @param null|string          $rootEventHexId  Published article event id (hex) for #e / #q matching
     *
     * @param null|callable(array{thread: array<int, object>, quotes: array<int, object>, superchats: list<array<string,mixed>>, partial?: bool}): void $onProgress When set, relays are queried in small batches and this is called after each batch (for incremental comment cache).
     *
     * @return array{thread: array<int, object>, quotes: array<int, object>, superchats: list<array<string,mixed>>, partial?: bool}
     */
    public function getArticleDiscussion(string $coordinate, ?string $rootEventHexId = null, ?callable $onProgress = null): array
    {
        $this->logger->info('nostr.article_discussion.start', [
            'coordinate' => $coordinate,
            'root_event_hex' => $rootEventHexId,
            'incremental' => $onProgress !== null,
        ]);

        $parts = explode(':', $coordinate, 3);
        if (\count($parts) < 3) {
            throw new \InvalidArgumentException('Invalid coordinate format, expected kind:pubkey:identifier');
        }
        $pubkey = $parts[1];

        $tRelays = microtime(true);
        $authorRelays = $this->authorRelayCache->getAuthorNip65RelaysList($pubkey);
        $this->logger->info('nostr.article_discussion.author_relays_ready', [
            'elapsed_ms' => (int) round((microtime(true) - $tRelays) * 1000),
            'author_relay_count' => \count($authorRelays),
        ]);

        $baseForDiscussion = $this->relayListFactory->getSearchRelayUrlList();
        $mergedForDiscussion = $this->relayListFactory->withAggrNostrLandIfUserSubscribesNostrLand(
            array_merge($baseForDiscussion, $authorRelays)
        );
        $plannedRelayUrls = array_values(array_unique($mergedForDiscussion, \SORT_REGULAR));
        if (\count($plannedRelayUrls) > self::MAX_DISCUSSION_RELAY_URLS) {
            $plannedRelayUrls = \array_slice($plannedRelayUrls, 0, self::MAX_DISCUSSION_RELAY_URLS);
            $this->logger->notice('nostr.article_discussion.relay_list_capped', [
                'max' => self::MAX_DISCUSSION_RELAY_URLS,
            ]);
        }

        $filters = $this->articleDiscussion->createArticleDiscussionFilters($coordinate, $rootEventHexId);
        foreach ($this->articleDiscussion->createSuperchatFilters($coordinate, $pubkey) as $sf) {
            $filters[] = $sf;
        }
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $requestMessage = new RequestMessage($subscriptionId, $filters);

        $this->logger->info('nostr.article_discussion.req_sending', [
            'subscription_id' => $subscriptionId,
            'filter_count' => \count($filters),
            'relay_urls' => $plannedRelayUrls,
            'relay_count' => \count($plannedRelayUrls),
        ]);

        if ($onProgress !== null) {
            return $this->fetchArticleDiscussionIncrementally(
                $plannedRelayUrls,
                $requestMessage,
                $coordinate,
                $rootEventHexId,
                $pubkey,
                $onProgress,
            );
        }

        $byId = [];
        $respondedRelayCount = 0;
        try {
            $tSend = microtime(true);
            $workerPath = $this->projectDir.'/bin/nostr_relay_request_worker.php';
            if (!\is_file($workerPath) || \count($plannedRelayUrls) <= 1) {
                $forSeq = $this->relayFanout->capUrlsForSequential($plannedRelayUrls);
                $response = $this->relayTransport->sendToUrls($forSeq, $requestMessage);
            } else {
                try {
                    $response = $this->relayTransport->sendParallelToUrls($plannedRelayUrls, $requestMessage);
                } catch (\Throwable $e) {
                    $this->logger->warning('nostr.article_discussion.parallel_failed', [
                        'message' => $e->getMessage(),
                        'exception_class' => \get_class($e),
                    ]);
                    $forSeq = $this->relayFanout->capUrlsForSequential($plannedRelayUrls);
                    $this->logger->warning('nostr.article_discussion.sequential_fallback', [
                        'relays' => $forSeq,
                    ]);
                    $seqTimeoutSec = min(6, $this->relayFanout->getRelayRequestTimeoutSec());
                    $response = $this->relayTransport->sendToUrls($forSeq, $requestMessage, $seqTimeoutSec);
                }
            }
            $sendMs = (int) round((microtime(true) - $tSend) * 1000);
            $this->logger->info('nostr.article_discussion.req_response_envelope', [
                'elapsed_ms' => $sendMs,
                'subscription_id' => $subscriptionId,
            ]);
            $this->relayFanout->logWireResponseSummary('article_discussion', $response);
            $respondedRelayCount = \count($response);
            $this->mergeDiscussionEventsFromWireResponse($response, $byId);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'nostr.article_discussion.req_send_failed (relays: %s): %s',
                implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $plannedRelayUrls)),
                $e->getMessage()
            ), [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
                'exception_class' => \get_class($e),
                'relays' => $plannedRelayUrls,
            ]);

            throw new \RuntimeException('Nostr request failed for article discussion', 0, $e);
        }

        $partial = $respondedRelayCount < \count($plannedRelayUrls);

        return $this->assembleArticleDiscussionFromEventMap(
            $byId,
            $coordinate,
            $rootEventHexId,
            $pubkey,
            $partial,
            \count($plannedRelayUrls),
            $respondedRelayCount,
        );
    }

    /**
     * @param list<string> $plannedRelayUrls
     * @param callable(array{thread: array<int, object>, quotes: array<int, object>, superchats: list<array<string,mixed>>, partial?: bool}): void $onProgress
     *
     * @return array{thread: array<int, object>, quotes: array<int, object>, superchats: list<array<string,mixed>>, partial?: bool}
     */
    private function fetchArticleDiscussionIncrementally(
        array $plannedRelayUrls,
        RequestMessage $requestMessage,
        string $coordinate,
        ?string $rootEventHexId,
        string $pubkey,
        callable $onProgress,
    ): array {
        /** @var array<string, object> $byId */
        $byId = [];
        $respondedRelayCount = 0;
        $timeoutSec = $this->relayFanout->getRelayRequestTimeoutSec();
        $batches = \array_chunk($plannedRelayUrls, 2);

        foreach ($batches as $batch) {
            try {
                $response = $this->relayTransport->sendToUrls($batch, $requestMessage, $timeoutSec);
                $respondedRelayCount += \count($response);
                $this->relayFanout->logWireResponseSummary('article_discussion_batch', $response);
                $this->mergeDiscussionEventsFromWireResponse($response, $byId);
            } catch (\Throwable $e) {
                $this->logger->warning('nostr.article_discussion.batch_failed', [
                    'relays' => $batch,
                    'message' => $e->getMessage(),
                ]);
            }
            $onProgress($this->assembleArticleDiscussionFromEventMap(
                $byId,
                $coordinate,
                $rootEventHexId,
                $pubkey,
                true,
                \count($plannedRelayUrls),
                $respondedRelayCount,
            ));
        }

        $partial = $respondedRelayCount < \count($plannedRelayUrls);

        return $this->assembleArticleDiscussionFromEventMap(
            $byId,
            $coordinate,
            $rootEventHexId,
            $pubkey,
            $partial,
            \count($plannedRelayUrls),
            $respondedRelayCount,
        );
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, object> $byId
     */
    private function mergeDiscussionEventsFromWireResponse(array $response, array &$byId): void
    {
        $this->nostrRelayQuery->processResponse($response, function ($event) use (&$byId) {
            if (\is_object($event) && isset($event->id)) {
                $byId[(string) $event->id] = $event;
            }

            return null;
        });
    }

    /**
     * @param array<string, object> $byId
     *
     * @return array{thread: array<int, object>, quotes: array<int, object>, superchats: list<array<string,mixed>>, partial?: bool}
     */
    private function assembleArticleDiscussionFromEventMap(
        array $byId,
        string $coordinate,
        ?string $rootEventHexId,
        string $pubkey,
        bool $partial,
        int $plannedRelayCount,
        int $respondedRelayCount,
    ): array {
        $tParse = microtime(true);
        $this->logger->info('nostr.article_discussion.events_collected', [
            'elapsed_ms' => (int) round((microtime(true) - $tParse) * 1000),
            'unique_events' => \count($byId),
        ]);

        $all = array_values($byId);
        $thread = [];
        $threadIds = [];
        $attestRequiredSuperchats = [];
        $selfAttestingSuperchats = [];
        $attestations9741 = [];

        foreach ($all as $event) {
            $kind = (int) ($event->kind ?? 0);
            if ($kind === KindsEnum::PAYMENT_NOTIFICATION->value || $kind === KindsEnum::MONERO_ZAP_RECEIPT->value) {
                $attestRequiredSuperchats[] = $event;
                continue;
            }
            if ($kind === KindsEnum::MONERO_TIP->value) {
                $selfAttestingSuperchats[] = $event;
                continue;
            }
            if ($kind === KindsEnum::PAYMENT_ATTESTATION->value) {
                $attestations9741[] = $event;
                continue;
            }
            if ($kind === KindsEnum::COMMENTS->value && $this->articleDiscussion->eventIsNip22ArticleThreadReply($event, $coordinate)) {
                $thread[] = $event;
                $threadIds[(string) $event->id] = true;

                continue;
            }
            if ($kind === KindsEnum::TEXT_NOTE->value && $this->articleDiscussion->eventIsLegacyThreadReply($event, $coordinate, $rootEventHexId)) {
                $thread[] = $event;
                $threadIds[(string) $event->id] = true;
            }
        }

        $superchatKinds = [
            KindsEnum::PAYMENT_NOTIFICATION->value,
            KindsEnum::MONERO_ZAP_RECEIPT->value,
            KindsEnum::MONERO_TIP->value,
            KindsEnum::PAYMENT_ATTESTATION->value,
        ];
        $quotes = [];
        foreach ($all as $event) {
            $id = (string) ($event->id ?? '');
            if ($id === '' || isset($threadIds[$id])) {
                continue;
            }
            if (\in_array((int) ($event->kind ?? 0), $superchatKinds, true)) {
                continue;
            }
            if ($this->articleDiscussion->eventIsArticleQuote($event, $coordinate, $rootEventHexId)) {
                $quotes[] = $event;
            }
        }

        $superchats = $this->articleDiscussion->buildSuperchatItems(
            $attestRequiredSuperchats,
            $selfAttestingSuperchats,
            $attestations9741,
            $pubkey,
        );

        $sortAsc = static function ($a, $b): int {
            return ((int) ($a->created_at ?? 0)) <=> ((int) ($b->created_at ?? 0));
        };
        $sortDesc = static function ($a, $b): int {
            return ((int) ($b->created_at ?? 0)) <=> ((int) ($a->created_at ?? 0));
        };
        usort($thread, $sortAsc);
        usort($quotes, $sortDesc);

        $this->logger->info('nostr.article_discussion.done', [
            'thread_count' => \count($thread),
            'quotes_count' => \count($quotes),
            'superchat_count' => \count($superchats),
            'partial' => $partial,
            'responded_relays' => $respondedRelayCount,
            'planned_relays' => $plannedRelayCount,
        ]);

        $out = ['thread' => $thread, 'quotes' => $quotes, 'superchats' => $superchats];
        if ($partial) {
            $out['partial'] = true;
        }

        return $out;
    }

    /**
     * Fetches kind 9802 (highlights) that reference the long-form address. Used for DB ingest only
     * ({@see HighlightSyncService} / prewarm). Relays: {@see NostrRelayListFactory::getSearchRelayUrlList()}, then
     * {@see NostrRelayListFactory::getProfileRelayUrlList()}, then author NIP-65, deduped (cap
     * {@see MAX_HIGHLIGHT_RELAY_URLS}).
     *
     * @return list<object> unique wire events by id
     */
    public function fetchHighlightEventsForArticle(string $coordinate): array
    {
        $parts = explode(':', $coordinate, 3);
        if (\count($parts) < 3) {
            throw new \InvalidArgumentException('Invalid coordinate format, expected kind:pubkey:identifier');
        }
        $pubkey = $parts[1];

        $tRelays = microtime(true);
        $authorRelays = $this->authorRelayCache->getAuthorNip65RelaysList($pubkey);
        $this->logger->info('nostr.highlight_relay_list', [
            'elapsed_ms' => (int) round((microtime(true) - $tRelays) * 1000),
            'author_relay_count' => \count($authorRelays),
        ]);

        $baseArticle = $this->relayListFactory->getSearchRelayUrlList();
        $profileConfigured = $this->relayListFactory->getProfileRelayUrlList();
        $mergedForDiscussion = $this->relayListFactory->withAggrNostrLandIfUserSubscribesNostrLand(
            array_merge($baseArticle, $profileConfigured, $authorRelays)
        );
        $plannedRelayUrls = array_values(array_unique($mergedForDiscussion, \SORT_REGULAR));
        $relayCountBeforeCap = \count($plannedRelayUrls);
        if ($relayCountBeforeCap > self::MAX_HIGHLIGHT_RELAY_URLS) {
            $this->logger->notice('nostr.highlight_relay_cap', [
                'max' => self::MAX_HIGHLIGHT_RELAY_URLS,
                'had' => $relayCountBeforeCap,
            ]);
            $plannedRelayUrls = \array_slice($plannedRelayUrls, 0, self::MAX_HIGHLIGHT_RELAY_URLS);
        }
        $limH = 200;
        $filters = [];
        $f = new Filter();
        $f->setKinds([KindsEnum::HIGHLIGHTS->value]);
        $f->setTag('#a', [$coordinate]);
        $f->setLimit($limH);
        $filters[] = $f;
        $f = new Filter();
        $f->setKinds([KindsEnum::HIGHLIGHTS->value]);
        $f->setTag('#A', [$coordinate]);
        $f->setLimit($limH);
        $filters[] = $f;

        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $requestMessage = new RequestMessage($subscriptionId, $filters);

        $this->logger->info('nostr.highlight_req', [
            'subscription_id' => $subscriptionId,
            'coordinate' => $coordinate,
            'relay_count' => \count($plannedRelayUrls),
        ]);

        try {
            if (!\is_file($this->projectDir.'/bin/nostr_relay_request_worker.php') || \count($plannedRelayUrls) <= 1) {
                $forSeq = $this->relayFanout->capUrlsForSequential($plannedRelayUrls);
                $response = $this->relayTransport->sendToUrls($forSeq, $requestMessage);
            } else {
                try {
                    $response = $this->relayTransport->sendParallelToUrls($plannedRelayUrls, $requestMessage);
                } catch (\Throwable $e) {
                    $this->logger->warning('nostr.highlight.parallel_failed', [
                        'message' => $e->getMessage(),
                        'exception_class' => \get_class($e),
                    ]);
                    $forSeq = $this->relayFanout->capUrlsForSequential($plannedRelayUrls);
                    $response = $this->relayTransport->sendToUrls($forSeq, $requestMessage);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('nostr.highlight_req_failed: '.$e->getMessage(), [
                'coordinate' => $coordinate,
            ]);
            throw new \RuntimeException('Nostr request failed for highlights', 0, $e);
        }

        $byId = [];
        $this->nostrRelayQuery->processResponse($response, function ($event) use (&$byId) {
            if (\is_object($event) && isset($event->id) && (int) ($event->kind ?? 0) === KindsEnum::HIGHLIGHTS->value) {
                $byId[(string) $event->id] = $event;
            }

            return null;
        });

        $this->logger->info('nostr.highlight_done', ['count' => \count($byId)]);

        return array_values($byId);
    }

    /**
     * Same merge/dedupe rules as {@see NostrRelayListFactory::createRelaySetMergedWithArticleList()} — used only for logging planned relay URLs.
     *
     * @param array<int, string> $relayUrls
     *
     * @return list<string>
     */
    private function plannedRelayUrlsForSet(array $relayUrls): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($this->relayListFactory->getSearchRelayUrlList(), $relayUrls) as $relayUrl) {
            if ($relayUrl === '' || isset($seen[$relayUrl])) {
                continue;
            }
            $seen[$relayUrl] = true;
            $out[] = $relayUrl;
        }

        return $out;
    }

    /**
     * Get zap events for a specific event
     *
     * @param string $coordinate The event coordinate (kind:pubkey:identifier)
     * @return array Array of zap events
     * @throws \Exception
     */
    public function getZapsForEvent(string $coordinate): array
    {
        $this->logger->info('Getting zaps for coordinate', ['coordinate' => $coordinate]);

        // Parse the coordinate to get pubkey
        $parts = explode(':', $coordinate);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid coordinate format, expected kind:pubkey:identifier');
        }
        $pubkey = $parts[1];

        // Get author's relays for better chances of finding zaps
        $authorRelays = $this->authorRelayCache->getTopReputableRelaysForAuthor($pubkey);
        $zapRelayUrls = $this->relayListFactory->mergeSearchRelayUrlList($authorRelays);
        $relaySet = $this->relayListFactory->createRelaySetMergedWithArticleList($authorRelays);

        // Create request using the helper method
        // Zaps are kind 9735
        $request = $this->nostrRelayQuery->createNostrRequest(
            defaultRelaySet: $this->searchRelaySet,
            kinds: [KindsEnum::ZAP],
            filters: ['tag' => ['#a', [$coordinate]]],
            relaySet: $relaySet,
        );

        // Process the response
        return $this->nostrRelayQuery->processResponse(
            $this->nostrRelayQuery->sendCreatedRequest($request, $zapRelayUrls),
            function ($event) {
            $this->logger->debug('Received zap event', ['event_id' => $event->id]);

            return $event;
        },
        );
    }

    public function getArticles(array $slugs): array
    {
        $articles = [];
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds(KindsEnum::longformKindValues());
        $filter->setTag('#d', $slugs);
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        try {
            $response = $this->nostrRelayQuery->sendToUrls($this->communityRelayUrls, $requestMessage);
        } catch (\Exception $e) {
            $relaysTried = $this->relayListFactory->getCommunityRelayUrlList();
            $relaysStr = implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $relaysTried));
            $this->logger->error(sprintf('Error querying relays (%s): %s', $relaysStr, $e->getMessage()), [
                'error' => $e->getMessage(),
                'relays' => $relaysTried,
            ]);

            return [];
        }

        foreach ($response as $relayUrl => $value) {
            if ($value instanceof \Throwable) {
                $this->logger->warning(sprintf(
                    '[%s] getArticles: %s',
                    NostrRelayQuery::relayLogLabel($relayUrl),
                    $value->getMessage()
                ), ['relay' => $relayUrl]);

                continue;
            }
            if (!\is_iterable($value)) {
                continue;
            }
            foreach ($value as $item) {
                if ($item->type === 'EVENT') {
                    if (!isset($articles[$item->event->id])) {
                        $articles[$item->event->id] = $item->event;
                    }
                }
            }
        }

        return $this->wireMerge->mergeNip33ParameterizedWireEvents(array_values($articles));
    }

    /**
     * Fetch articles by coordinates (kind:author:slug)
     * Returns a map of coordinate => event for successful fetches
     *
     * @param array $coordinates Array of coordinates in format kind:author:slug
     * @return array Map of coordinate => event
     * @throws \Exception
     */
    public function getArticlesByCoordinates(array $coordinates): array
    {
        $articlesMap = [];

        foreach ($coordinates as $coordinate) {
            $parts = explode(':', $coordinate);

            if (count($parts) !== 3) {
                $this->logger->warning('Invalid coordinate format', ['coordinate' => $coordinate]);
                continue;
            }

            $kind = (int)$parts[0];
            $pubkey = $parts[1];
            $slug = $parts[2];

            // Try to get relays associated with the author first
            $relayList = [];
            try {
                // Get relays where the author publishes
                $authorRelays = $this->authorRelayCache->getTopReputableRelaysForAuthor($pubkey);
                if (!empty($authorRelays)) {
                    $relayList = $authorRelays;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get author relays', [
                    'pubkey' => $pubkey,
                    'error' => $e->getMessage()
                ]);
                // Continue with default relays
            }

            if (empty($relayList)) {
                $relayList = [];
            }

            // Create subscription and filter
            $subscription = new Subscription();
            $subscriptionId = $subscription->setId();
            $filter = new Filter();
            $filter->setKinds([$kind]);
            $filter->setAuthors([$pubkey]);
            $filter->setTag('#d', [$slug]);
            $requestMessage = new RequestMessage($subscriptionId, [$filter]);
            $relaysForLog = $this->plannedRelayUrlsForSet($relayList);
            $relaysLogStr = implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $relaysForLog));

            try {
                $events = $this->nostrRelayQuery->processResponse(
                    $this->nostrRelayQuery->sendToUrls($relaysForLog, $requestMessage),
                    static fn (object $event) => $event,
                );
                $ev = $this->wireMerge->pickEventForNip33OrFirst($events, $kind, (string) $pubkey, (string) $slug);
                if ($ev !== null) {
                    $articlesMap[$coordinate] = $ev;
                }

                if (!isset($articlesMap[$coordinate])) {
                    $this->logger->info('Article not found in author relays, trying default relays', [
                        'coordinate' => $coordinate,
                    ]);
                    $events2 = $this->nostrRelayQuery->processResponse(
                        $this->nostrRelayQuery->sendToUrls($this->searchRelayUrls, $requestMessage),
                        static fn (object $event) => $event,
                    );
                    $ev2 = $this->wireMerge->pickEventForNip33OrFirst($events2, $kind, (string) $pubkey, (string) $slug);
                    if ($ev2 !== null) {
                        $articlesMap[$coordinate] = $ev2;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Error fetching article (relays: %s): %s',
                    $relaysLogStr,
                    $e->getMessage()
                ), [
                    'coordinate' => $coordinate,
                    'error' => $e->getMessage(),
                    'relays' => $relaysForLog,
                ]);
            }
        }

        return $articlesMap;
    }

    /**
     * @param Article $article
     * @return void
     */
    public function saveEachArticleToTheDatabase(Article $article): void
    {
        $this->longformArticleIngest->ingest($article);
    }

    /**
     * @param mixed $descriptor
     * @return Event|null
     */
    public function getEventFromDescriptor(mixed $descriptor): ?\stdClass
    {
        // Descriptor is an stdClass with properties: type and decoded
        if (is_object($descriptor) && isset($descriptor->type, $descriptor->decoded)) {
            // construct a request from the descriptor to fetch the event
            $data = json_decode($descriptor->decoded);
            if (!\is_object($data)) {
                $this->logger->error('Invalid descriptor decoded JSON', ['descriptor' => $descriptor]);

                return null;
            }

            $byEventId = isset($data->id) && \is_string($data->id) && $data->id !== '';

            if ($byEventId) {
                // NIP-01: filter by "ids", not "#e" (which matches *tags* named "e").
                $kind = isset($data->kind) ? (int) $data->kind : 1;
                $request = $this->nostrRelayQuery->createNostrRequest(
                    defaultRelaySet: $this->searchRelaySet,
                    kinds: [$kind],
                    filters: ['ids' => [$data->id]],
                    relaySet: $this->searchRelaySet
                );
            } else {
                // Replaceable address (naddr): must filter on #d like {@see getEventByNaddr()}.
                // Using key "d" does not call Filter::setTag — relays then return any kind match for the author.
                $pubkey = (string) ($data->pubkey ?? '');
                $identifier = (string) ($data->identifier ?? '');
                if ($pubkey === '' || $identifier === '') {
                    $this->logger->warning('Naddr descriptor missing pubkey or identifier', ['data' => $data]);

                    return null;
                }
                $kind = (int) ($data->kind ?? KindsEnum::LONGFORM->value);
                $request = $this->nostrRelayQuery->createNostrRequest(
                    defaultRelaySet: $this->searchRelaySet,
                    kinds: [$kind],
                    filters: [
                        'authors' => [$pubkey],
                        'tag' => ['#d', [$identifier]],
                    ],
                    relaySet: $this->searchRelaySet
                );
            }

            $events = $this->nostrRelayQuery->processResponse(
                $this->nostrRelayQuery->sendCreatedRequest($request, $this->searchRelayUrls),
                function ($received) {
                $this->logger->info('Getting event', ['item' => $received]);

                return $received;
            },
            );

            if (empty($events)) {
                $this->logger->warning('No events found for descriptor', ['descriptor' => $descriptor]);

                return null;
            }

            if ($byEventId) {
                foreach ($events as $event) {
                    if (isset($event->id) && $event->id === $data->id) {
                        return $event;
                    }
                }

                return $events[0];
            }

            $wantD = (string) ($data->identifier ?? '');
            $kindI = (int) ($data->kind ?? KindsEnum::LONGFORM->value);
            $authorH = $this->wireMerge->authorIdentToHexLower($data->pubkey ?? null);
            if ($this->wireMerge->isNip33ParameterizedKind($kindI) && $authorH !== null) {
                $picked = $this->wireMerge->pickLatestNip33ParameterizedForQuery($events, $kindI, $authorH, $wantD);
                if ($picked !== null) {
                    return $picked;
                }
            }
            foreach ($events as $event) {
                if ($this->eventHasDTag($event, $wantD)) {
                    return $event;
                }
            }

            return $events[0];
        } else {
            $this->logger->error('Invalid descriptor format', ['descriptor' => $descriptor]);
            return null;
        }
    }

    private function eventHasDTag(object $event, string $identifier): bool
    {
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            if (($tag[0] ?? '') === 'd' && (string) ($tag[1] ?? '') === $identifier) {
                return true;
            }
        }

        return false;
    }

    /**
     * Latest kind 30040 index for this author and #d tag, as {@see PublicationEventEntity}
     * so callers can use {@see PublicationEventEntity::getTags()} (relay payloads are otherwise stdClass).
     *
     * The magazine root uses the site d_tag from config. Each category uses the full child d
     * (third segment of the root "a" address). A category 30040 lists 30023/30024 article "a" tags
     * and may also list nested kind-30040 section indices.
     *
     * Tries search_relays first; if no 30040 is found, retries on config `profile_relays` not
     * already listed in search_relays (see prewarm / category discovery).
     */
    public function getMagazineIndex(mixed $npub, mixed $dTag, ?int $relayTimeoutSec = null): ?PublicationEventEntity
    {
        $urls = $this->relayListFactory->getSearchRelayUrlList();
        $relaysForLog = implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $urls));
        $result = $this->queryMagazineIndex($npub, $dTag, $urls, $relaysForLog, $relayTimeoutSec);
        if ($result !== null) {
            return $result;
        }
        $profileExtra = $this->relayListFactory->getProfileRelayUrlsExcludedFromSearchRelays();
        if ($profileExtra === []) {
            return null;
        }
        $relaysForLog2 = implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $profileExtra)).' (profile_relays)';

        return $this->queryMagazineIndex($npub, $dTag, $profileExtra, $relaysForLog2, $relayTimeoutSec);
    }

    /**
     * @param list<string> $relayUrls
     */
    private function queryMagazineIndex(
        mixed $npub,
        mixed $dTag,
        array $relayUrls,
        string $relaysForLog,
        ?int $relayTimeoutSec = null,
    ): ?PublicationEventEntity {
        $authorHex = $this->wireMerge->npubToHexPubkey($npub);
        if ($authorHex === null) {
            $this->logger->warning('Magazine index: could not resolve npub to hex pubkey', [
                'npub' => $npub,
                'dTag' => $dTag,
            ]);

            return null;
        }
        $request = $this->nostrRelayQuery->createNostrRequest(
            defaultRelaySet: $this->searchRelaySet,
            relaySet: $this->relayListFactory->relaySetFromDistinctUrlList($relayUrls),
            kinds: [KindsEnum::PUBLICATION_INDEX],
            filters: ['authors' => [$authorHex], 'tag' => ['#d', [(string) $dTag]]],
            relayTimeoutSec: $relayTimeoutSec,
        );
        $this->logger->info(sprintf('Magazine index query (relays: %s)', $relaysForLog), [
            'npub' => $npub,
            'dTag' => $dTag,
            'relays' => $relaysForLog,
        ]);
        $response = $this->nostrRelayQuery->sendCreatedRequest($request, $relayUrls);
        $events = $this->nostrRelayQuery->processResponse($response, function ($received) {
            return $received;
        });
        if (empty($events)) {
            return null;
        }
        $raw = $this->wireMerge->pickLatestNip33ParameterizedForQuery(
            $events,
            KindsEnum::PUBLICATION_INDEX->value,
            $authorHex,
            (string) $dTag
        );
        if ($raw === null) {
            $this->logger->warning('Magazine index: no event matched NIP-33 address (kind:pubkey:d) after merge', [
                'npub' => $npub,
                'dTag' => $dTag,
                'relays' => $relaysForLog,
                'event_count' => \count($events),
            ]);

            return null;
        }

        return $this->wireMerge->magazineEventToPublicationEntity($raw);
    }

    /**
     * Single long-form coordinate on config profile relays only (not already in search_relays).
     */
    private function tryFetchLongformCoordinateOnProfileRelays(string $coordinate): ?object
    {
        $extra = $this->relayListFactory->getProfileRelayUrlsExcludedFromSearchRelays();
        if ($extra === []) {
            return null;
        }
        $parts = explode(':', $coordinate, 3);
        if (\count($parts) !== 3) {
            return null;
        }
        $kind = (int) $parts[0];
        $pubkey = strtolower($parts[1]);
        $slug = trim((string) $parts[2]);
        $kindEnum = KindsEnum::tryFrom($kind);
        if ($kindEnum === null || $pubkey === '' || $slug === '') {
            return null;
        }
        try {
            $pfSet = $this->relayListFactory->createRelaySetFromUrlsOnly($extra);
            $request = $this->nostrRelayQuery->createNostrRequest(
                defaultRelaySet: $this->searchRelaySet,
                relaySet: $pfSet,
                kinds: [$kindEnum],
                filters: ['authors' => [$pubkey], 'tag' => ['#d', [$slug]]],
            );
            $events = $this->nostrRelayQuery->processResponse(
                $this->nostrRelayQuery->sendCreatedRequest($request, $extra),
                static fn (object $event) => $event,
            );
            $ev = $this->wireMerge->pickEventForNip33OrFirst($events, $kind, $pubkey, $slug);
            if ($ev !== null) {
                return $ev;
            }
            $fallbackReq = $this->nostrRelayQuery->createNostrRequest(
                defaultRelaySet: $this->searchRelaySet,
                relaySet: $pfSet,
                kinds: [$kindEnum],
                filters: ['tag' => ['#d', [$slug]]],
            );
            $fallbackEvents = $this->nostrRelayQuery->processResponse(
                $this->nostrRelayQuery->sendCreatedRequest($fallbackReq, $extra),
                static fn (object $event) => $event,
            );
            $matched = [];
            foreach ($fallbackEvents as $ev2) {
                if (!\is_object($ev2)) {
                    continue;
                }
                if (strtolower((string) ($ev2->pubkey ?? '')) !== $pubkey) {
                    continue;
                }
                $d = $this->wireMerge->eventDTagValue($ev2);
                if ($d === null || trim((string) $d) !== $slug) {
                    continue;
                }
                $matched[] = $ev2;
            }

            return $matched === [] ? null : $this->wireMerge->pickEventForNip33OrFirst($matched, $kind, $pubkey, $slug);
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Batch-fetch latest longform for category `a` coordinates; one Nostr call per (author × kind)
     * group. Default relays: {@see $searchRelaySet} (site magazine categories). Pass
     * {@code $communityFeed = true} for publication section bodies ({@see community_relay}).
     *
     * After search_relays return nothing, retries use `profile_relays` not already in search_relays.
     *
     * @param list<string> $addresses kind:pubkey:identifier
     */
    public function ingestLongformForCategoryCoordinates(array $addresses, ?int $relayTimeoutSec = null, bool $communityFeed = false): void
    {
        if ($addresses === []) {
            $this->logger->info('[longform_ingest] ingestLongform: no addresses, exit');

            return;
        }
        $relayUrlList = $communityFeed
            ? $this->communityRelayUrls
            : $this->searchRelayUrls;
        $relaysForLog = implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $relayUrlList));
        $this->logger->info('[longform_ingest] ingestLongform: start', [
            'address_count' => \count($addresses),
            'relays' => $relaysForLog,
            'addresses_sample' => \array_slice($addresses, 0, 15),
        ]);
        $groups = [];
        foreach ($addresses as $c) {
            $parts = explode(':', (string) $c, 3);
            if (\count($parts) < 3) {
                $this->logger->notice('[longform_ingest] ingestLongform: skip malformed coordinate (not kind:pubkey:rest)', [
                    'coordinate' => $c,
                ]);

                continue;
            }
            $kind = (int) $parts[0];
            $pubkey = strtolower($parts[1]);
            $d = trim((string) $parts[2]);
            if ($d === '' || $kind <= 0) {
                continue;
            }
            $gkey = $pubkey.':'.(string) $kind;
            $groups[$gkey]['pubkey'] = $pubkey;
            $groups[$gkey]['kind'] = $kind;
            $groups[$gkey]['dTags'][] = $d;
        }
        $this->logger->info('[longform_ingest] ingestLongform: request groups (batched by author+kind)', [
            'group_count' => \count($groups),
        ]);
        foreach ($groups as $gkey => $g) {
            $dTags = array_values(array_unique($g['dTags']));
            $kindEnum = KindsEnum::tryFrom((int) $g['kind']);
            if ($kindEnum === null) {
                $this->logger->notice('[longform_ingest] skip group: unknown kind', ['kind' => $g['kind']]);

                continue;
            }
            $this->logger->info('[longform_ingest] ingestLongform: REQ group', [
                'group_key' => $gkey,
                'filter_kind' => (int) $g['kind'],
                'author_hex64_prefix' => substr((string) $g['pubkey'], 0, 12),
                'd_tag_count' => \count($dTags),
                'd_tags' => array_map(
                    fn (string $dt): string => $this->wireMerge->longformIngestShortSlug($dt, 72),
                    $dTags
                ),
            ]);
            $request = $this->nostrRelayQuery->createNostrRequest(
                defaultRelaySet: $this->searchRelaySet,
                kinds: [$kindEnum],
                filters: ['authors' => [(string) $g['pubkey']], 'tag' => ['#d', $dTags]],
                relaySet: $this->relayListFactory->relaySetFromDistinctUrlList($relayUrlList),
                relayTimeoutSec: $relayTimeoutSec,
            );
            try {
                $events = $this->nostrRelayQuery->processResponse(
                    $this->nostrRelayQuery->sendCreatedRequest($request, $relayUrlList),
                    static fn (object $event) => $event,
                );
                $rawCount = \count($events);
                $rawSample = [];
                $si = 0;
                foreach ($events as $ev) {
                    if (!\is_object($ev)) {
                        continue;
                    }
                    if ($si < 25) {
                        $rawSample[] = $this->wireMerge->longformIngestEventWireSummary($ev);
                    }
                    ++$si;
                }
                $this->logger->info('[longform_ingest] ingestLongform: responses merged from relays (pre-NIP-33 per-address merge)', [
                    'raw_wire_count' => $rawCount,
                    'sample_up_to_25' => $rawSample,
                ]);
                if ($rawCount === 0) {
                    $this->logger->notice('[longform_ingest] ingestLongform: no EVENT rows returned for this filter — trying fallback queries', [
                        'group_key' => $gkey,
                        'authors_filter' => $g['pubkey'],
                    ]);
                    // Some relays fail to satisfy combined authors+#d filters for parameterized replaceables.
                    // Fallback: query by #d only, then enforce author and d-tag match client-side.
                    $fallbackReq = $this->nostrRelayQuery->createNostrRequest(
                        defaultRelaySet: $this->searchRelaySet,
                        kinds: [$kindEnum],
                        filters: ['tag' => ['#d', $dTags]],
                        relaySet: $this->relayListFactory->relaySetFromDistinctUrlList($relayUrlList),
                        relayTimeoutSec: $relayTimeoutSec,
                    );
                    $fallbackEvents = $this->nostrRelayQuery->processResponse(
                        $this->nostrRelayQuery->sendCreatedRequest($fallbackReq, $relayUrlList),
                        static fn (object $event) => $event,
                    );
                    $fallbackMatched = [];
                    $expectedPubkey = strtolower((string) $g['pubkey']);
                    $expectedD = array_fill_keys($dTags, true);
                    foreach ($fallbackEvents as $ev) {
                        if (!\is_object($ev)) {
                            continue;
                        }
                        $evPubkey = strtolower((string) ($ev->pubkey ?? ''));
                        if ($evPubkey !== $expectedPubkey) {
                            continue;
                        }
                        $evD = $this->wireMerge->eventDTagValue($ev);
                        if ($evD === null || !isset($expectedD[$evD])) {
                            continue;
                        }
                        $fallbackMatched[] = $ev;
                    }
                    $this->logger->info('[longform_ingest] ingestLongform: fallback #d-only query result', [
                        'group_key' => $gkey,
                        'fallback_raw_wire_count' => \count($fallbackEvents),
                        'fallback_matched_count' => \count($fallbackMatched),
                    ]);
                    if ($fallbackMatched !== []) {
                        $events = $fallbackMatched;
                        $rawCount = \count($events);
                    }
                }
                if ($rawCount === 0) {
                    $profileExtra = $this->relayListFactory->getProfileRelayUrlsExcludedFromSearchRelays();
                    if ($profileExtra !== []) {
                        $this->logger->info('[longform_ingest] ingestLongform: no rows on configured relays; trying profile_relays', [
                            'group_key' => $gkey,
                            'relays' => implode(', ', array_map(NostrRelayQuery::relayLogLabel(...), $profileExtra)),
                        ]);
                        $requestPf = $this->nostrRelayQuery->createNostrRequest(
                            defaultRelaySet: $this->searchRelaySet,
                            relaySet: $this->relayListFactory->relaySetFromDistinctUrlList($profileExtra),
                            kinds: [$kindEnum],
                            filters: ['authors' => [(string) $g['pubkey']], 'tag' => ['#d', $dTags]],
                            relayTimeoutSec: $relayTimeoutSec,
                        );
                        $events = $this->nostrRelayQuery->processResponse(
                            $this->nostrRelayQuery->sendCreatedRequest($requestPf, $profileExtra),
                            static fn (object $event) => $event,
                        );
                        $rawCount = \count($events);
                        if ($rawCount === 0) {
                            $fallbackPf = $this->nostrRelayQuery->createNostrRequest(
                                defaultRelaySet: $this->searchRelaySet,
                                relaySet: $this->relayListFactory->relaySetFromDistinctUrlList($profileExtra),
                                kinds: [$kindEnum],
                                filters: ['tag' => ['#d', $dTags]],
                                relayTimeoutSec: $relayTimeoutSec,
                            );
                            $fallbackEventsPf = $this->nostrRelayQuery->processResponse(
                                $this->nostrRelayQuery->sendCreatedRequest($fallbackPf, $profileExtra),
                                static fn (object $event) => $event,
                            );
                            $fallbackMatchedPf = [];
                            $expectedPubkeyPf = strtolower((string) $g['pubkey']);
                            $expectedDPf = array_fill_keys($dTags, true);
                            foreach ($fallbackEventsPf as $ev) {
                                if (!\is_object($ev)) {
                                    continue;
                                }
                                $evPubkey = strtolower((string) ($ev->pubkey ?? ''));
                                if ($evPubkey !== $expectedPubkeyPf) {
                                    continue;
                                }
                                $evD = $this->wireMerge->eventDTagValue($ev);
                                if ($evD === null || !isset($expectedDPf[$evD])) {
                                    continue;
                                }
                                $fallbackMatchedPf[] = $ev;
                            }
                            $this->logger->info('[longform_ingest] ingestLongform: profile_relays #d-only fallback', [
                                'group_key' => $gkey,
                                'fallback_raw_wire_count' => \count($fallbackEventsPf),
                                'fallback_matched_count' => \count($fallbackMatchedPf),
                            ]);
                            if ($fallbackMatchedPf !== []) {
                                $events = $fallbackMatchedPf;
                                $rawCount = \count($events);
                            }
                        }
                    }
                }
                $merged = $this->wireMerge->mergeNip33ParameterizedWireEvents($events);
                $mergedDetail = [];
                foreach ($merged as $ev) {
                    $mergedDetail[] = $this->wireMerge->longformIngestEventWireSummary($ev);
                }
                $this->logger->info('[longform_ingest] ingestLongform: after mergeNip33ParameterizedWireEvents', [
                    'merged_count' => \count($merged),
                    'one_row_per_nip33_address' => $mergedDetail,
                ]);
                $kindInt = (int) $g['kind'];
                $authorHex = strtolower((string) $g['pubkey']);
                $expectedAddresses = [];
                foreach ($dTags as $dt) {
                    $expectedAddresses[$kindInt.':'.$authorHex.':'.$dt] = true;
                }
                $seenAddresses = [];
                foreach ($merged as $event) {
                    $addr = $this->wireMerge->nip33ParameterizedReplaceableAddress($event);
                    if ($addr !== null) {
                        $seenAddresses[$addr] = true;
                    }
                    $article = $this->articleFactory->createFromLongFormContentEvent($event);
                    $this->saveEachArticleToTheDatabase($article);
                }
                foreach (array_keys($expectedAddresses) as $coordinate) {
                    if (isset($seenAddresses[$coordinate])) {
                        continue;
                    }
                    $this->logger->notice('[longform_ingest] ingestLongform: address missing after batch merge; trying author NIP-65 relays', [
                        'coordinate' => $coordinate,
                    ]);
                    $byCoord = $this->getArticlesByCoordinates([$coordinate]);
                    $evExtra = $byCoord[$coordinate] ?? null;
                    if ($evExtra === null) {
                        $evExtra = $this->tryFetchLongformCoordinateOnProfileRelays($coordinate);
                    }
                    if ($evExtra === null) {
                        $this->logger->warning('[longform_ingest] ingestLongform: still no event for coordinate (not on article, author, or profile relays)', [
                            'coordinate' => $coordinate,
                        ]);

                        continue;
                    }
                    $article = $this->articleFactory->createFromLongFormContentEvent($evExtra);
                    $this->saveEachArticleToTheDatabase($article);
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('[longform_ingest] ingestLongform: exception in group %s: %s', (string) $gkey, $e->getMessage()),
                    [
                        'message' => $e->getMessage(),
                        'pubkey' => $g['pubkey'],
                        'trace' => $e->getTraceAsString(),
                        'relays' => $relaysForLog,
                    ],
                );
            }
        }
        $this->logger->info('[longform_ingest] ingestLongform: done (all groups)');
    }
}
