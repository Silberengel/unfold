<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Pulls magazine indices from relays within a wall-clock budget and persists them to {@see MagazineIndexStore}.
 */
final class MagazineRefresher
{
    private const RELAY_STAMP_KEY = 'mag_relay_v1';

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly MagazineIndexStore $store,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $appCache,
    ) {
    }

    /**
     * Fetches the root index then each category index until $budgetSeconds elapses. $preferSlugs
     * are requested first (e.g. current /cat route) so they are less likely to miss the budget.
     */
    public function refreshFromRelays(int $budgetSeconds = 8, array $preferSlugs = []): void
    {
        $budgetSeconds = max(1, min(30, $budgetSeconds));
        $deadline = microtime(true) + $budgetSeconds;
        $npub = (string) $this->params->get('npub');
        $dTag = (string) $this->params->get('d_tag');

        // Do not set max_execution_time to the *remaining* soft budget: PHP resets the timer, so
        // after a 6s root fetch, "2s left" would become a 2s hard cap for the *next* relay I/O
        // (e.g. slow TLS) and can fatal. Cap once with headroom; the $deadline loop limits work.
        $this->applyExecutionTimeCap($budgetSeconds);

        $defaultRelay = (string) $this->params->get('default_relay');
        $relayLabel = (string) (parse_url($defaultRelay, \PHP_URL_HOST) ?: $defaultRelay);

        $root = $this->nostrClient->getMagazineIndex($npub, $dTag);
        if ($root === null) {
            $this->logger->warning(sprintf(
                'MagazineRefresher: root index not returned (tried from %s)',
                $relayLabel
            ), [
                'd_tag' => $dTag,
                'relay' => $defaultRelay,
            ]);

            return;
        }

        $this->store->putRoot($npub, $dTag, $root);

        $slugs = $this->orderedCategorySlugs($this->categorySlugsFromRoot($root), $preferSlugs);
        foreach ($slugs as $slug) {
            if (microtime(true) >= $deadline) {
                $this->logger->notice('MagazineRefresher: stopped at time budget; some categories not fetched', [
                    'unprocessed_from' => $slug,
                ]);
                break;
            }
            try {
                $cat = $this->nostrClient->getMagazineIndex($npub, $slug);
                if ($cat !== null) {
                    $this->store->putCategory($slug, $cat);
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'MagazineRefresher: category fetch failed (relays from %s): %s',
                    $relayLabel,
                    $e->getMessage()
                ), [
                    'slug' => $slug,
                    'message' => $e->getMessage(),
                    'relay' => $defaultRelay,
                ]);
            }
        }

        $this->touchLastRelayTime();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getSecondsSinceLastRelayRun(): ?int
    {
        try {
            $item = $this->appCache->getItem(self::RELAY_STAMP_KEY);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (!$item->isHit()) {
            return null;
        }

        return time() - (int) $item->get();
    }

    /**
     * Child category indices are kind 30040; each root "a" tag is a NIP-33 address
     * kind:hexpubkey:d-identifier. The third segment is the child #d (e.g. the long
     * newsroom-…-category-… string), not a shortened title.
     *
     * @return list<string>
     */
    private function categorySlugsFromRoot(Event $root): array
    {
        $slugs = [];
        foreach ($root->getTags() as $tag) {
            if (($tag[0] ?? null) !== 'a' || !isset($tag[1])) {
                continue;
            }
            $parts = explode(':', (string) $tag[1], 3);
            if (\count($parts) < 3) {
                continue;
            }
            $s = trim((string) end($parts));
            if ($s !== '' && !\in_array($s, $slugs, true)) {
                $slugs[] = $s;
            }
        }

        return $slugs;
    }

    /**
     * @param list<string> $allFromRoot
     * @param list<string> $prefer
     * @return list<string>
     */
    private function orderedCategorySlugs(array $allFromRoot, array $prefer): array
    {
        $prefer = array_values(array_filter($prefer, static function (string $s): bool {
            return $s !== '';
        }));
        $out = $prefer;
        foreach ($allFromRoot as $s) {
            if (!\in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function touchLastRelayTime(): void
    {
        $item = $this->appCache->getItem(self::RELAY_STAMP_KEY);
        $item->set((string) time());
        $item->expiresAfter(86_400);
        $this->appCache->save($item);
    }

    /**
     * One generous ceiling for PHP so relay/WebSocket I/O in one Nostr call can outlast the soft
     * $deadline by seconds without a fatal, while the loop still stops *starting* new fetches in time.
     */
    private function applyExecutionTimeCap(int $budgetSeconds): void
    {
        $sec = max(30, min(120, $budgetSeconds + 30));
        @set_time_limit($sec);
        @ini_set('max_execution_time', (string) $sec);
    }
}
