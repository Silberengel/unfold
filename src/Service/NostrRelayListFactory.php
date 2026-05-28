<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Config-driven relay URL lists and {@link RelaySet} construction: default + article + profile URLs,
 * profile fetch ordering, sequential cap for slow in-process {@see \swentel\nostr\Request\Request::send()},
 * and Nostr Land → aggr.nostr.land for logged-in readers who list the former.
 */
final readonly class NostrRelayListFactory
{
    /** When a logged-in user lists this relay, also use {@see self::AGGR_NOSTR_LAND} for comment + profile reads. */
    private const NOSTR_LAND = 'wss://nostr.land';

    /**
     * Aggregated / subscription relay (not for anonymous visitors). Only added when the session user
     * has {@see self::NOSTR_LAND} in their NIP-65-style relay list.
     */
    private const AGGR_NOSTR_LAND = 'wss://aggr.nostr.land';

    /**
     * {@see \swentel\nostr\Request\Request::send()} hits relays sequentially; profile pages (metadata, long-form list, 10133) used
     * the full default+article+profile list (~8–9 wss) → 2 slow relays can exceed PHP’s 30s default max_execution_time.
     */
    private const MAX_PROFILE_SEQUENTIAL_RELAY_URLS = 3;

    /**
     * @param list<string> $articleRelayUrls
     * @param list<string> $profileRelayUrls kind-0 / profile; merged for metadata (see {@see getProfileMetadataQueryRelayUrlList()})
     */
    public function __construct(
        private string $defaultRelayUrl,
        private array $articleRelayUrls,
        private array $profileRelayUrls,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger,
    ) {
    }

    public function getDefaultRelayUrl(): string
    {
        return $this->defaultRelayUrl;
    }

    /**
     * default_relay + article_relays from config, in order, deduplicated. Used for the static
     * default set and as the base when merging author/extra relay URLs in {@see createRelaySetMergedWithArticleList()}.
     *
     * @return list<string>
     */
    public function getConfiguredArticleRelayUrlList(): array
    {
        $seen = [];
        $out = [];
        if ($this->defaultRelayUrl !== '') {
            $seen[$this->defaultRelayUrl] = true;
            $out[] = $this->defaultRelayUrl;
        }
        foreach ($this->articleRelayUrls as $url) {
            if ($url === '' || isset($seen[$url])) {
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

    public function getDefaultArticleRelaySet(): RelaySet
    {
        $relaySet = new RelaySet();
        foreach ($this->getConfiguredArticleRelayUrlList() as $url) {
            $relaySet->addRelay(new Relay($url));
        }

        return $relaySet;
    }

    /**
     * Configured profile relays (kind-0 / NIP-05 hints) that are not already in the article relay list.
     * Used as a second pass for magazine 30040 and category long-form ingest when article relays return nothing.
     * Intentionally excludes merging article URLs again — {@see createRelaySetMergedWithArticleList()} prepends article relays.
     *
     * @return list<string>
     */
    public function getProfileRelayUrlsExcludedFromArticleRelays(): array
    {
        $article = array_fill_keys($this->getConfiguredArticleRelayUrlList(), true);
        $out = [];
        foreach ($this->getProfileRelayUrlList() as $u) {
            if (!isset($article[$u])) {
                $out[] = $u;
            }
        }

        return $out;
    }

    /**
     * Relay set built only from the given URLs (no implicit article-relay merge).
     */
    public function createRelaySetFromUrlsOnly(array $relayUrls): RelaySet
    {
        $relaySet = new RelaySet();
        $seen = [];
        foreach ($relayUrls as $relayUrl) {
            if (!\is_string($relayUrl) || $relayUrl === '' || isset($seen[$relayUrl])) {
                continue;
            }
            $seen[$relayUrl] = true;
            $relaySet->addRelay(new Relay($relayUrl));
        }

        return $relaySet;
    }

    /**
     * Merges all configured article relays (default + article_relays) with the given URLs in order, deduped.
     * Used for comment threads, per-author fetches, etc.
     */
    public function createRelaySetMergedWithArticleList(array $relayUrls): RelaySet
    {
        $relaySet = new RelaySet();
        $seen = [];
        foreach (array_merge($this->getConfiguredArticleRelayUrlList(), $relayUrls) as $relayUrl) {
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

    public function loggedInUserHasNostrLandInRelayList(): bool
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
     *
     * @return list<string>
     */
    public function withAggrNostrLandIfUserSubscribesNostrLand(array $urls): array
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
    public function relaySetFromDistinctUrlList(array $urls): RelaySet
    {
        $relaySet = new RelaySet();
        $seen = [];
        foreach ($urls as $relayUrl) {
            if ($relayUrl === '' || isset($seen[$relayUrl])) {
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
    public function capSequentialRelaysForProfileFetches(array $urls): array
    {
        if (\count($urls) <= self::MAX_PROFILE_SEQUENTIAL_RELAY_URLS) {
            return $urls;
        }
        $this->logger->notice('nostr.relay_list_capped', [
            'context' => 'profile_sequential',
            'max' => self::MAX_PROFILE_SEQUENTIAL_RELAY_URLS,
            'had' => \count($urls),
        ]);

        return \array_slice($urls, 0, self::MAX_PROFILE_SEQUENTIAL_RELAY_URLS);
    }

    /**
     * @return list<string> Deduplicated profile relay URLs from config
     */
    /**
     * Union of default_relay, article_relays, and profile_relays (config only).
     *
     * @return list<string>
     */
    public function getTenantConfiguredRelayUrlList(): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge(
            $this->getConfiguredArticleRelayUrlList(),
            $this->getProfileRelayUrlList(),
        ) as $url) {
            $norm = $this->normalizeRelayUrl($url);
            if ($norm === '' || isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $out[] = $url;
        }

        return $out;
    }

    public function isTenantConfiguredRelay(string $relayUrl): bool
    {
        $norm = $this->normalizeRelayUrl($relayUrl);
        if ($norm === '') {
            return false;
        }
        foreach ($this->getTenantConfiguredRelayUrlList() as $configured) {
            if ($this->normalizeRelayUrl($configured) === $norm) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRelayUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * @return list<string>
     */
    public function getProfileRelayUrlList(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->profileRelayUrls as $url) {
            if ($url === '' || isset($seen[$url])) {
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
     * Profile (kind-0) queries: {@see getProfileRelayUrlList()} first (Damus, nos.lol, …), then default + article set.
     * Order matters: {@see \swentel\nostr\Request\Request::send()} walks relays sequentially.
     *
     * @return list<string>
     */
    public function getProfileMetadataQueryRelayUrlList(): array
    {
        $seen = [];
        $ordered = [];
        foreach (array_merge($this->getProfileRelayUrlList(), $this->getConfiguredArticleRelayUrlList()) as $u) {
            if ($u === '' || isset($seen[$u])) {
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
     * Same relays for kind-0 metadata, without mutating the default article relay set from {@see NostrClient}.
     */
    public function getRelaySetForProfileMetadataFetch(): RelaySet
    {
        $relaySet = new RelaySet();
        foreach ($this->getProfileMetadataQueryRelayUrlList() as $url) {
            $relaySet->addRelay(new Relay($url));
        }

        return $relaySet;
    }
}
