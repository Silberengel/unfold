<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Util\NostrEventTags;
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
        private readonly FeaturedAuthorSync $featuredAuthorSync,
        /**
         * Comma-separated category #d slugs (from the root index `a` tags) to fetch first after the root
         * when the magazine relay phase is time-bounded; see MAGAZINE_PREWARM_PREFER_SLUGS in .env.
         */
        private readonly string $magazinePrewarmPreferSlugs = '',
        /**
         * Comma-separated category #d slugs to always run a 30040 fetch for in prewarm, after the
         * slugs from the live root (e.g. politics while the cached root has not yet listed that `a` tag).
         */
        private readonly string $magazinePrewarmAlsoSlugs = '',
    ) {
    }

    /**
     * Fetches the root 30040, then each category 30040. The soft wall-time budget applies to the
     * **category phase only** (after the root is stored). The root fetch is not counted against that
     * window—otherwise a slow root can consume the entire default budget and no category would be
     * refreshed (stale per-category cache while the root looks current).
     *
     * $preferSlugs are requested first (e.g. current /cat route) so they are less likely to miss
     * the category budget if the slug list is long.
     *
     * @param (callable(string, array<string, int|string|bool|null>): void)|null $onProgress
     *        Phases: `before_root`, `after_root` (total_steps, step, slug_count, slugs: list<string>),
     *        `category_fetched` (step, total_steps, category_index, category_total, slug)
     */
    public function refreshFromRelays(int $budgetSeconds = 8, array $preferSlugs = [], ?callable $onProgress = null): void
    {
        // Allow large budgets (PrewarmCommand --magazine-budget). Hard cap only to avoid runaway PHP time.
        $budgetSeconds = max(1, min(600, $budgetSeconds));
        $npub = (string) $this->params->get('npub');
        $dTag = (string) $this->params->get('d_tag');
        $preferFromEnv = $this->parseCommaSeparatedSlugs($this->magazinePrewarmPreferSlugs);

        // Allow enough PHP wall time for a slow root fetch plus the full category-phase budget.
        $this->applyExecutionTimeCap(2 * $budgetSeconds);

        $defaultRelay = (string) $this->params->get('default_relay');
        $relayLabel = (string) (parse_url($defaultRelay, \PHP_URL_HOST) ?: $defaultRelay);

        if ($preferFromEnv !== []) {
            $this->logger->info('MagazineRefresher: prefer slugs (env) merged into fetch order', [
                'prefer' => $preferFromEnv,
            ]);
        }

        $onProgress?->__invoke('before_root', []);
        $root = $this->nostrClient->getMagazineIndex($npub, $dTag);
        if ($root === null) {
            $onProgress?->__invoke('aborted', ['reason' => 'no_root']);
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

        $deadline = microtime(true) + $budgetSeconds;

        $mergedPrefer = $this->mergePreferSlugsInOrder($preferSlugs, $preferFromEnv);
        $alsoFromEnv = $this->parseCommaSeparatedSlugs($this->magazinePrewarmAlsoSlugs);
        if ($alsoFromEnv !== []) {
            $this->logger->info('MagazineRefresher: also slugs (env) merged into 30040 fetch list', [
                'also' => $alsoFromEnv,
            ]);
        }
        $slugs = $this->orderedCategorySlugs(
            $this->categorySlugsFromRoot($root),
            $mergedPrefer,
            $alsoFromEnv
        );
        $totalSteps = 1 + \count($slugs);
        $onProgress?->__invoke('after_root', [
            'total_steps' => $totalSteps,
            'step' => 1,
            'slug_count' => \count($slugs),
            'slugs' => $slugs,
        ]);
        $step = 1;
        $catTotal = \count($slugs);
        $catIndex = 0;
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
            } finally {
                ++$step;
                ++$catIndex;
                $onProgress?->__invoke('category_fetched', [
                    'step' => $step,
                    'total_steps' => $totalSteps,
                    'category_index' => $catIndex,
                    'category_total' => $catTotal,
                    'slug' => $slug,
                ]);
            }
        }

        try {
            $this->featuredAuthorSync->syncNewAuthorsFromMagazineCategories();
        } catch (\Throwable $e) {
            $this->logger->warning('MagazineRefresher: featured author sync failed', [
                'message' => $e->getMessage(),
            ]);
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
            if (!NostrEventTags::tagNameMatches($tag, 'a')) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($tag);
            if ($seq === null || !isset($seq[1]) || (string) $seq[1] === '') {
                continue;
            }
            $parts = explode(':', (string) $seq[1], 3);
            if (\count($parts) < 3) {
                continue;
            }
            $s = trim((string) $parts[2]);
            if ($s !== '' && !\in_array($s, $slugs, true)) {
                $slugs[] = $s;
            }
        }

        return $slugs;
    }

    /**
     * Order: prefer (incl. MAGAZINE_PREWARM_PREFER_SLUGS), then MAGAZINE_PREWARM_ALSO_SLUGS, then
     * each remaining category from the live root 30040. "Also" runs before the root tail so a
     * time-bounded prewarm still fetches e.g. a new politics category 30040 even if the slug list
     * from the root is long and the soft budget would stop before the former end of the list.
     *
     * @param list<string> $allFromRoot
     * @param list<string> $prefer
     * @param list<string>  $also
     *
     * @return list<string>
     */
    private function orderedCategorySlugs(array $allFromRoot, array $prefer, array $also): array
    {
        $prefer = array_values(array_filter($prefer, static function (string $s): bool {
            return $s !== '';
        }));
        $out = $prefer;
        foreach ($also as $s) {
            $s = trim($s);
            if ($s !== '' && !\in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
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
        $sec = max(30, min(700, $budgetSeconds + 30));
        @set_time_limit($sec);
        @ini_set('max_execution_time', (string) $sec);
    }

    /**
     * @return list<string>
     */
    private function parseCommaSeparatedSlugs(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $s = trim($part);
            if ($s !== '' && !\in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $fromCaller e.g. current /cat route (first)
     * @param list<string> $fromEnv MAGAZINE_PREWARM_PREFER_SLUGS (next)
     *
     * @return list<string>
     */
    private function mergePreferSlugsInOrder(array $fromCaller, array $fromEnv): array
    {
        $out = [];
        foreach (array_merge($fromCaller, $fromEnv) as $s) {
            $s = trim((string) $s);
            if ($s !== '' && !\in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }
}
