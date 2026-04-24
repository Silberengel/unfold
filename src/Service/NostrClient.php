<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\User;
use App\Entity\Event as PublicationEventEntity;
use App\Enum\EventStatusEnum;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use nostriphant\NIP19\Data;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Key\Key;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class NostrClient
{
    /** Per-relay WebSocket I/O cap (seconds), applied on each relay’s {@see \WebSocket\Client}. */
    private const RELAY_REQUEST_TIMEOUT_SEC = 15;

    /** Extra wall time for {@see bin/nostr_relay_request_worker.php} process vs. WebSocket timeout. */
    private const DISCUSSION_WORKER_GRACE_SEC = 5.0;

    /**
     * Hard cap on unique relay URLs for article discussion. More relays do not help much (indexers duplicate)
     * but blow up wall time when we fall back to sequential in-process {@see Request::send()}.
     */
    private const MAX_DISCUSSION_RELAY_URLS = 10;

    /**
     * {@see Request::send()} hits relays sequentially; profile pages (metadata, long-form list, 10133) used
     * the full default+article+profile list (~8–9 wss) → 2 slow relays can exceed PHP’s 30s default max_execution_time.
     */
    private const MAX_PROFILE_SEQUENTIAL_RELAY_URLS = 3;

    /**
     * {@see sendArticleDiscussionToRelaysSequential} visits relays one after another (~RELAY_REQUEST_TIMEOUT_SEC
     * each). Keep this low so HTTP /fragment/comments and browsers do not hit 60–90s proxy cuts.
     */
    private const MAX_SEQUENTIAL_RELAY_URLS = 3;

    /** When a logged-in user lists this relay, also use {@see self::AGGR_NOSTR_LAND} for comment + profile reads. */
    private const NOSTR_LAND = 'wss://nostr.land';

    /**
     * Aggregated / subscription relay (not for anonymous visitors). Only added when the session user
     * has {@see self::NOSTR_LAND} in their NIP-65-style relay list.
     */
    private const AGGR_NOSTR_LAND = 'wss://aggr.nostr.land';

    private RelaySet $defaultRelaySet;

    /**
     * @param list<string> $articleRelayUrls extra relays for the default set (default_relay is always first)
     * @param list<string> $profileRelayUrls  kind-0 / profile; merged for metadata (see {@see profileMetadataQueryRelayUrlList()})
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ArticleFactory $articleFactory,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
        private readonly string $defaultRelayUrl,
        private readonly array $articleRelayUrls,
        private readonly array $profileRelayUrls,
        private readonly CacheInterface $relayQueryCache,
        private readonly string $projectDir,
    ) {
        $this->defaultRelaySet = $this->buildArticleRelaySet();
    }

    /**
     * default_relay + article_relays (deduplicated) for publishing user comments.
     *
     * @return list<string>
     */
    public function getArticleWriteRelayUrls(): array
    {
        return $this->configuredArticleRelayUrlList();
    }

    /**
     * Relays to publish a kind-1111 reply: site defaults plus NIP-65 (kind-10002) for the
     * article author and, when the direct parent is another pubkey (nested comment), that
     * author’s relays as well.
     *
     * @param string $articleCoordinate         kind:pubkey:identifier
     * @param string $parentEventAuthorHex      64-char hex of the event being replied to
     *
     * @return list<string>
     */
    public function getRelayUrlsForCommentPublish(string $articleCoordinate, string $parentEventAuthorHex): array
    {
        $base = $this->configuredArticleRelayUrlList();
        $parts = explode(':', $articleCoordinate, 3);
        $articlePk = \count($parts) >= 2 ? strtolower((string) $parts[1]) : '';
        if (64 !== \strlen($articlePk) || !ctype_xdigit($articlePk)) {
            $articlePk = '';
        }
        $parentPk = strtolower(trim($parentEventAuthorHex));
        if (64 !== \strlen($parentPk) || !ctype_xdigit($parentPk)) {
            $parentPk = '';
        }
        $pubkeys = [];
        if ($articlePk !== '') {
            $pubkeys[] = $articlePk;
        }
        if ($parentPk !== '' && $parentPk !== $articlePk) {
            $pubkeys[] = $parentPk;
        }

        $seen = array_fill_keys($base, true);
        $out = $base;
        foreach ($pubkeys as $pk) {
            foreach ($this->getAuthorNip65RelaysList($pk) as $wss) {
                if (!\is_string($wss) || $wss === '' || isset($seen[$wss])) {
                    continue;
                }
                $seen[$wss] = true;
                $out[] = $wss;
            }
        }

        return $out;
    }

    /**
     * default_relay + article_relays from config, in order, deduplicated. Used for the static
     * default set and as the base when merging author/extra relay URLs in {@see createRelaySet()}.
     *
     * @return list<string>
     */
    private function configuredArticleRelayUrlList(): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge([$this->defaultRelayUrl], $this->articleRelayUrls) as $url) {
            if (!\is_string($url) || $url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $url;
        }
        if ($out === []) {
            $out[] = $this->defaultRelayUrl;
        }

        return $out;
    }

    private function buildArticleRelaySet(): RelaySet
    {
        $relaySet = new RelaySet();
        foreach ($this->configuredArticleRelayUrlList() as $url) {
            $relaySet->addRelay(new Relay($url));
        }

        return $relaySet;
    }

    /**
     * Single-relay set for I/O that intentionally hits one wss (e.g. longform ingest). Magazine
     * 30040 resolution uses the full article relay set so all relays can contribute the latest
     * NIP-33 replaceable per address.
     */
    private function buildSingleRelaySet(string $wssUrl): RelaySet
    {
        $rs = new RelaySet();
        $rs->addRelay(new Relay($wssUrl));

        return $rs;
    }

    /**
     * Host (or full URL) for log messages so console output shows which relay without opening context.
     */
    private static function relayLogLabel(string $relayUrl): string
    {
        $host = parse_url($relayUrl, \PHP_URL_HOST);
        if (\is_string($host) && $host !== '') {
            return $host;
        }

        return $relayUrl;
    }

    private function newTimedRequest(RelaySet $relaySet, RequestMessage $requestMessage): Request
    {
        $request = new Request($relaySet, $requestMessage);
        // 1.9.4+: Request::setTimeout() drives getResponseFromRelay(). Older: only WebSocket client on Relay.
        if (method_exists($request, 'setTimeout')) {
            $request->setTimeout(self::RELAY_REQUEST_TIMEOUT_SEC);
        } else {
            $this->applyRelaySocketTimeoutToSet($relaySet);
        }

        return $request;
    }

    /**
     * Set per-relay WebSocket I/O cap. {@see RelaySet::send()} bypasses {@see Request}; use this there too.
     */
    private function applyRelaySocketTimeoutToSet(RelaySet $relaySet): void
    {
        foreach ($relaySet->getRelays() as $relay) {
            $client = $relay->getClient();
            if (method_exists($client, 'setTimeout')) {
                $client->setTimeout(self::RELAY_REQUEST_TIMEOUT_SEC);
            }
        }
    }

    /**
     * Merges all configured article relays (default + article_relays) with the given URLs in order, deduped.
     * Used for comment threads (getArticleDiscussion), per-author fetches, etc.
     */
    private function createRelaySet(array $relayUrls): RelaySet
    {
        $relaySet = new RelaySet();
        $seen = [];
        foreach (array_merge($this->configuredArticleRelayUrlList(), $relayUrls) as $relayUrl) {
            if (!\is_string($relayUrl) || $relayUrl === '' || isset($seen[$relayUrl])) {
                continue;
            }
            $seen[$relayUrl] = true;
            $relaySet->addRelay(new Relay($relayUrl));
        }

        return $relaySet;
    }

    /**
     * Suffix to segregate HTTP caches: aggr is only used for some logged-in readers, so results differ.
     *
     * @return string empty when aggr is not used, else a short token
     */
    public function getNostrLandAggrReaderCacheSuffix(): string
    {
        return $this->loggedInUserHasNostrLandInRelayList() ? 'a1' : '';
    }

    private function loggedInUserHasNostrLandInRelayList(): bool
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return false;
        }
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->userRelayListContainsNostrLand($user->getRelays());
    }

    /**
     * @param list<array{0?: string, 1?: string, 2?: string}>|array<array-key, mixed>|null $relays
     */
    private function userRelayListContainsNostrLand(?array $relays): bool
    {
        if ($relays === null || $relays === []) {
            return false;
        }
        $target = $this->normalizeWssUrlForNostrLandMatch(self::NOSTR_LAND);
        foreach ($relays as $row) {
            if (!\is_array($row) || !isset($row[1]) || !\is_string($row[1])) {
                continue;
            }
            if ($this->normalizeWssUrlForNostrLandMatch($row[1]) === $target) {
                return true;
            }
        }

        return false;
    }

    private function normalizeWssUrlForNostrLandMatch(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * Appends wss://aggr.nostr.land when the current user listed wss://nostr.land (session).
     *
     * @param list<string> $urls
     * @return list<string>
     */
    private function withAggrNostrLandIfUserSubscribesNostrLand(array $urls): array
    {
        if (!$this->loggedInUserHasNostrLandInRelayList()) {
            return $urls;
        }
        $seen = array_fill_keys($urls, true);
        if (isset($seen[self::AGGR_NOSTR_LAND])) {
            return $urls;
        }
        $this->logger->debug('nostr.relay.append_aggr_nostr_land', [
            'user_has_nostr_land' => true,
        ]);
        $out = $urls;
        $out[] = self::AGGR_NOSTR_LAND;

        return $out;
    }

    /**
     * @param list<string> $urls
     */
    private function relaySetFromDistinctUrlList(array $urls): RelaySet
    {
        $relaySet = new RelaySet();
        $seen = [];
        foreach ($urls as $relayUrl) {
            if (!\is_string($relayUrl) || $relayUrl === '' || isset($seen[$relayUrl])) {
                continue;
            }
            $seen[$relayUrl] = true;
            $relaySet->addRelay(new Relay($relayUrl));
        }

        return $relaySet;
    }

    /**
     * @param list<string> $urls
     *
     * @return list<string>
     */
    private function capSequentialRelaysForProfileFetches(array $urls): array
    {
        if (\count($urls) <= self::MAX_PROFILE_SEQUENTIAL_RELAY_URLS) {
            return $urls;
        }
        $this->logger->notice('nostr.relay_list_capped', [
            'context' => 'profile_sequential',
            'max' => self::MAX_PROFILE_SEQUENTIAL_RELAY_URLS,
            'had' => \count($urls),
        ]);

        return \array_values(\array_slice($urls, 0, self::MAX_PROFILE_SEQUENTIAL_RELAY_URLS));
    }

    /**
     * Full NIP-65 (kind-10002) wss:// list for a hex pubkey, cached. Used for comment fetches; prefer
     * {@see getTopReputableRelaysForAuthor} when you only need a few relays.
     *
     * @return list<string>
     */
    private function getAuthorNip65RelaysList(string $pubkey): array
    {
        $cacheKey = 'nostr_kind10002_relays_v1_'.hash('sha256', $pubkey);

        return $this->relayQueryCache->get($cacheKey, function (ItemInterface $item) use ($pubkey): array {
            $item->expiresAfter(3600);
            try {
                $authorRelays = $this->getNpubRelays($pubkey);
            } catch (\Exception $e) {
                $this->logger->error('Error getting author NIP-65 relay list', [
                    'pubkey' => $pubkey,
                    'error' => $e->getMessage(),
                ]);
                $authorRelays = [];
            }
            $authorRelays = array_values(array_filter(
                is_array($authorRelays) ? $authorRelays : [],
                static function ($relay): bool {
                    return \is_string($relay)
                        && str_starts_with($relay, 'wss:')
                        && !str_contains($relay, 'localhost');
                }
            ));
            if ($authorRelays === []) {
                return [];
            }
            $seen = [];
            $out = [];
            foreach ($authorRelays as $u) {
                if (isset($seen[$u])) {
                    continue;
                }
                $seen[$u] = true;
                $out[] = $u;
            }

            return $out;
        });
    }

    /**
     * A short prefix of the author NIP-65 list (or default relay) for queries that do not need every home relay.
     */
    private function getTopReputableRelaysForAuthor(string $pubkey, int $limit = 3): array
    {
        $all = $this->getAuthorNip65RelaysList($pubkey);
        if ($all === []) {
            return [$this->defaultRelayUrl];
        }
        if ($limit < 1) {
            $limit = 1;
        }

        return \array_values(\array_slice($all, 0, $limit));
    }

    /**
     * @return list<string> Deduplicated profile relay URLs from config
     */
    private function profileRelayUrlList(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->profileRelayUrls as $url) {
            if (!\is_string($url) || $url === '' || isset($seen[$url])) {
                continue;
            }
            if (!str_starts_with($url, 'wss:')) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $url;
        }

        return $out;
    }

    /**
     * Profile (kind-0) queries: {@see profileRelayUrlList()} first (Damus, nos.lol, …), then default + article set.
     * Order matters: {@see Request::send()} walks relays sequentially.
     *
     * @return list<string>
     */
    private function profileMetadataQueryRelayUrlList(): array
    {
        $seen = [];
        $ordered = [];
        foreach (array_merge($this->profileRelayUrlList(), $this->configuredArticleRelayUrlList()) as $u) {
            if (!\is_string($u) || $u === '' || isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $ordered[] = $u;
        }
        if ($ordered === []) {
            $ordered[] = $this->defaultRelayUrl;
        }

        return $this->withAggrNostrLandIfUserSubscribesNostrLand($ordered);
    }

    /**
     * Same relays for kind-0 metadata, without mutating {@see $this->defaultRelaySet}.
     */
    private function relaySetForProfileMetadataFetch(): RelaySet
    {
        $relaySet = new RelaySet();
        foreach ($this->profileMetadataQueryRelayUrlList() as $url) {
            $relaySet->addRelay(new Relay($url));
        }

        return $relaySet;
    }

    /**
     * NIP kind-range convention: kind 0, 3, and 10_000–19_999 are replaceable by (kind, pubkey) only;
     * 30_000–39_999 are addressable by (kind, pubkey, d). On equal {@see created_at}, the
     * lexicographically lowest id is kept.
     */
    private static function isReplaceableByKindAndPubkeyNip(int $kind): bool
    {
        return $kind === 0
            || $kind === 3
            || ($kind >= 10_000 && $kind < 20_000);
    }

    private static function replaceableKindPubkeyAddressFromWire(mixed $e): ?string
    {
        if (!\is_object($e)) {
            return null;
        }
        $k = (int) ($e->kind ?? 0);
        if (!self::isReplaceableByKindAndPubkeyNip($k)) {
            return null;
        }
        $pk = (string) ($e->pubkey ?? '');
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return null;
        }

        return (string) $k.':'.strtolower($pk);
    }

    private static function isValidNostrEventIdString(string $id): bool
    {
        return 64 === \strlen($id) && ctype_xdigit($id);
    }

    /**
     * Whether $candidate is the NIP-preferred live revision over $incumbent: higher created_at, or
     * same created_at and lower (lexicographically first) id. Events without a valid 64-hex id
     * lose to valid ones (avoids an empty id “winning” a tie and hiding real content).
     */
    private static function wireEventSupersedes(mixed $candidate, mixed $incumbent): bool
    {
        $c = self::magazineEventCreatedAt($candidate);
        $i = self::magazineEventCreatedAt($incumbent);
        if ($c !== $i) {
            return $c > $i;
        }
        $idC = self::magazineEventId($candidate);
        $idI = self::magazineEventId($incumbent);
        $vC = self::isValidNostrEventIdString($idC);
        $vI = self::isValidNostrEventIdString($idI);
        if ($vC && !$vI) {
            return true;
        }
        if (!$vC && $vI) {
            return false;
        }
        if (!$vC && !$vI) {
            if ($idC === $idI) {
                return false;
            }

            return $idC < $idI;
        }
        if ($idC === $idI) {
            return false;
        }

        return $idC < $idI;
    }

    /**
     * NIP-01: kind-0 profile metadata is replaceable; the live document is addressed by `0:pubkey`
     * (not by event id). Multiple relay copies collapse per {@see wireEventSupersedes}.
     */
    private static function kind0Nip01ReplaceableAddress(mixed $ev): ?string
    {
        if (!\is_object($ev) || (int) ($ev->kind ?? -1) !== KindsEnum::METADATA->value) {
            return null;
        }
        $pk = (string) ($ev->pubkey ?? '');
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return null;
        }

        return '0:'.strtolower($pk);
    }

    private static function kind0ReplaceableIsNewer(mixed $candidate, mixed $incumbent): bool
    {
        return self::wireEventSupersedes($candidate, $incumbent);
    }

    /**
     * @param list<mixed> $events
     *
     * @return array<string, object> Keyed by `0:` + 64 hex (lowercase); one winning kind-0 event per key
     */
    private static function mergeKind0EventsByReplaceableAddress(array $events): array
    {
        $byAddress = [];
        foreach ($events as $ev) {
            $addr = self::kind0Nip01ReplaceableAddress($ev);
            if ($addr === null) {
                continue;
            }
            if (!isset($byAddress[$addr]) || self::kind0ReplaceableIsNewer($ev, $byAddress[$addr])) {
                $byAddress[$addr] = $ev;
            }
        }

        return $byAddress;
    }

    /**
     * Batched kind-0 profile fetch: one Nostr REQ per chunk with multiple "authors" (hex pubkeys).
     *
     * @param list<string> $authorPubkeyHex
     * @return array<string, \stdClass> Newest kind-0 JSON per pubkey, keyed by hex
     */
    public function fetchKind0MetadataForAuthors(array $authorPubkeyHex, int $authorsPerRequest = 50): array
    {
        $authorPubkeyHex = \array_values(\array_unique(\array_filter(
            $authorPubkeyHex,
            static fn (mixed $h): bool => \is_string($h) && 64 === \strlen($h),
        )));
        if ($authorPubkeyHex === []) {
            return [];
        }
        $authorsPerRequest = max(1, min(200, $authorsPerRequest));
        $byPub = [];
        $relaysTried = $this->profileMetadataQueryRelayUrlList();
        $relaysTriedStr = implode(', ', array_map(self::relayLogLabel(...), $relaysTried));
        $relaySet = $this->relaySetForProfileMetadataFetch();
        $chunks = array_chunk($authorPubkeyHex, $authorsPerRequest);
        foreach ($chunks as $i => $chunk) {
            $t0 = microtime(true);
            $request = $this->createNostrRequest(
                kinds: [KindsEnum::METADATA],
                filters: ['authors' => $chunk],
                relaySet: $relaySet
            );
            $events = $this->processResponse(
                $request->send(),
                static fn ($ev) => $ev,
            );
            $this->logger->info('nostr.metadata.batch_chunk', [
                'chunk' => 1 + $i,
                'of' => \count($chunks),
                'authors' => \count($chunk),
                'events' => \count($events),
                'relays' => $relaysTriedStr,
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
            foreach (self::mergeKind0EventsByReplaceableAddress($events) as $addr => $ev) {
                if (!\is_object($ev) || !isset($ev->content)) {
                    continue;
                }
                $pk = \substr($addr, 2);
                try {
                    $data = \json_decode((string) $ev->content, false, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (\is_object($data)) {
                    $byPub[$pk] = $data;
                }
            }
        }

        return $byPub;
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
            static fn (mixed $h): bool => \is_string($h) && 64 === \strlen($h),
        )));
        if ($authorPubkeyHex === [] || $since >= $until) {
            return [];
        }
        $authorsPerRequest = max(1, min(100, $authorsPerRequest));
        $byId = [];
        $chunks = array_chunk($authorPubkeyHex, $authorsPerRequest);
        $numChunks = \count($chunks);
        foreach ($chunks as $i => $chunk) {
            $request = $this->createNostrRequest(
                kinds: [KindsEnum::DELETION_REQUEST],
                filters: [
                    'authors' => $chunk,
                    'since' => $since,
                    'until' => $until,
                ],
            );
            $t0 = microtime(true);
            $events = $this->processResponse(
                $request->send(),
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
     * @throws \Exception
     */
    public function getNpubMetadata($npub): \stdClass
    {
        $relaysTried = $this->capSequentialRelaysForProfileFetches($this->profileMetadataQueryRelayUrlList());
        $relaysTriedStr = implode(', ', array_map(self::relayLogLabel(...), $relaysTried));
        $relaySet = $this->relaySetFromDistinctUrlList($relaysTried);
        $this->logger->info(sprintf('Getting metadata for npub (relays: %s)', $relaysTriedStr), ['npub' => $npub, 'relays' => $relaysTried]);
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::METADATA],
            filters: ['authors' => [$npub]],
            relaySet: $relaySet
        );

        $events = $this->processResponse(
            $request->send(),
            function ($received) {
                $this->logger->debug('nostr.metadata.relay_event', ['event' => $received]);

                return $received;
            },
        );

        if (empty($events)) {
            throw new \Exception('No metadata for npub '.$npub.' (relays: '.$relaysTriedStr.')');
        }
        $byAddr = self::mergeKind0EventsByReplaceableAddress($events);
        $authorHex = self::npubToHexPubkey($npub);
        if ($authorHex === null) {
            throw new \Exception('Invalid npub for metadata: '.$npub);
        }
        $key = '0:'.$authorHex;
        if (!isset($byAddr[$key])) {
            throw new \Exception('No kind-0 metadata for npub '.$npub.' (relays: '.$relaysTriedStr.')');
        }

        return $byAddr[$key];
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
        $relaysTried = $this->capSequentialRelaysForProfileFetches($this->profileMetadataQueryRelayUrlList());
        $relaysTriedStr = implode(', ', array_map(self::relayLogLabel(...), $relaysTried));
        $relaySet = $this->relaySetFromDistinctUrlList($relaysTried);
        try {
            $request = $this->createNostrRequest(
                kinds: [KindsEnum::PAYMENT_TARGETS],
                filters: ['authors' => [$npub], 'limit' => max(1, min(50, $limit))],
                relaySet: $relaySet
            );
            $events = $this->processResponse(
                $request->send(),
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
        if (!\is_array($events) || $events === []) {
            return [];
        }

        return self::mergeNip33ParameterizedWireEvents($events);
    }

    public function getNpubLongForm($npub): void
    {
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([KindsEnum::LONGFORM]);
        $filter->setAuthors([$npub]);
        $filter->setSince(strtotime('-6 months')); // too much?
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        // if user is logged in, use their settings
        /* @var  $user */
        $user = $this->tokenStorage->getToken()?->getUser();
        $relays = $this->defaultRelaySet;
        if ($user && $user->getRelays()) {
            $relays = new RelaySet();
            foreach ($user->getRelays() as $relayArr) {
                if ($relayArr[2] == 'write') {
                    $relays->addRelay(new Relay($relayArr[1]));
                }
            }
        }

        $request = $this->newTimedRequest($relays, $requestMessage);

        $wrappers = $this->processResponse($request->send(), function (object $event) {
            $w = new \stdClass();
            $w->event = $event;

            return $w;
        });
        if ($wrappers !== []) {
            $this->saveLongFormContent($wrappers);
        }
        // TODO handle relays that require auth
    }

    public function publishEvent(Event $event, array $relays): array
    {
        $eventMessage = new EventMessage($event);
        $relaySet = new RelaySet();
        foreach ($relays as $relayWss) {
            $relay = new Relay($relayWss);
            $relaySet->addRelay($relay);
        }
        $relaySet->setMessage($eventMessage);
        $this->applyRelaySocketTimeoutToSet($relaySet);
        // TODO handle responses appropriately
        return $relaySet->send();
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
            $this->getLongFormContentForTimeWindow($windowFrom, $windowTo);
            $this->entityManager->clear();
        }
    }

    private function getLongFormContentForTimeWindow(int $since, int $until): void
    {
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([KindsEnum::LONGFORM]);
        $filter->setSince($since);
        $filter->setUntil($until);
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        $request = $this->newTimedRequest($this->defaultRelaySet, $requestMessage);

        $wrappers = $this->processResponse($request->send(), function (object $event) {
            $w = new \stdClass();
            $w->event = $event;

            return $w;
        });
        if ($wrappers !== []) {
            $this->saveLongFormContent($wrappers);
        }
    }

    /**
     * @throws \Exception
     */
    public function getLongFormFromNaddr($slug, $relayList, $author, $kind): void
    {
        if (empty($relayList)) {
            $topAuthorRelays = $this->getTopReputableRelaysForAuthor($author);
            $authorRelaySet = $this->createRelaySet($topAuthorRelays);
            $relaysTried = $this->plannedRelayUrlsForSet($topAuthorRelays);
        } else {
            $authorRelaySet = $this->createRelaySet($relayList);
            $relaysTried = $this->plannedRelayUrlsForSet($relayList);
        }
        $relaysTriedStr = implode(', ', array_map(self::relayLogLabel(...), $relaysTried));

        try {
            // Create request using the helper method for forest relay set
            $request = $this->createNostrRequest(
                kinds: [$kind],
                filters: [
                    'authors' => [$author],
                    'tag' => ['#d', [$slug]]
                ],
                relaySet: $authorRelaySet
            );

            // Process the response
            $events = $this->processResponse($request->send(), function($event) {
                return $event;
            });

            if (!empty($events)) {
                $kindI = (int) $kind;
                $authorH = self::authorIdentToHexLower($author);
                $event = self::isNip33ParameterizedKind($kindI) && $authorH !== null
                    ? self::pickLatestNip33ParameterizedForQuery($events, $kindI, $authorH, (string) $slug)
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

        // Use provided relays or default if empty
        $relaySet = empty($relays) ? $this->defaultRelaySet : $this->createRelaySet($relays);

        // Create request using the helper method
        $request = $this->createNostrRequest(
            kinds: [], // Leave empty to accept any kind
            filters: ['ids' => [$eventId]],
            relaySet: $relaySet
        );

        // Process the response
        $events = $this->processResponse($request->send(), function($event) {
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

        // Try author's relays first
        $authorRelays = empty($relays) ? $this->getTopReputableRelaysForAuthor($pubkey) : $relays;
        $relaySet = $this->createRelaySet($authorRelays);

        // Create request using the helper method
        $request = $this->createNostrRequest(
            kinds: [$kind],
            filters: [
                'authors' => [$pubkey],
                'tag' => ['#d', [$identifier]]
            ],
            relaySet: $relaySet
        );

        // Process the response
        $events = $this->processResponse($request->send(), function($event) {
            return $event;
        });

        if (!empty($events)) {
            return $events[0];
        }

        // Try default relays as fallback
        $request = $this->createNostrRequest(
            kinds: [$kind],
            filters: [
                'authors' => [$pubkey],
                'tag' => ['#d', [$identifier]]
            ]
        );

        $events = $this->processResponse($request->send(), function($event) {
            return $event;
        });

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
        foreach (self::mergeNip33ParameterizedWireEvents($events) as $event) {
            $article = $this->articleFactory->createFromLongFormContentEvent($event);
            $this->saveEachArticleToTheDatabase($article);
        }
    }

    /**
     * @throws \Exception
     */
    public function getNpubRelays($npub): array
    {
        // Get relays
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::RELAY_LIST],
            filters: ['authors' => [$npub]],
            relaySet: $this->defaultRelaySet
        );
        $response = $this->processResponse($request->send(), function($received) {
            return $received;
        });
        if (empty($response)) {
            return [];
        }
        $merged = self::mergeNip33ParameterizedWireEvents($response);
        $use = null;
        $k10002 = (int) KindsEnum::RELAY_LIST->value;
        foreach ($merged as $e) {
            if (\is_object($e) && (int) ($e->kind ?? 0) === $k10002) {
                $use = $e;
                break;
            }
        }
        if ($use === null) {
            return [];
        }
        $relays = [];
        foreach ($use->tags ?? [] as $tag) {
            if ($tag[0] === 'r') {
                $relays[] = $tag[1];
            }
        }
        // Remove duplicates, localhost and any non-wss relays
        return array_filter(array_unique($relays), function ($relay) {
            return str_starts_with($relay, 'wss:') && !str_contains($relay, 'localhost');
        });
    }

    /**
     * NIP-22 kind 1111 thread, legacy kind 1 replies (pre-NIP-22 clients), and quote/repost-style references.
     *
     * @param string               $coordinate      kind:pubkey:d-identifier (e.g. longform address)
     * @param null|string          $rootEventHexId  Published article event id (hex) for #e / #q matching
     *
     * @return array{thread: array<int, object>, quotes: array<int, object>}
     */
    public function getArticleDiscussion(string $coordinate, ?string $rootEventHexId = null): array
    {
        $this->logger->info('nostr.article_discussion.start', [
            'coordinate' => $coordinate,
            'root_event_hex' => $rootEventHexId,
        ]);

        $parts = explode(':', $coordinate, 3);
        if (\count($parts) < 3) {
            throw new \InvalidArgumentException('Invalid coordinate format, expected kind:pubkey:identifier');
        }
        $pubkey = $parts[1];

        $tRelays = microtime(true);
        $authorRelays = $this->getAuthorNip65RelaysList($pubkey);
        $this->logger->info('nostr.article_discussion.author_relays_ready', [
            'elapsed_ms' => (int) round((microtime(true) - $tRelays) * 1000),
            'author_relay_count' => \count($authorRelays),
        ]);

        $baseForDiscussion = $this->configuredArticleRelayUrlList();
        $mergedForDiscussion = $this->withAggrNostrLandIfUserSubscribesNostrLand(
            array_merge($baseForDiscussion, $authorRelays)
        );
        $plannedRelayUrls = array_values(array_unique($mergedForDiscussion, \SORT_REGULAR));
        if (\count($plannedRelayUrls) > self::MAX_DISCUSSION_RELAY_URLS) {
            $plannedRelayUrls = \array_slice($plannedRelayUrls, 0, self::MAX_DISCUSSION_RELAY_URLS);
            $this->logger->notice('nostr.article_discussion.relay_list_capped', [
                'max' => self::MAX_DISCUSSION_RELAY_URLS,
            ]);
        }

        $filters = $this->createArticleDiscussionFilters($coordinate, $rootEventHexId);
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $requestMessage = new RequestMessage($subscriptionId, $filters);

        $this->logger->info('nostr.article_discussion.req_sending', [
            'subscription_id' => $subscriptionId,
            'filter_count' => \count($filters),
            'relay_urls' => $plannedRelayUrls,
            'relay_count' => \count($plannedRelayUrls),
        ]);

        $byId = [];
        try {
            $tSend = microtime(true);
            $workerPath = $this->projectDir.'/bin/nostr_relay_request_worker.php';
            if (!\is_file($workerPath) || \count($plannedRelayUrls) <= 1) {
                $forSeq = $this->capRelayUrlsForSequentialPath($plannedRelayUrls);
                $response = $this->sendArticleDiscussionToRelaysSequential($forSeq, $requestMessage);
            } else {
                try {
                    $response = $this->sendArticleDiscussionToRelaysParallel($plannedRelayUrls, $requestMessage);
                } catch (\Throwable $e) {
                    $this->logger->warning('nostr.article_discussion.parallel_failed', [
                        'message' => $e->getMessage(),
                        'exception_class' => \get_class($e),
                    ]);
                    $forSeq = $this->capRelayUrlsForSequentialPath($plannedRelayUrls);
                    $this->logger->warning('nostr.article_discussion.sequential_fallback', [
                        'relays' => $forSeq,
                    ]);
                    $response = $this->sendArticleDiscussionToRelaysSequential($forSeq, $requestMessage);
                }
            }
            $sendMs = (int) round((microtime(true) - $tSend) * 1000);
            $this->logger->info('nostr.article_discussion.req_response_envelope', [
                'elapsed_ms' => $sendMs,
                'subscription_id' => $subscriptionId,
            ]);
            $this->logNostrWireResponseSummary('article_discussion', $response);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'nostr.article_discussion.req_send_failed (relays: %s): %s',
                implode(', ', array_map(self::relayLogLabel(...), $plannedRelayUrls)),
                $e->getMessage()
            ), [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
                'exception_class' => \get_class($e),
                'relays' => $plannedRelayUrls,
            ]);

            // Do not return a successful empty shape: callers (e.g. comment cache) must not
            // persist [] as if relays responded — that would clobber a previously good thread.
            throw new \RuntimeException('Nostr request failed for article discussion', 0, $e);
        }

        $tParse = microtime(true);
        $this->processResponse($response, function ($event) use (&$byId) {
            if (\is_object($event) && isset($event->id)) {
                $byId[(string) $event->id] = $event;
            }

            return null;
        });
        $this->logger->info('nostr.article_discussion.events_collected', [
            'elapsed_ms' => (int) round((microtime(true) - $tParse) * 1000),
            'unique_events' => \count($byId),
        ]);

        $all = array_values($byId);
        $thread = [];
        $threadIds = [];

        foreach ($all as $event) {
            $kind = (int) ($event->kind ?? 0);
            if ($kind === KindsEnum::COMMENTS->value && $this->eventIsNip22ArticleThreadReply($event, $coordinate)) {
                $thread[] = $event;
                $threadIds[(string) $event->id] = true;

                continue;
            }
            if ($kind === KindsEnum::TEXT_NOTE->value && $this->eventIsLegacyThreadReply($event, $coordinate, $rootEventHexId)) {
                $thread[] = $event;
                $threadIds[(string) $event->id] = true;
            }
        }

        $quotes = [];
        foreach ($all as $event) {
            $id = (string) ($event->id ?? '');
            if ($id === '' || isset($threadIds[$id])) {
                continue;
            }
            if ($this->eventIsArticleQuote($event, $coordinate, $rootEventHexId)) {
                $quotes[] = $event;
            }
        }

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
        ]);

        return ['thread' => $thread, 'quotes' => $quotes];
    }

    /**
     * @param list<string> $relayUrls
     *
     * @return list<string>
     */
    private function capRelayUrlsForSequentialPath(array $relayUrls): array
    {
        if (\count($relayUrls) <= self::MAX_SEQUENTIAL_RELAY_URLS) {
            return $relayUrls;
        }
        $this->logger->notice('nostr.article_discussion.sequential_relay_cap', [
            'used' => self::MAX_SEQUENTIAL_RELAY_URLS,
            'had' => \count($relayUrls),
        ]);

        return \array_values(\array_slice($relayUrls, 0, self::MAX_SEQUENTIAL_RELAY_URLS));
    }

    /**
     * One {@see Request} over all relays (library visits each wss:// in series).
     *
     * @param list<string> $relayUrls
     *
     * @return array<string, mixed> Same shape as {@see Request::send()} (relay url → message list)
     */
    private function sendArticleDiscussionToRelaysSequential(array $relayUrls, RequestMessage $requestMessage): array
    {
        $relaySet = $this->relaySetFromDistinctUrlList($relayUrls);
        $request = $this->newTimedRequest($relaySet, $requestMessage);

        return $request->send();
    }

    /**
     * One short-lived CLI worker per relay so WebSocket I/O is parallel (vs. a single in-process
     * {@see Request::send() loop).
     *
     * @param list<string> $relayUrls
     *
     * @return array<string, mixed> Same shape as {@see Request::send()}
     */
    private function sendArticleDiscussionToRelaysParallel(array $relayUrls, RequestMessage $requestMessage): array
    {
        $worker = $this->projectDir.'/bin/nostr_relay_request_worker.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $timeout = self::RELAY_REQUEST_TIMEOUT_SEC + (int) self::DISCUSSION_WORKER_GRACE_SEC;

        $rawPayload = serialize($requestMessage);
        $tmp = tempnam(sys_get_temp_dir(), 'nrq_');
        if ($tmp === false) {
            throw new \RuntimeException('tempnam failed for Nostr discussion payload');
        }
        try {
            if (file_put_contents($tmp, $rawPayload) === false) {
                throw new \RuntimeException('Could not write Nostr discussion temp payload');
            }

            /** @var array<string, Process> $procs */
            $procs = [];
            foreach ($relayUrls as $wss) {
                if (!\is_string($wss) || $wss === '') {
                    continue;
                }
                $p = new Process(
                    [$phpBinary, $worker, $wss, $tmp],
                    $this->projectDir,
                    null,
                    null,
                    (float) $timeout
                );
                $p->start();
                $procs[$wss] = $p;
            }

            $merged = [];
            foreach ($procs as $wss => $p) {
                $p->wait();
                if (!$p->isSuccessful()) {
                    $err = $p->getErrorOutput();
                    $this->logger->warning('nostr.article_discussion.relay_worker_failed', [
                        'relay' => $wss,
                        'exit_code' => $p->getExitCode(),
                        'stderr' => $err !== '' ? $err : null,
                    ]);
                    $merged[$wss] = [];

                    continue;
                }
                $out = trim($p->getOutput());
                if ($out === '') {
                    $merged[$wss] = [];

                    continue;
                }
                $decoded = base64_decode($out, true);
                if ($decoded === false || $decoded === '') {
                    $merged[$wss] = [];

                    continue;
                }
                $chunk = unserialize($decoded, ['allowed_classes' => true]);
                if (!\is_array($chunk)) {
                    $merged[$wss] = [];

                    continue;
                }
                $merged = array_replace($merged, $chunk);
            }

            return $merged;
        } finally {
            if (\is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Same merge/dedupe rules as {@see createRelaySet()} — used only for logging planned relay URLs.
     *
     * @param array<int, string> $relayUrls
     *
     * @return list<string>
     */
    private function plannedRelayUrlsForSet(array $relayUrls): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($this->configuredArticleRelayUrlList(), $relayUrls) as $relayUrl) {
            if (!\is_string($relayUrl) || $relayUrl === '' || isset($seen[$relayUrl])) {
                continue;
            }
            $seen[$relayUrl] = true;
            $out[] = $relayUrl;
        }

        return $out;
    }

    /**
     * One line per relay after {@see Request::send()}: errors vs message-type counts (EVENT, EOSE, …).
     *
     * @param array<string, mixed> $response
     */
    private function logNostrWireResponseSummary(string $context, array $response): void
    {
        foreach ($response as $relayUrl => $relayRes) {
            if ($relayRes instanceof \Throwable) {
                $this->logger->warning(sprintf(
                    'nostr.wire.relay_throwable [%s]: %s',
                    self::relayLogLabel($relayUrl),
                    $relayRes->getMessage()
                ), [
                    'context' => $context,
                    'relay' => $relayUrl,
                    'message' => $relayRes->getMessage(),
                    'class' => \get_class($relayRes),
                ]);

                continue;
            }
            if (!\is_iterable($relayRes)) {
                $this->logger->warning(sprintf(
                    'nostr.wire.relay_not_iterable [%s]: %s',
                    self::relayLogLabel($relayUrl),
                    \get_debug_type($relayRes)
                ), [
                    'context' => $context,
                    'relay' => $relayUrl,
                    'php_type' => \get_debug_type($relayRes),
                ]);

                continue;
            }
            $counts = [
                'EVENT' => 0,
                'EOSE' => 0,
                'NOTICE' => 0,
                'ERROR' => 0,
                'AUTH' => 0,
                'CLOSED' => 0,
                'other' => 0,
            ];
            foreach ($relayRes as $item) {
                if (!\is_object($item)) {
                    ++$counts['other'];

                    continue;
                }
                $t = (string) ($item->type ?? 'other');
                if (\array_key_exists($t, $counts)) {
                    ++$counts[$t];
                } else {
                    ++$counts['other'];
                }
            }
            $this->logger->info(sprintf('nostr.wire.relay_messages [%s]', self::relayLogLabel($relayUrl)), [
                'context' => $context,
                'relay' => $relayUrl,
                'counts' => $counts,
            ]);
        }
    }

    private function eventIsNip22ArticleThreadReply(object $event, string $coordinate): bool
    {
        if ((int) ($event->kind ?? 0) !== KindsEnum::COMMENTS->value) {
            return false;
        }
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            $name = (string) ($tag[0] ?? '');
            if (($name === 'a' || $name === 'A') && (string) ($tag[1] ?? '') === $coordinate) {
                return true;
            }
        }

        return false;
    }

    private function eventIsLegacyThreadReply(object $event, string $coordinate, ?string $rootEventHexId): bool
    {
        if ((int) ($event->kind ?? 0) !== KindsEnum::TEXT_NOTE->value) {
            return false;
        }
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            $name = (string) ($tag[0] ?? '');
            $val = (string) ($tag[1] ?? '');
            if (($name === 'a' || $name === 'A') && $val === $coordinate) {
                return true;
            }
            if ($rootEventHexId !== null && $rootEventHexId !== '' && $name === 'e' && $val === $rootEventHexId) {
                return true;
            }
        }

        return false;
    }

    private function eventIsArticleQuote(object $event, string $coordinate, ?string $rootEventHexId): bool
    {
        $kind = (int) ($event->kind ?? 0);
        if ($kind === KindsEnum::COMMENTS->value) {
            foreach ($event->tags ?? [] as $tag) {
                if (!\is_array($tag) || \count($tag) < 2) {
                    continue;
                }
                if (($tag[0] ?? '') === 'q') {
                    $val = (string) ($tag[1] ?? '');
                    if ($val === $coordinate || ($rootEventHexId !== null && $val === $rootEventHexId)) {
                        return true;
                    }
                }
            }

            return false;
        }
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            $name = (string) ($tag[0] ?? '');
            $val = (string) ($tag[1] ?? '');
            if ($name === 'q') {
                if ($val === $coordinate || ($rootEventHexId !== null && $val === $rootEventHexId)) {
                    return true;
                }
            }
        }
        if ($kind === KindsEnum::GENERIC_REPOST->value) {
            foreach ($event->tags ?? [] as $tag) {
                if (!\is_array($tag) || \count($tag) < 2) {
                    continue;
                }
                if (($tag[0] ?? '') === 'a' && (string) ($tag[1] ?? '') === $coordinate) {
                    return true;
                }
            }
        }
        if ($kind === KindsEnum::HIGHLIGHTS->value) {
            foreach ($event->tags ?? [] as $tag) {
                if (!\is_array($tag) || \count($tag) < 2) {
                    continue;
                }
                $n = (string) ($tag[0] ?? '');
                if (($n === 'a' || $n === 'A') && (string) ($tag[1] ?? '') === $coordinate) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, Filter>
     */
    private function createArticleDiscussionFilters(string $coordinate, ?string $rootEventHexId): array
    {
        $limThread = 100;
        $limQuote = 80;

        $filters = [];

        $k1111 = KindsEnum::COMMENTS->value;
        $f = new Filter();
        $f->setKinds([$k1111]);
        $f->setTag('#A', [$coordinate]);
        $f->setLimit($limThread);
        $filters[] = $f;
        $f = new Filter();
        $f->setKinds([$k1111]);
        $f->setTag('#a', [$coordinate]);
        $f->setLimit($limThread);
        $filters[] = $f;

        $k1 = KindsEnum::TEXT_NOTE->value;
        $f = new Filter();
        $f->setKinds([$k1]);
        $f->setTag('#A', [$coordinate]);
        $f->setLimit($limThread);
        $filters[] = $f;
        $f = new Filter();
        $f->setKinds([$k1]);
        $f->setTag('#a', [$coordinate]);
        $f->setLimit($limThread);
        $filters[] = $f;

        if ($rootEventHexId !== null && $rootEventHexId !== '') {
            $f = new Filter();
            $f->setKinds([$k1]);
            $f->setTag('#e', [$rootEventHexId]);
            $f->setLimit($limThread);
            $filters[] = $f;
        }

        $qKinds = [
            KindsEnum::TEXT_NOTE->value,
            KindsEnum::REPOST->value,
            KindsEnum::GENERIC_REPOST->value,
            KindsEnum::COMMENTS->value,
            KindsEnum::HIGHLIGHTS->value,
        ];
        $qVals = [$coordinate];
        if ($rootEventHexId !== null && $rootEventHexId !== '') {
            $qVals[] = $rootEventHexId;
        }
        $f = new Filter();
        $f->setKinds($qKinds);
        $f->setTag('#q', $qVals);
        $f->setLimit($limQuote);
        $filters[] = $f;

        $f = new Filter();
        $f->setKinds([KindsEnum::GENERIC_REPOST->value]);
        $f->setTag('#a', [$coordinate]);
        $f->setLimit(50);
        $filters[] = $f;

        $f = new Filter();
        $f->setKinds([KindsEnum::HIGHLIGHTS->value]);
        $f->setTag('#a', [$coordinate]);
        $f->setLimit(40);
        $filters[] = $f;
        $f = new Filter();
        $f->setKinds([KindsEnum::HIGHLIGHTS->value]);
        $f->setTag('#A', [$coordinate]);
        $f->setLimit(40);
        $filters[] = $f;

        return $filters;
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
        $authorRelays = $this->getTopReputableRelaysForAuthor($pubkey);
        $relaySet = $this->createRelaySet($authorRelays);

        // Create request using the helper method
        // Zaps are kind 9735
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::ZAP],
            filters: ['tag' => ['#a', [$coordinate]]],
            relaySet: $relaySet
        );

        // Process the response
        return $this->processResponse($request->send(), function($event) {
            $this->logger->debug('Received zap event', ['event_id' => $event->id]);
            return $event;
        });
    }

    /**
     * @throws \Exception
     */
    public function getLongFormContentForPubkey(string $ident): array
    {
        $authorRelays = $this->getTopReputableRelaysForAuthor($ident);
        $base = $this->configuredArticleRelayUrlList();
        $merged = $authorRelays !== [] ? array_merge($base, $authorRelays) : $base;
        $seen = [];
        $deduped = [];
        foreach ($merged as $url) {
            if (!\is_string($url) || $url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $deduped[] = $url;
        }
        $capped = $this->capSequentialRelaysForProfileFetches($deduped);
        $relaySet = $this->relaySetFromDistinctUrlList($capped);

        // Create request using the helper method
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::LONGFORM],
            filters: [
                'authors' => [$ident],
                'limit' => 10
            ],
            relaySet: $relaySet
        );

        $events = $this->processResponse(
            $request->send(),
            static fn (object $event) => $event,
        );
        foreach (self::mergeNip33ParameterizedWireEvents($events) as $event) {
            if (!\is_object($event)) {
                continue;
            }
            $article = $this->articleFactory->createFromLongFormContentEvent($event);
            $this->saveEachArticleToTheDatabase($article);
        }

        return [];
    }

    public function getArticles(array $slugs): array
    {
        $articles = [];
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([KindsEnum::LONGFORM]);
        $filter->setTag('#d', $slugs);
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        try {
            $request = $this->newTimedRequest($this->defaultRelaySet, $requestMessage);
            $response = $request->send();
            $hasEvents = false;

            // Check if we got any events
            foreach ($response as $relayUrl => $value) {
                if ($value instanceof \Throwable) {
                    $this->logger->warning(sprintf(
                        '[%s] getArticles: %s',
                        self::relayLogLabel($relayUrl),
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
                            $hasEvents = true;
                        }
                    }
                }
            }

            // If no articles found, try the default relay set
            if (!$hasEvents && !empty($slugs)) {
                $this->logger->info('No results from theforest, trying default relays');

                $request = $this->newTimedRequest($this->defaultRelaySet, $requestMessage);
                $response = $request->send();

                foreach ($response as $relayUrl => $value) {
                    if ($value instanceof \Throwable) {
                        $this->logger->warning(sprintf(
                            '[%s] getArticles: %s',
                            self::relayLogLabel($relayUrl),
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
                        } elseif (in_array($item->type, ['AUTH', 'ERROR', 'NOTICE'], true)) {
                            $msg = (string) ($item->message ?? '');
                            $this->logger->error(sprintf(
                                '[%s] %s while getting articles: %s',
                                self::relayLogLabel($relayUrl),
                                $item->type,
                                $msg !== '' ? $msg : '(no message)'
                            ), ['relay' => $relayUrl, 'response' => $item]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $relaysTried = $this->configuredArticleRelayUrlList();
            $relaysStr = implode(', ', array_map(self::relayLogLabel(...), $relaysTried));
            $this->logger->error(sprintf('Error querying relays (%s): %s', $relaysStr, $e->getMessage()), [
                'error' => $e->getMessage(),
                'relays' => $relaysTried,
            ]);

            // Fall back to default relay set
            $request = $this->newTimedRequest($this->defaultRelaySet, $requestMessage);
            $response = $request->send();

            foreach ($response as $relayUrl => $value) {
                if ($value instanceof \Throwable) {
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
        }

        return self::mergeNip33ParameterizedWireEvents(array_values($articles));
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
                $authorRelays = $this->getTopReputableRelaysForAuthor($pubkey);
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

            // Ensure we use a RelaySet
            $relaySet = $this->createRelaySet($relayList);

            // Create subscription and filter
            $subscription = new Subscription();
            $subscriptionId = $subscription->setId();
            $filter = new Filter();
            $filter->setKinds([$kind]);
            $filter->setAuthors([$pubkey]);
            $filter->setTag('#d', [$slug]);
            $requestMessage = new RequestMessage($subscriptionId, [$filter]);
            $relaysForLog = $this->plannedRelayUrlsForSet($relayList);
            $relaysLogStr = implode(', ', array_map(self::relayLogLabel(...), $relaysForLog));

            try {
                $request = $this->newTimedRequest($relaySet, $requestMessage);
                $events = $this->processResponse(
                    $request->send(),
                    static fn (object $event) => $event,
                );
                $ev = $this->pickEventForNip33OrFirst($events, $kind, (string) $pubkey, (string) $slug);
                if ($ev !== null) {
                    $articlesMap[$coordinate] = $ev;
                }

                if (!isset($articlesMap[$coordinate])) {
                    $this->logger->info('Article not found in author relays, trying default relays', [
                        'coordinate' => $coordinate
                    ]);
                    $request2 = $this->newTimedRequest($this->defaultRelaySet, $requestMessage);
                    $events2 = $this->processResponse(
                        $request2->send(),
                        static fn (object $event) => $event,
                    );
                    $ev2 = $this->pickEventForNip33OrFirst($events2, $kind, (string) $pubkey, (string) $slug);
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

    private function createNostrRequest(array $kinds, array $filters = [], ?RelaySet $relaySet = null): Request
    {
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds($kinds);

        foreach ($filters as $key => $value) {
            $method = 'set' . ucfirst($key);
            if (method_exists($filter, $method)) {
                // If it's tags, we need to handle it differently
                if ($key === 'tag') {
                   $filter->setTag($value[0], $value[1]);
                } else {
                    // Call the method with the value
                    $filter->$method($value);
                }
            }
        }

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        return $this->newTimedRequest($relaySet ?? $this->defaultRelaySet, $requestMessage);
    }

    private function processResponse(array $response, callable $eventHandler): array
    {
        $results = [];
        foreach ($response as $relayUrl => $relayRes) {
            if ($relayRes instanceof \Throwable) {
                $this->logger->error(sprintf(
                    'Relay error at %s: %s',
                    self::relayLogLabel($relayUrl),
                    $relayRes->getMessage()
                ), [
                    'relay' => $relayUrl,
                    'error' => $relayRes->getMessage(),
                ]);
                continue;
            }

            $itemEstimate = \is_countable($relayRes) ? \count($relayRes) : null;
            $this->logger->debug(sprintf('Processing relay response from %s', self::relayLogLabel($relayUrl)), [
                'relay' => $relayUrl,
                'item_count' => $itemEstimate,
            ]);

            foreach ($relayRes as $item) {
                try {
                    if (!is_object($item)) {
                        $this->logger->warning(sprintf(
                            'Invalid response item from %s',
                            self::relayLogLabel($relayUrl)
                        ), [
                            'relay' => $relayUrl,
                            'item' => $item,
                        ]);
                        continue;
                    }

                    switch ($item->type) {
                        case 'EVENT':
                            $this->logger->debug(sprintf('Processing event from %s', self::relayLogLabel($relayUrl)), [
                                'relay' => $relayUrl,
                                'event_id' => $item->event->id ?? 'unknown',
                            ]);
                            $result = $eventHandler($item->event);
                            if ($result !== null) {
                                $results[] = $result;
                            }
                            break;
                        case 'AUTH':
                            $this->logger->warning(sprintf(
                                'Relay %s requires authentication',
                                self::relayLogLabel($relayUrl)
                            ), [
                                'relay' => $relayUrl,
                                'response' => $item,
                            ]);
                            break;
                        case 'ERROR':
                        case 'NOTICE':
                            $msg = (string) ($item->message ?? 'No message');
                            $this->logger->warning(sprintf(
                                '[%s] %s: %s',
                                self::relayLogLabel($relayUrl),
                                $item->type,
                                $msg
                            ), [
                                'relay' => $relayUrl,
                                'type' => $item->type,
                                'message' => $msg,
                            ]);
                            break;
                    }
                } catch (\Exception $e) {
                    $this->logger->error(sprintf(
                        'Error processing event from relay %s: %s',
                        self::relayLogLabel($relayUrl),
                        $e->getMessage()
                    ), [
                        'relay' => $relayUrl,
                        'error' => $e->getMessage(),
                    ]);
                    continue; // Skip this item but continue processing others
                }
            }
        }
        return $results;
    }

    /**
     * @param Article $article
     * @return void
     */
    public function saveEachArticleToTheDatabase(Article $article): void
    {
        $newId = (string) ($article->getEventId() ?? '');
        if ($newId === '') {
            $this->logger->info('[longform_ingest] saveEachArticle: skip, empty eventId on Article', [
                'title' => $article->getTitle(),
            ]);

            return;
        }
        $repo = $this->entityManager->getRepository(Article::class);
        if ($repo->findOneBy(['eventId' => $newId]) !== null) {
            $this->logger->info('[longform_ingest] saveEachArticle: skip, DB already has this exact event id (no work)', [
                'eventId' => $newId,
                'slug' => $article->getSlug(),
            ]);

            return;
        }
        $pubkey = strtolower((string) ($article->getPubkey() ?? ''));
        $slug = trim((string) ($article->getSlug() ?? ''));
        if ($pubkey === '' || $slug === '') {
            $this->logger->info('[longform_ingest] saveEachArticle: persist new (missing pubkey or slug on entity)', [
                'eventId' => $newId,
                'pubkey_empty' => $pubkey === '',
                'slug' => $slug,
            ]);
            $this->persistNewArticle($article, 'missing_pubkey_or_slug_on_entity');

            return;
        }
        $incumbent = $this->findLatestLongFormArticleByAuthorAndSlug($pubkey, $slug);
        if ($incumbent === null) {
            $this->logger->info('[longform_ingest] saveEachArticle: persist new row (no DB row for author+slug)', [
                'eventId' => $newId,
                'address' => $pubkey.':…:'.self::longformIngestShortSlug($slug),
            ]);
            $this->persistNewArticle($article, 'no_db_row_for_nip33_address');

            return;
        }
        $candidate = $article->getRaw();
        if (!\is_object($candidate)) {
            $this->logger->warning('[longform_ingest] saveEachArticle: new Article has no raw wire; trying insert as new', [
                'eventId' => $newId,
            ]);
            $this->persistNewArticle($article, 'no_raw_on_incoming_article');

            return;
        }
        $iWire = self::longFormWireStubFromArticle($incumbent);
        $cTs = self::magazineEventCreatedAt($candidate);
        $iTs = self::magazineEventCreatedAt($iWire);
        if (self::wireEventSupersedes($candidate, $iWire)) {
            $this->logger->info('[longform_ingest] saveEachArticle: NIP-33 update — candidate wins, flushing DB row', [
                'address' => $pubkey.':…:'.self::longformIngestShortSlug($slug),
                'from_event_id' => $incumbent->getEventId(),
                'to_event_id' => $newId,
                'db_row_id' => $incumbent->getId(),
                'incumbent_created_at' => $iTs,
                'candidate_created_at' => $cTs,
            ]);
            $this->applyLongFormArticleOnto($article, $incumbent);
            if ($incumbent->getPubkey() !== $pubkey) {
                $incumbent->setPubkey($pubkey);
            }
            try {
                $this->entityManager->flush();
            } catch (\Exception $e) {
                $this->logger->error('[longform_ingest] saveEachArticle: flush after update failed: '.$e->getMessage());
                $this->managerRegistry->resetManager();
            }

            return;
        }
        if (self::wireEventSupersedes($iWire, $candidate)) {
            $this->logger->info('[longform_ingest] saveEachArticle: keep DB — merged relay result is not newer (incumbent wins)', [
                'address' => $pubkey.':…:'.self::longformIngestShortSlug($slug),
                'dbEventId' => $incumbent->getEventId(),
                'seenEventId' => $newId,
                'db_row_id' => $incumbent->getId(),
                'dbCreatedAt' => $iTs,
                'seenCreatedAt' => $cTs,
            ]);
        } elseif ((string) $incumbent->getEventId() !== $newId) {
            $this->logger->notice('[longform_ingest] saveEachArticle: inconclusive supersedes (different ids) — check relays / d-tag match', [
                'address' => $pubkey.':…:'.self::longformIngestShortSlug($slug),
                'dbEventId' => $incumbent->getEventId(),
                'seenEventId' => $newId,
                'db_row_id' => $incumbent->getId(),
                'dbCreatedAt' => $iTs,
                'seenCreatedAt' => $cTs,
            ]);
        }
    }

    private function persistNewArticle(Article $article, string $reason = 'unspecified'): void
    {
        try {
            $this->logger->info('[longform_ingest] persistNewArticle', [
                'reason' => $reason,
                'eventId' => $article->getEventId(),
                'slug' => self::longformIngestShortSlug((string) ($article->getSlug() ?? '')),
            ]);
            $this->entityManager->persist($article);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('[longform_ingest] persistNewArticle failed: '.$e->getMessage(), [
                'reason' => $reason,
                'eventId' => $article->getEventId(),
            ]);
            $this->managerRegistry->resetManager();
        }
    }

    private static function longformIngestShortSlug(string $slug, int $max = 100): string
    {
        $t = trim($slug);
        if (strlen($t) > $max) {
            return substr($t, 0, $max - 1).'…';
        }

        return $t;
    }

    /**
     * @return array{kind: int, id: string, created_at: int, d: string, nip33: ?string}
     */
    private static function longformIngestEventWireSummary(object $e): array
    {
        $d = self::eventDTagValue($e);
        $nip = self::nip33ParameterizedReplaceableAddress($e);

        return [
            'kind' => (int) ($e->kind ?? 0),
            'id' => (string) ($e->id ?? ''),
            'created_at' => (int) ($e->created_at ?? 0),
            'd' => $d !== null && $d !== '' ? self::longformIngestShortSlug($d, 80) : '',
            'nip33' => $nip,
        ];
    }

    private function findLatestLongFormArticleByAuthorAndSlug(string $pubkey, string $slug): ?Article
    {
        $pubkey = strtolower($pubkey);
        /** @var ?Article $row */
        $row = $this->entityManager->getRepository(Article::class)->createQueryBuilder('a')
            ->where('LOWER(a.pubkey) = :pk')
            ->andWhere('a.slug = :sl')
            ->setParameter('pk', $pubkey)
            ->setParameter('sl', $slug)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * Minimal Nostr event shape for {@see self::wireEventSupersedes} when `raw` is not a full wire object.
     */
    private static function longFormWireStubFromArticle(Article $a): object
    {
        $raw = $a->getRaw();
        if (\is_object($raw) && isset($raw->id) && (isset($raw->created_at) || isset($raw->createdAt))) {
            return $raw;
        }
        $o = new \stdClass();
        $o->id = (string) ($a->getEventId() ?? '');
        $ca = $a->getCreatedAt();
        $o->created_at = $ca !== null ? $ca->getTimestamp() : 0;
        $o->pubkey = (string) ($a->getPubkey() ?? '');
        $k = $a->getKind();

        $o->kind = $k !== null ? $k->value : KindsEnum::LONGFORM->value;

        return $o;
    }

    private function applyLongFormArticleOnto(Article $source, Article $target): void
    {
        $target->setEventId((string) $source->getEventId());
        $target->setContent($source->getContent());
        $target->setTitle($source->getTitle());
        $target->setSummary($source->getSummary());
        $target->setImage($source->getImage());
        if ($source->getCreatedAt() !== null) {
            $target->setCreatedAt($source->getCreatedAt());
        }
        $target->setSig($source->getSig());
        if ($source->getPublishedAt() !== null) {
            $target->setPublishedAt($source->getPublishedAt());
        }
        $target->setTopics($source->getTopics());
        if ($source->getKind() !== null) {
            $target->setKind($source->getKind());
        }
        $es = $source->getEventStatus();
        $target->setEventStatus($es ?? EventStatusEnum::PUBLISHED);
        $target->setRaw($source->getRaw());
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
            /** @var Data $ata */
            $data = json_decode($descriptor->decoded);
            if (!\is_object($data)) {
                $this->logger->error('Invalid descriptor decoded JSON', ['descriptor' => $descriptor]);

                return null;
            }

            $byEventId = isset($data->id) && \is_string($data->id) && $data->id !== '';

            if ($byEventId) {
                // NIP-01: filter by "ids", not "#e" (which matches *tags* named "e").
                $kind = isset($data->kind) ? (int) $data->kind : 1;
                $request = $this->createNostrRequest(
                    kinds: [$kind],
                    filters: ['ids' => [$data->id]],
                    relaySet: $this->defaultRelaySet
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
                $request = $this->createNostrRequest(
                    kinds: [$kind],
                    filters: [
                        'authors' => [$pubkey],
                        'tag' => ['#d', [$identifier]],
                    ],
                    relaySet: $this->defaultRelaySet
                );
            }

            $events = $this->processResponse($request->send(), function($received) {
                $this->logger->info('Getting event', ['item' => $received]);

                return $received;
            });

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
            $authorH = self::authorIdentToHexLower($data->pubkey ?? null);
            if (self::isNip33ParameterizedKind($kindI) && $authorH !== null) {
                $picked = self::pickLatestNip33ParameterizedForQuery($events, $kindI, $authorH, $wantD);
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
     * One wire event for a (kind, author, #d) coordinate after merging relay results.
     *
     * @param list<mixed> $events
     */
    private function pickEventForNip33OrFirst(array $events, int $kind, string $authorIdent, string $dTag): ?object
    {
        if ($events === []) {
            return null;
        }
        if (self::isNip33ParameterizedKind($kind)) {
            $h = self::authorIdentToHexLower($authorIdent);
            if ($h !== null) {
                $picked = self::pickLatestNip33ParameterizedForQuery($events, $kind, $h, $dTag);
                if ($picked !== null && \is_object($picked)) {
                    return $picked;
                }
            }
            $merged = self::mergeNip33ParameterizedWireEvents($events);
            $first = $merged[0] ?? null;

            return \is_object($first) ? $first : null;
        }
        if (self::isReplaceableByKindAndPubkeyNip($kind)) {
            $h = self::authorIdentToHexLower($authorIdent);
            if ($h !== null) {
                $best = null;
                foreach ($events as $e) {
                    if (!\is_object($e) || (int) ($e->kind ?? 0) !== $kind) {
                        continue;
                    }
                    if (strtolower((string) ($e->pubkey ?? '')) !== $h) {
                        continue;
                    }
                    if ($best === null || self::wireEventSupersedes($e, $best)) {
                        $best = $e;
                    }
                }
                if ($best !== null) {
                    return $best;
                }
            }
            foreach (self::mergeNip33ParameterizedWireEvents($events) as $e) {
                if (\is_object($e) && (int) ($e->kind ?? 0) === $kind) {
                    return $e;
                }
            }

            return null;
        }
        $e0 = $events[0] ?? null;

        return \is_object($e0) ? $e0 : null;
    }

    /** NIP-33: kinds 30_000–39_999 (parameterized replaceable) use `kind:pubkey:d` as address. */
    private const NIP33_PARAMETERIZED_KIND_MIN = 30_000;
    private const NIP33_PARAMETERIZED_KIND_MAX = 39_999;

    private static function isNip33ParameterizedKind(int $kind): bool
    {
        return $kind >= self::NIP33_PARAMETERIZED_KIND_MIN
            && $kind <= self::NIP33_PARAMETERIZED_KIND_MAX;
    }

    /**
     * NIP-33: `kind:pubkey_hex:d` (d from tags; d may include colons). Kinds 30000–39999 only.
     */
    private static function nip33ParameterizedReplaceableAddress(mixed $event): ?string
    {
        $k = self::magazineEventKind($event);
        if (!self::isNip33ParameterizedKind($k)) {
            return null;
        }
        $pk = self::magazineEventPubkeyHex($event);
        if ($pk === '' || 64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return null;
        }
        $d = self::eventDTagValue($event);
        if ($d === null || $d === '') {
            return null;
        }

        return (string) $k.':'.strtolower($pk).':'.$d;
    }

    /**
     * NIP-33: from merged relay results, the event at the replaceable address kind:pubkeyLower:d.
     * Uses {@see mergeNip33ParameterizedWireEvents} so every relay’s copies collapse to the live
     * revision the same way everywhere; then we match the requested address only.
     *
     * (Older logic reimplemented “merge” by hand and had a fallback that could return a **different**
     * 30040 (wrong #d) when the expected address key did not line up, which surfaced as “stale”
     * category indices even when a newer note existed on a relay such as TheForest.)
     *
     * @param list<mixed> $events
     */
    private static function pickLatestNip33ParameterizedForQuery(
        array $events,
        int $expectedKind,
        string $authorHexLower,
        string $dTag
    ): mixed {
        if (!self::isNip33ParameterizedKind($expectedKind)) {
            return null;
        }
        $wantD = trim($dTag);
        $expectedAddr = (string) $expectedKind.':'.$authorHexLower.':'.$wantD;

        $merged = self::mergeNip33ParameterizedWireEvents($events);
        foreach ($merged as $e) {
            if (!\is_object($e)) {
                continue;
            }
            if (self::magazineEventKind($e) !== $expectedKind) {
                continue;
            }
            if (strtolower(self::magazineEventPubkeyHex($e)) !== $authorHexLower) {
                continue;
            }
            $addr = self::nip33ParameterizedReplaceableAddress($e);
            if ($addr === $expectedAddr) {
                return $e;
            }
        }

        return null;
    }

    /**
     * Merge relay results: 30_000–39_999 by `kind:pubkey:d`; kind 0, 3, 10_000–19_999 by `kind:pubkey`;
     * others by event id. Uses {@see wireEventSupersedes} for the winning revision in each bucket.
     *
     * @param list<object> $events
     *
     * @return list<object>
     */
    private static function mergeNip33ParameterizedWireEvents(array $events): array
    {
        $byNip33Address = [];
        $byKindPubkey = [];
        $byId = [];
        foreach ($events as $e) {
            if (!\is_object($e)) {
                continue;
            }
            $k = (int) ($e->kind ?? 0);
            if (self::isNip33ParameterizedKind($k)) {
                $a = self::nip33ParameterizedReplaceableAddress($e);
                if ($a === null) {
                    continue;
                }
                if (!isset($byNip33Address[$a]) || self::wireEventSupersedes($e, $byNip33Address[$a])) {
                    $byNip33Address[$a] = $e;
                }
            } elseif (self::isReplaceableByKindAndPubkeyNip($k)) {
                $a = self::replaceableKindPubkeyAddressFromWire($e);
                if ($a === null) {
                    continue;
                }
                if (!isset($byKindPubkey[$a]) || self::wireEventSupersedes($e, $byKindPubkey[$a])) {
                    $byKindPubkey[$a] = $e;
                }
            } else {
                $id = (string) ($e->id ?? '');
                if ($id === '') {
                    continue;
                }
                if (!isset($byId[$id]) || self::wireEventSupersedes($e, $byId[$id])) {
                    $byId[$id] = $e;
                }
            }
        }

        return array_values(array_merge($byId, $byKindPubkey, $byNip33Address));
    }

    private static function authorIdentToHexLower(mixed $ident): ?string
    {
        return self::npubToHexPubkey($ident);
    }

    /**
     * Latest kind 30040 index for this author and #d tag, as {@see PublicationEventEntity}
     * so callers can use {@see PublicationEventEntity::getTags()} (relay payloads are otherwise stdClass).
     *
     * The magazine root uses the site d_tag from config. Each category uses the full child d
     * (third segment of the root "a" address). A category 30040 lists 30023 article "a" tags, not
     * further nested 30040 indices.
     */
    public function getMagazineIndex(mixed $npub, mixed $dTag): ?PublicationEventEntity
    {
        $urls = $this->configuredArticleRelayUrlList();
        $relaysForLog = implode(', ', array_map(self::relayLogLabel(...), $urls));

        return $this->queryMagazineIndex($npub, $dTag, $this->defaultRelaySet, $relaysForLog);
    }

    private function queryMagazineIndex(mixed $npub, mixed $dTag, RelaySet $relaySet, string $relaysForLog): ?PublicationEventEntity
    {
        $authorHex = self::npubToHexPubkey($npub);
        if ($authorHex === null) {
            $this->logger->warning('Magazine index: could not resolve npub to hex pubkey', [
                'npub' => $npub,
                'dTag' => $dTag,
            ]);

            return null;
        }
        $request = $this->createNostrRequest(
            [KindsEnum::PUBLICATION_INDEX],
            ['authors' => [(string) $npub], 'tag' => ['#d', [(string) $dTag]]],
            $relaySet,
        );
        $this->logger->info(sprintf('Magazine index query (relays: %s)', $relaysForLog), [
            'npub' => $npub,
            'dTag' => $dTag,
            'relays' => $relaysForLog,
        ]);
        $response = $request->send();
        $events = $this->processResponse($response, function ($received) {
            return $received;
        });
        if (empty($events)) {
            return null;
        }
        $raw = self::pickLatestNip33ParameterizedForQuery(
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

        return self::magazineEventToPublicationEntity($raw);
    }

    /**
     * Batch-fetch latest longform for category `a` coordinates; one Nostr call per (author × kind)
     * group. Uses the same full article {@see $defaultRelaySet} as kind 30040 index queries so merged
     * NIP-33 results are not stuck on a single relay’s copy. {@see saveEachArticleToTheDatabase}
     * upserts by NIP-33 address.
     *
     * @param list<string> $addresses kind:pubkey:identifier
     */
    public function ingestLongformForCategoryCoordinates(array $addresses): void
    {
        if ($addresses === []) {
            $this->logger->info('[longform_ingest] ingestLongform: no addresses, exit');

            return;
        }
        $relaysForLog = implode(', ', array_map(self::relayLogLabel(...), $this->configuredArticleRelayUrlList()));
        $this->logger->info('[longform_ingest] ingestLongform: start', [
            'address_count' => \count($addresses),
            'relays' => $relaysForLog,
            'addresses_sample' => \array_values(\array_slice($addresses, 0, 15)),
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
            $dTags = array_values(array_unique($g['dTags'] ?? []));
            if ($dTags === [] || !isset($g['pubkey'], $g['kind'])) {
                continue;
            }
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
                    fn (string $dt): string => self::longformIngestShortSlug($dt, 72),
                    $dTags
                ),
            ]);
            $request = $this->createNostrRequest(
                [$kindEnum],
                ['authors' => [(string) $g['pubkey']], 'tag' => ['#d', $dTags]],
                $this->defaultRelaySet,
            );
            try {
                $events = $this->processResponse(
                    $request->send(),
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
                        $rawSample[] = self::longformIngestEventWireSummary($ev);
                    }
                    ++$si;
                }
                $this->logger->info('[longform_ingest] ingestLongform: responses merged from relays (pre-NIP-33 per-address merge)', [
                    'raw_wire_count' => $rawCount,
                    'sample_up_to_25' => $rawSample,
                ]);
                if ($rawCount === 0) {
                    $this->logger->warning('[longform_ingest] ingestLongform: no EVENT rows returned for this filter (check relay index / author filter / #d list)', [
                        'group_key' => $gkey,
                        'authors_filter' => $g['pubkey'],
                    ]);
                }
                $merged = self::mergeNip33ParameterizedWireEvents($events);
                $mergedDetail = [];
                foreach ($merged as $ev) {
                    if (!\is_object($ev)) {
                        continue;
                    }
                    $mergedDetail[] = self::longformIngestEventWireSummary($ev);
                }
                $this->logger->info('[longform_ingest] ingestLongform: after mergeNip33ParameterizedWireEvents', [
                    'merged_count' => \count($merged),
                    'one_row_per_nip33_address' => $mergedDetail,
                ]);
                foreach ($merged as $event) {
                    if (!\is_object($event)) {
                        continue;
                    }
                    $article = $this->articleFactory->createFromLongFormContentEvent($event);
                    $this->saveEachArticleToTheDatabase($article);
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('[longform_ingest] ingestLongform: exception in group %s: %s', (string) $gkey, $e->getMessage()),
                    [
                        'message' => $e->getMessage(),
                        'pubkey' => $g['pubkey'] ?? null,
                        'trace' => $e->getTraceAsString(),
                        'relays' => $relaysForLog,
                    ],
                );
            }
        }
        $this->logger->info('[longform_ingest] ingestLongform: done (all groups)');
    }

    private static function magazineEventCreatedAt(mixed $event): int
    {
        if ($event instanceof PublicationEventEntity) {
            return $event->getCreatedAt();
        }
        if (\is_object($event) && isset($event->created_at)) {
            return (int) $event->created_at;
        }

        return 0;
    }

    private static function magazineEventId(mixed $event): string
    {
        if ($event instanceof PublicationEventEntity) {
            return $event->getId();
        }
        if (\is_object($event) && isset($event->id)) {
            return (string) $event->id;
        }

        return '';
    }

    private static function magazineEventKind(mixed $event): int
    {
        if ($event instanceof PublicationEventEntity) {
            return $event->getKind();
        }
        if (\is_object($event) && isset($event->kind)) {
            return (int) $event->kind;
        }

        return 0;
    }

    private static function magazineEventPubkeyHex(mixed $event): string
    {
        if ($event instanceof PublicationEventEntity) {
            return (string) $event->getPubkey();
        }
        if (\is_object($event) && isset($event->pubkey)) {
            return (string) $event->pubkey;
        }

        return '';
    }

    /**
     * Nostr wire tag as a name-first sequence (e.g. ["d", "ident"]). Handles both indexed arrays
     * and object-shaped tag rows from JSON.
     *
     * @return list<string>|null
     */
    private static function normalizeNostrTagRowToSequence(mixed $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if (\is_object($row)) {
            $row = get_object_vars($row);
        }
        if (!\is_array($row) || $row === []) {
            return null;
        }
        $seq = array_values(
            array_map(
                static fn (mixed $v): string => (string) $v,
                $row
            )
        );
        if ($seq === [] || $seq[0] === '') {
            return null;
        }

        return $seq;
    }

    /**
     * First "d" tag value from raw relay or {@see PublicationEventEntity} tag arrays (trimmed).
     */
    private static function eventDTagValue(mixed $event): ?string
    {
        $tags = null;
        if ($event instanceof PublicationEventEntity) {
            $tags = $event->getTags();
        } elseif (\is_object($event) && isset($event->tags) && \is_array($event->tags)) {
            $tags = $event->tags;
        }
        if (!\is_array($tags)) {
            return null;
        }
        foreach ($tags as $t) {
            $seq = self::normalizeNostrTagRowToSequence($t);
            if ($seq === null || ($seq[0] ?? '') !== 'd' || !isset($seq[1]) || (string) $seq[1] === '') {
                continue;
            }

            return trim((string) $seq[1]);
        }

        return null;
    }

    private static function npubToHexPubkey(mixed $npub): ?string
    {
        $s = trim((string) $npub);
        if ($s === '') {
            return null;
        }
        if (64 === \strlen($s) && ctype_xdigit($s)) {
            return strtolower($s);
        }
        if (str_starts_with($s, 'npub')) {
            $hex = (new Key())->convertToHex($s);

            return $hex !== '' && 64 === \strlen($hex) && ctype_xdigit($hex) ? strtolower($hex) : null;
        }

        return null;
    }

    /**
     * Normalize relay / library event objects to the app's Event entity (not persisted).
     */
    private static function magazineEventToPublicationEntity(mixed $raw): ?PublicationEventEntity
    {
        if ($raw instanceof PublicationEventEntity) {
            return $raw;
        }
        if (!\is_object($raw)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode(json_encode($raw, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data)) {
            return null;
        }
        $entity = new PublicationEventEntity();
        $entity->setId((string) ($data['id'] ?? ''));
        $entity->setKind((int) ($data['kind'] ?? 0));
        $entity->setPubkey((string) ($data['pubkey'] ?? ''));
        $entity->setContent((string) ($data['content'] ?? ''));
        $entity->setCreatedAt((int) ($data['created_at'] ?? 0));
        $tags = $data['tags'] ?? [];
        $entity->setTags(\is_array($tags) ? $tags : []);
        $entity->setSig((string) ($data['sig'] ?? ''));

        return $entity;
    }
}
