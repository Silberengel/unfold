<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;
use App\Util\NostrEventTags;
use Psr\Log\LoggerInterface;

/**
 * Mass kind-30040 ingest for community publications (not the site magazine tree).
 */
final class PublicationIndexRefresher
{
    public function __construct(
        private readonly PublicationFeature $publicationFeature,
        private readonly NostrClient $nostrClient,
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param (callable(string, array<string, int|string|bool|null>): void)|null $onProgress
     *
     * @return array{stored_window: int, nested_fetched: int}
     */
    public function refreshFromRelays(
        int $budgetSeconds,
        int $since,
        int $until,
        ?callable $onProgress = null,
    ): array {
        if (!$this->publicationFeature->isEnabled()) {
            return ['stored_window' => 0, 'nested_fetched' => 0];
        }

        $budgetSeconds = max(1, min(600, $budgetSeconds));
        $onProgress?->__invoke('before_mass_req', ['since' => $since, 'until' => $until]);

        $storedWindow = $this->nostrClient->ingestPublicationIndicesForTimeWindow($since, $until);
        $onProgress?->__invoke('after_mass_req', ['stored' => $storedWindow]);

        $deadline = microtime(true) + $budgetSeconds;
        $nested = $this->fetchNestedPublicationIndicesUntilDeadline($deadline, $onProgress);

        return ['stored_window' => $storedWindow, 'nested_fetched' => $nested];
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
}
