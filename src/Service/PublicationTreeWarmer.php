<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Util\NostrEventTags;
use Psr\Log\LoggerInterface;

/**
 * Fetches nested kind-30040 indices, leaf section articles, and kind-0 profiles for publication trees.
 */
final class PublicationTreeWarmer
{
    public const READER_RELAY_TIMEOUT_SEC = 10;

    public const DEFAULT_PREWARM_MIN_INDEX_COUNT = 500;

    private const MAX_NEST_DEPTH = 8;

    public function __construct(
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly NostrClient $nostrClient,
        private readonly ArticleRepository $articleRepository,
        private readonly CacheService $cacheService,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * On-demand warm for a single publication page (bounded wall time + per-relay timeout).
     */
    public function warmForReader(Event $rootIndex, string $rootNpub, string $rootSlug): void
    {
        unset($rootNpub, $rootSlug);
        $deadline = microtime(true) + self::READER_RELAY_TIMEOUT_SEC;
        $this->warmTree(
            [$rootIndex],
            $deadline,
            self::READER_RELAY_TIMEOUT_SEC,
            warmProfiles: true,
        );
    }

    /**
     * Prewarm sections and profiles for the newest stored publication indices.
     *
     * @return array{nested_fetched: int, sections_ingested: int, profiles_stored: int}
     */
    public function warmStoredPublicationTrees(int $indexLimit, int $budgetSeconds, int $relayTimeoutSec): array
    {
        $indexLimit = max(1, $indexLimit);
        $budgetSeconds = max(1, min(600, $budgetSeconds));
        $relayTimeoutSec = max(1, min(60, $relayTimeoutSec));
        $deadline = microtime(true) + $budgetSeconds;
        $indices = $this->publicationIndexStore->findNewestPaginated($indexLimit, 0);

        return $this->warmTree($indices, $deadline, $relayTimeoutSec, warmProfiles: true);
    }

    /**
     * @param list<Event> $roots
     *
     * @return array{nested_fetched: int, sections_ingested: int, profiles_stored: int}
     */
    private function warmTree(array $roots, float $deadline, int $relayTimeoutSec, bool $warmProfiles): array
    {
        $nestedFetched = 0;
        $seenNested = [];
        $queue = [];

        foreach ($roots as $root) {
            foreach (NostrEventTags::publicationIndexNestedACoordinates($root->getTags()) as $coord) {
                if (!isset($seenNested[$coord])) {
                    $seenNested[$coord] = true;
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

            $stored = $this->publicationIndexStore->getByPubkeyHexAndD($pk, $d);
            if ($stored === null) {
                try {
                    $npub = $this->nostrKeyHelper->convertPublicKeyToBech32($pk);
                    $stored = $this->nostrClient->fetchAndStorePublicationIndex($npub, $d, $relayTimeoutSec);
                    if ($stored !== null) {
                        ++$nestedFetched;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('PublicationTreeWarmer: nested index fetch failed', [
                        'coordinate' => $coord,
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if ($stored === null) {
                continue;
            }

            foreach (NostrEventTags::publicationIndexNestedACoordinates($stored->getTags()) as $childCoord) {
                if (!isset($seenNested[$childCoord])) {
                    $seenNested[$childCoord] = true;
                    $queue[] = $childCoord;
                }
            }
        }

        $loadedIndices = $this->loadIndicesForRoots($roots, $seenNested);
        $sectionCoords = $this->collectSectionCoordinates($loadedIndices);
        $missingSections = $this->filterMissingSectionCoordinates($sectionCoords);
        $sectionsIngested = 0;
        if ($missingSections !== [] && microtime(true) < $deadline) {
            $sectionsIngested = $this->ingestSectionsUntilDeadline($missingSections, $deadline, $relayTimeoutSec);
        }

        $profilesStored = 0;
        if ($warmProfiles && microtime(true) < $deadline) {
            $pubkeys = $this->collectAuthorPubkeys($loadedIndices, $sectionCoords);
            $profilesStored = $this->warmAuthorProfilesUntilDeadline($pubkeys, $deadline, $relayTimeoutSec);
        }

        return [
            'nested_fetched' => $nestedFetched,
            'sections_ingested' => $sectionsIngested,
            'profiles_stored' => $profilesStored,
        ];
    }

    /**
     * @param list<Event> $roots
     * @param array<string, true> $seenNestedCoords
     *
     * @return list<Event>
     */
    private function loadIndicesForRoots(array $roots, array $seenNestedCoords): array
    {
        $byKey = [];
        foreach ($roots as $root) {
            $key = strtolower($root->getPubkey()).':'.(string) $root->getSlug();
            $byKey[$key] = $root;
        }
        foreach (array_keys($seenNestedCoords) as $coord) {
            $parts = explode(':', $coord, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $pk = strtolower($parts[1]);
            $d = trim($parts[2]);
            $stored = $this->publicationIndexStore->getByPubkeyHexAndD($pk, $d);
            if ($stored !== null) {
                $byKey[$pk.':'.$d] = $stored;
            }
        }

        return array_values($byKey);
    }

    /**
     * @param list<Event> $indices
     *
     * @return list<string>
     */
    private function collectSectionCoordinates(array $indices): array
    {
        $seen = [];
        $out = [];
        foreach ($indices as $index) {
            $this->walkIndexForSections($index, $seen, $out, 0);
        }

        return $out;
    }

    /**
     * @param array<string, true> $seenCoords
     * @param list<string>      $out
     */
    private function walkIndexForSections(Event $index, array &$seenCoords, array &$out, int $depth): void
    {
        if ($depth >= self::MAX_NEST_DEPTH) {
            return;
        }

        foreach (NostrEventTags::publicationSectionACoordinates($index->getTags()) as $coord) {
            if (!isset($seenCoords[$coord])) {
                $seenCoords[$coord] = true;
                $out[] = $coord;
            }
        }

        foreach (NostrEventTags::publicationIndexNestedACoordinates($index->getTags()) as $nestedCoord) {
            $parts = explode(':', $nestedCoord, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $pk = strtolower($parts[1]);
            $d = trim($parts[2]);
            $child = $this->publicationIndexStore->getByPubkeyHexAndD($pk, $d);
            if ($child !== null) {
                $this->walkIndexForSections($child, $seenCoords, $out, $depth + 1);
            }
        }
    }

    /**
     * @param list<string> $coordinates
     *
     * @return list<string>
     */
    private function filterMissingSectionCoordinates(array $coordinates): array
    {
        $missing = [];
        foreach ($coordinates as $coord) {
            $parts = explode(':', $coord, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $kind = (int) $parts[0];
            if (!\in_array($kind, KindsEnum::publicationSectionKindValues(), true)) {
                continue;
            }
            $pubkey = strtolower(trim($parts[1]));
            $slug = trim((string) $parts[2]);
            if ($slug === '' || 64 !== \strlen($pubkey)) {
                continue;
            }
            if ($this->articleRepository->findLatestBySlugForTenant($slug, $pubkey) instanceof Article) {
                continue;
            }
            $missing[] = $coord;
        }

        return $missing;
    }

    /**
     * @param list<string> $coordinates
     */
    private function ingestSectionsUntilDeadline(array $coordinates, float $deadline, int $relayTimeoutSec): int
    {
        $ingested = 0;
        $batchSize = 40;
        foreach (array_chunk($coordinates, $batchSize) as $chunk) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $before = $this->countPresentSections($chunk);
            try {
                $this->nostrClient->ingestLongformForCategoryCoordinates($chunk, $relayTimeoutSec);
            } catch (\Throwable $e) {
                $this->logger->warning('PublicationTreeWarmer: section ingest batch failed', [
                    'count' => \count($chunk),
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
            $after = $this->countPresentSections($chunk);
            $ingested += max(0, $after - $before);
        }

        return $ingested;
    }

    /**
     * @param list<string> $coordinates
     */
    private function countPresentSections(array $coordinates): int
    {
        $n = 0;
        foreach ($coordinates as $coord) {
            $parts = explode(':', $coord, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $pubkey = strtolower(trim($parts[1]));
            $slug = trim((string) $parts[2]);
            if ($this->articleRepository->findLatestBySlugForTenant($slug, $pubkey) instanceof Article) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @param list<Event>  $indices
     * @param list<string> $sectionCoords
     *
     * @return list<string> hex pubkeys
     */
    private function collectAuthorPubkeys(array $indices, array $sectionCoords): array
    {
        $hex = [];
        foreach ($indices as $index) {
            $pk = strtolower($index->getPubkey());
            if (64 === \strlen($pk) && ctype_xdigit($pk)) {
                $hex[$pk] = true;
            }
        }
        foreach ($sectionCoords as $coord) {
            $parts = explode(':', $coord, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $pk = strtolower(trim($parts[1]));
            if (64 === \strlen($pk) && ctype_xdigit($pk)) {
                $hex[$pk] = true;
            }
        }

        return array_keys($hex);
    }

    /**
     * @param list<string> $pubkeyHex
     */
    private function warmAuthorProfilesUntilDeadline(array $pubkeyHex, float $deadline, int $relayTimeoutSec): int
    {
        if ($pubkeyHex === [] || microtime(true) >= $deadline) {
            return 0;
        }

        $stored = 0;
        foreach (array_chunk($pubkeyHex, 50) as $chunk) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $bundles = $this->nostrClient->fetchProfilePrewarmWireBundlesForAuthors($chunk, $relayTimeoutSec);
                $stored += $this->cacheService->putPrewarmMetadataBatch($chunk, $bundles);
            } catch (\Throwable $e) {
                $this->logger->warning('PublicationTreeWarmer: profile batch failed', [
                    'authors' => \count($chunk),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $stored;
    }
}
