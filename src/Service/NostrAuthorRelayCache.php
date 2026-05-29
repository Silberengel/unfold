<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Kind-10002 (NIP-65) wss:// lists per author hex pubkey, cached. Fetches wire data via
 * {@see NostrClient::getNpubRelays()}; {@see NostrClient} is injected lazily to avoid a container cycle.
 *
 * Intentionally not `final` so Symfony can generate a lazy proxy for this service.
 */
class NostrAuthorRelayCache
{
    public function __construct(
        private readonly CacheInterface $relayQueryCache,
        private readonly LoggerInterface $logger,
        private readonly NostrRelayListFactory $relayListFactory,
        private readonly NostrClient $nostrClient,
    ) {
    }

    /**
     * Full NIP-65 wss:// list for a hex pubkey, cached. Prefer {@see getTopReputableRelaysForAuthor} when
     * only a few relays are needed.
     *
     * @return list<string>
     */
    public function getAuthorNip65RelaysList(string $pubkey): array
    {
        $cacheKey = 'nostr_kind10002_relays_v1_'.hash('sha256', $pubkey);

        return $this->relayQueryCache->get($cacheKey, function (ItemInterface $item) use ($pubkey): array {
            $item->expiresAfter(3600);
            try {
                $authorRelays = $this->nostrClient->getNpubRelays($pubkey);
            } catch (\Exception $e) {
                $this->logger->error('Error getting author NIP-65 relay list', [
                    'pubkey' => $pubkey,
                    'error' => $e->getMessage(),
                ]);
                $authorRelays = [];
            }
            $authorRelays = array_values(array_filter(
                $authorRelays,
                static function (string $relay): bool {
                    return str_starts_with($relay, 'wss:')
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
     * A short prefix of the author NIP-65 list (or default site relay) for queries that do not need every home relay.
     *
     * @return list<string>
     */
    public function getTopReputableRelaysForAuthor(string $pubkey, int $limit = 3): array
    {
        $all = $this->getAuthorNip65RelaysList($pubkey);
        if ($all === []) {
            return [$this->relayListFactory->getDefaultRelayUrl()];
        }
        if ($limit < 1) {
            $limit = 1;
        }

        return \array_slice($all, 0, $limit);
    }

    /**
     * NIP-65 outbox relays (`write` + unmarked `r` tags) for publish fan-out.
     *
     * @return list<string>
     */
    public function getAuthorNip65OutboxRelaysList(string $pubkey): array
    {
        $cacheKey = 'nostr_kind10002_outbox_v1_'.hash('sha256', $pubkey);

        return $this->relayQueryCache->get($cacheKey, function (ItemInterface $item) use ($pubkey): array {
            $item->expiresAfter(3600);
            try {
                $authorRelays = $this->nostrClient->getNpubOutboxRelays($pubkey);
            } catch (\Exception $e) {
                $this->logger->error('Error getting author NIP-65 outbox relay list', [
                    'pubkey' => $pubkey,
                    'error' => $e->getMessage(),
                ]);
                $authorRelays = [];
            }

            return $this->dedupeWssUrls($authorRelays);
        });
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
        foreach ($urls as $u) {
            if (!\is_string($u) || $u === '' || !str_starts_with($u, 'wss:') || str_contains($u, 'localhost')) {
                continue;
            }
            if (isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $out[] = $u;
        }

        return $out;
    }
}
