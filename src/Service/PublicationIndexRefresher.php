<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\NostrEventTags;
use Psr\Log\LoggerInterface;

/**
 * Mass kind-30040 ingest for community publications (not the site magazine tree).
 */
final class PublicationIndexRefresher
{
    private const INGEST_CHUNK_SECONDS = 5_184_000; // 60 days

    private const MAX_LOOKBACK_SECONDS = 63_072_000; // ~2 years

    public function __construct(
        private readonly PublicationFeature $publicationFeature,
        private readonly NostrClient $nostrClient,
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly PublicationTreeWarmer $publicationTreeWarmer,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly LoggerInterface $logger,
        private readonly int $prewarmMinIndexCount = PublicationTreeWarmer::DEFAULT_PREWARM_MIN_INDEX_COUNT,
    ) {
    }

    /**
     * @param (callable(string, array<string, int|string|bool|null>): void)|null $onProgress
     *
     * @return array{
     *     stored_window: int,
     *     nested_fetched: int,
     *     tree_nested: int,
     *     tree_sections: int,
     *     tree_profiles: int,
     *     index_count: int
     * }
     */
    public function refreshFromRelays(
        int $budgetSeconds,
        int $since,
        int $until,
        ?callable $onProgress = null,
        ?int $minIndexCount = null,
    ): array {
        if (!$this->publicationFeature->isEnabled()) {
            return $this->emptyResult();
        }

        $minIndexCount = max(1, $minIndexCount ?? $this->prewarmMinIndexCount);
        $budgetSeconds = max(1, min(600, $budgetSeconds));
        $onProgress?->__invoke('before_mass_req', ['since' => $since, 'until' => $until, 'min_count' => $minIndexCount]);

        $storedWindow = $this->ingestUntilMinCount($since, $until, $minIndexCount, $onProgress);
        $onProgress?->__invoke('after_mass_req', ['stored' => $storedWindow, 'index_count' => $this->publicationIndexStore->countPublicationIndices()]);

        $deadline = microtime(true) + $budgetSeconds;
        $nested = $this->fetchNestedPublicationIndicesUntilDeadline($deadline, $onProgress);

        $treeBudget = max(1, (int) ceil($budgetSeconds / 2));
        $relayTimeout = max(1, min(60, (int) ceil($budgetSeconds / 30)));
        $tree = $this->publicationTreeWarmer->warmStoredPublicationTrees(
            $minIndexCount,
            $treeBudget,
            $relayTimeout,
        );

        return [
            'stored_window' => $storedWindow,
            'nested_fetched' => $nested,
            'tree_nested' => $tree['nested_fetched'],
            'tree_sections' => $tree['sections_ingested'],
            'tree_profiles' => $tree['profiles_stored'],
            'index_count' => $this->publicationIndexStore->countPublicationIndices(),
        ];
    }

    /**
     * @param (callable(string, array<string, int|string|bool|null>): void)|null $onProgress
     */
    private function ingestUntilMinCount(int $since, int $until, int $minIndexCount, ?callable $onProgress): int
    {
        $storedWindow = 0;
        $floor = max(0, $until - self::MAX_LOOKBACK_SECONDS);
        $windowUntil = $until;
        $windowSince = $since;

        while (true) {
            for ($from = $windowSince; $from < $windowUntil; $from += self::INGEST_CHUNK_SECONDS) {
                $to = min($from + self::INGEST_CHUNK_SECONDS, $windowUntil);
                $storedWindow += $this->nostrClient->ingestPublicationIndicesForTimeWindow($from, $to);
            }

            if ($this->publicationIndexStore->countPublicationIndices() >= $minIndexCount) {
                break;
            }
            if ($windowSince <= $floor) {
                $this->logger->info('PublicationIndexRefresher: reached lookback floor before min index count', [
                    'count' => $this->publicationIndexStore->countPublicationIndices(),
                    'min' => $minIndexCount,
                ]);
                break;
            }

            $windowUntil = $windowSince - 1;
            $windowSince = max($floor, $windowSince - self::INGEST_CHUNK_SECONDS);
            $onProgress?->__invoke('widen_window', ['since' => $windowSince, 'until' => $windowUntil]);
        }

        return $storedWindow;
    }

    /**
     * @param (callable(string, array<string, int|string|bool|null>): void)|null $onProgress
     */
    private function fetchNestedPublicationIndicesUntilDeadline(float $deadline, ?callable $onProgress): int
    {
        $fetched = 0;
        $seen = [];
        $queue = [];

        foreach ($this->publicationIndexStore->findAllStoredIndices() as $index) {
            foreach (NostrEventTags::publicationIndexNestedACoordinates($index->getTags()) as $coord) {
                if (!isset($seen[$coord])) {
                    $seen[$coord] = true;
                    $queue[] = $coord;
                }
            }
        }

        while ($queue !== [] && microtime(true) < $deadline) {
            $coord = array_shift($queue);
            $parts = explode(':', $coord, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $pk = strtolower($parts[1]);
            $d = trim($parts[2]);
            if ($d === '' || 64 !== \strlen($pk)) {
                continue;
            }

            if ($this->publicationIndexStore->getByPubkeyHexAndD($pk, $d) !== null) {
                continue;
            }

            try {
                $npub = $this->nostrKeyHelper->convertPublicKeyToBech32($pk);
                $entity = $this->nostrClient->fetchAndStorePublicationIndex($npub, $d);
                if ($entity !== null) {
                    ++$fetched;
                    $onProgress?->__invoke('nested_fetched', ['d' => $d, 'step' => $fetched]);
                    foreach (NostrEventTags::publicationIndexNestedACoordinates($entity->getTags()) as $grandchild) {
                        if (!isset($seen[$grandchild])) {
                            $seen[$grandchild] = true;
                            $queue[] = $grandchild;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('PublicationIndexRefresher: nested fetch failed', [
                    'coordinate' => $coord,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $fetched;
    }

    /**
     * @return array{
     *     stored_window: int,
     *     nested_fetched: int,
     *     tree_nested: int,
     *     tree_sections: int,
     *     tree_profiles: int,
     *     index_count: int
     * }
     */
    private function emptyResult(): array
    {
        return [
            'stored_window' => 0,
            'nested_fetched' => 0,
            'tree_nested' => 0,
            'tree_sections' => 0,
            'tree_profiles' => 0,
            'index_count' => 0,
        ];
    }
}
