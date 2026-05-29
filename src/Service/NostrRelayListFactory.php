<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Relay lists from site YAML ({@code community_relays}, {@code search_relays}, {@code profile_relays}).
 * Use-case comments live in {@see config/sites/*.yaml}.
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
     * @param list<string> $communityRelayUrls
     * @param list<string> $searchRelayUrls
     * @param list<string> $profileRelayUrls
     */
    public function __construct(
        private array $communityRelayUrls,
        private array $searchRelayUrls,
        private array $profileRelayUrls,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @deprecated Use {@see getCommunityRelayUrlList()}
     */
    public function getCommunityRelayUrl(): string
    {
        $list = $this->getCommunityRelayUrlList();

        return $list[0] ?? '';
    }

    /**
     * All configured community relays (wss + http), deduplicated.
     *
     * @return list<string>
     */
    public function getCommunityRelayUrlList(): array
    {
        return $this->dedupeRelayUrls($this->communityRelayUrls);
    }

    /**
     * @return list<string>
     */
    public function getCommunityWssUrlList(): array
    {
        return $this->dedupeWssUrls($this->communityRelayUrls);
    }

    /**
     * @return list<string>
     */
    public function getCommunityHttpUrlList(): array
    {
        return $this->dedupeHttpRelayUrls($this->communityRelayUrls);
    }

    public function getCommunityRelaySet(): RelaySet
    {
        return $this->relaySetFromDistinctUrlList($this->getCommunityWssUrlList());
    }

    /**
     * All configured search relays (wss + http), deduplicated.
     *
     * @return list<string>
     */
    public function getSearchRelayUrlList(): array
    {
        return $this->dedupeRelayUrls($this->searchRelayUrls);
    }

    /**
     * @return list<string>
     */
    public function getSearchWssUrlList(): array
    {
        return $this->dedupeWssUrls($this->searchRelayUrls);
    }

    /**
     * @return list<string>
     */
    public function getSearchHttpUrlList(): array
    {
        return $this->dedupeHttpRelayUrls($this->searchRelayUrls);
    }

    public function getSearchRelaySet(): RelaySet
    {
        return $this->relaySetFromDistinctUrlList($this->getSearchWssUrlList());
    }

    /**
     * community_relays + search_relays (deduplicated). Used for publishing comments and similar paths.
     *
     * @return list<string>
     */
    public function getPublishRelayUrlList(): array
    {
        return $this->dedupeRelayUrls(array_merge(
            $this->getCommunityRelayUrlList(),
            $this->getSearchRelayUrlList(),
        ));
    }

    /** @deprecated Use {@see getSearchRelayUrlList()} */
    public function getConfiguredArticleRelayUrlList(): array
    {
        return $this->getSearchRelayUrlList();
    }

    /** @deprecated Use {@see getSearchRelaySet()} */
    public function getDefaultArticleRelaySet(): RelaySet
    {
        return $this->getSearchRelaySet();
    }

    /** @deprecated Use {@see getCommunityRelayUrl()} */
    public function getDefaultRelayUrl(): string
    {
        return $this->getCommunityRelayUrl() !== '' ? $this->getCommunityRelayUrl() : ($this->getSearchRelayUrlList()[0] ?? '');
    }

    /**
     * Profile relays not already listed in {@see getSearchRelayUrlList()}.
     *
     * @return list<string>
     */
    public function getProfileRelayUrlsExcludedFromSearchRelays(): array
    {
        $search = array_fill_keys($this->getSearchRelayUrlList(), true);
        $out = [];
        foreach ($this->getProfileRelayUrlList() as $u) {
            if (!isset($search[$u])) {
                $out[] = $u;
            }
        }

        return $out;
    }

    /** @deprecated Use {@see getProfileRelayUrlsExcludedFromSearchRelays()} */
    public function getProfileRelayUrlsExcludedFromArticleRelays(): array
    {
        return $this->getProfileRelayUrlsExcludedFromSearchRelays();
    }

    public function createRelaySetFromUrlsOnly(array $relayUrls): RelaySet
    {
        return $this->relaySetFromDistinctUrlList($relayUrls);
    }

    /**
     * Prepends {@see getSearchWssUrlList()}, then extra URLs, deduped (wss only for RelaySet).
     */
    public function createRelaySetMergedWithSearchList(array $relayUrls): RelaySet
    {
        return $this->relaySetFromDistinctUrlList(array_merge($this->getSearchWssUrlList(), $relayUrls));
    }

    /**
     * @return list<string>
     */
    public function mergeSearchRelayUrlList(array $relayUrls): array
    {
        return $this->dedupeRelayUrls(array_merge($this->getSearchRelayUrlList(), $relayUrls));
    }

    /** @deprecated Use {@see createRelaySetMergedWithSearchList()} */
    public function createRelaySetMergedWithArticleList(array $relayUrls): RelaySet
    {
        return $this->createRelaySetMergedWithSearchList($relayUrls);
    }

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
        foreach ($this->dedupeWssUrls($urls) as $relayUrl) {
            $relaySet->addRelay(new Relay($relayUrl));
        }

        return $relaySet;
    }

    /**
     * @param list<string> $urls
     *
     * @return array{wss: list<string>, http: list<string>}
     */
    public function partitionRelayUrlsByScheme(array $urls): array
    {
        $wss = [];
        $http = [];
        foreach ($this->dedupeRelayUrls($urls) as $url) {
            if (str_starts_with($url, 'wss:')) {
                $wss[] = $url;
            } elseif (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                $http[] = $url;
            }
        }

        return ['wss' => $wss, 'http' => $http];
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
     * @return list<string>
     */
    public function getTenantConfiguredRelayUrlList(): array
    {
        return $this->dedupeRelayUrls(array_merge(
            $this->getCommunityRelayUrlList(),
            $this->getSearchRelayUrlList(),
            $this->getProfileRelayUrlList(),
        ));
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
        return $this->dedupeWssUrls($this->profileRelayUrls);
    }

    /**
     * profile_relays first, then community + search wss only (no HTTP index relays for kind-0).
     *
     * @return list<string>
     */
    public function getProfileMetadataQueryRelayUrlList(): array
    {
        $ordered = $this->dedupeWssUrls(array_merge(
            $this->getProfileRelayUrlList(),
            $this->getCommunityWssUrlList(),
            $this->getSearchWssUrlList(),
        ));
        if ($ordered === []) {
            return [];
        }

        return $this->withAggrNostrLandIfUserSubscribesNostrLand($ordered);
    }

    public function getRelaySetForProfileMetadataFetch(): RelaySet
    {
        return $this->relaySetFromDistinctUrlList($this->getProfileMetadataQueryRelayUrlList());
    }

    /**
     * @param list<string> $urls
     *
     * @return list<string>
     */
    private function dedupeRelayUrls(array $urls): array
    {
        $seen = [];
        $out = [];
        foreach ($urls as $url) {
            if (!\is_string($url) || $url === '' || isset($seen[$url])) {
                continue;
            }
            if (!str_starts_with($url, 'wss:')
                && !str_starts_with($url, 'http://')
                && !str_starts_with($url, 'https://')) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $url;
        }

        return $out;
    }

    /**
     * @param list<string> $urls
     *
     * @return list<string>
     */
    private function dedupeWssUrls(array $urls): array
    {
        $seen = [];
        $out = [];
        foreach ($urls as $url) {
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
     * @param list<string> $urls
     *
     * @return list<string>
     */
    private function dedupeHttpRelayUrls(array $urls): array
    {
        $seen = [];
        $out = [];
        foreach ($urls as $url) {
            if (!\is_string($url) || $url === '' || isset($seen[$url])) {
                continue;
            }
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $url;
        }

        return $out;
    }
}
