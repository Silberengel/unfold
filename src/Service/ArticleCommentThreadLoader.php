<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Loads Nostr article discussion: NIP-22 (1111) + legacy kind 1 replies, plus quotes/reposts (q / a tags).
 */
final readonly class ArticleCommentThreadLoader
{
    /** PSR-6 pool backing {@see $cache}; used for true cache-only reads (SSR) without invoking Nostr. */
    public function __construct(
        private NostrClient $nostrClient,
        private NostrLinkParser $nostrLinkParser,
        private CacheInterface $cache,
        private CacheItemPoolInterface $appCachePool,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>
     * }|null
     */
    public function tryLoadFromCacheOnly(string $coordinate, ?string $articleEventHexId = null): ?array
    {
        $key = $this->cacheKeyForThread($coordinate, $articleEventHexId);
        try {
            $item = $this->appCachePool->getItem($key);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (!$item->isHit()) {
            return null;
        }
        $discussion = $item->get();
        if (!\is_array($discussion)) {
            return null;
        }
        if (($discussion['thread'] ?? []) === [] && ($discussion['quotes'] ?? []) === []) {
            $this->logger->info('comments.loader.cache_hit_empty', ['coordinate' => $coordinate]);
        } else {
            $this->logger->info('comments.loader.cache_hit_only', [
                'coordinate' => $coordinate,
                'thread' => \count($discussion['thread'] ?? []),
            ]);
        }

        return $this->expandFromDiscussion($discussion, microtime(true));
    }

    /**
     * @return array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>
     * }
     */
    public function load(string $coordinate, ?string $articleEventHexId = null): array
    {
        $t0 = microtime(true);
        $cacheKey = $this->cacheKeyForThread($coordinate, $articleEventHexId);
        $this->logger->info('comments.loader.start', [
            'cache_key_suffix' => substr($cacheKey, -16),
            'coordinate' => $coordinate,
            'article_event_hex' => $articleEventHexId,
        ]);

        try {
            $discussion = $this->cache->get($cacheKey, function (ItemInterface $item) use ($coordinate, $articleEventHexId, $t0): array {
                // Prewarm + HTTP should share the same key; 2m expiry caused cold misses during normal use.
                $item->expiresAfter(86400);
                $this->logger->info('comments.loader.cache_miss', [
                    'elapsed_since_load_start_ms' => (int) round((microtime(true) - $t0) * 1000),
                ]);
                $tNostr = microtime(true);
                // On failure, let this throw: Symfony cache will not store a value, so a prior good thread is not replaced by [].
                $out = $this->nostrClient->getArticleDiscussion($coordinate, $articleEventHexId);
                $this->logger->info('comments.loader.nostr_ok', [
                    'nostr_elapsed_ms' => (int) round((microtime(true) - $tNostr) * 1000),
                    'thread' => \count($out['thread'] ?? []),
                    'quotes' => \count($out['quotes'] ?? []),
                ]);

                return $out;
            });
        } catch (\Throwable $e) {
            $this->logger->error('comments.loader.cache_or_nostr_failed', [
                'message' => $e->getMessage(),
                'exception_class' => \get_class($e),
            ]);
            $discussion = ['thread' => [], 'quotes' => []];
        }

        return $this->expandFromDiscussion($discussion, $t0);
    }

    /**
     * Same key for CLI prewarm, anonymous, and logged-in readers so cached threads are shared.
     * (Relay selection for misses may still add aggr for signed-in users in {@see NostrClient::getArticleDiscussion}.)
     */
    private function cacheKeyForThread(string $coordinate, ?string $articleEventHexId): string
    {
        return 'comments_v5_'.hash('sha256', $coordinate."\0".($articleEventHexId ?? ''));
    }

    /**
     * @param array{thread: array<int, object>, quotes: array<int, object>} $discussion
     *
     * @return array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>
     * }
     */
    private function expandFromDiscussion(array $discussion, float $t0): array
    {
        $list = $discussion['thread'] ?? [];
        $quotes = $discussion['quotes'] ?? [];
        $this->logger->info('comments.loader.cache_resolved', [
            'elapsed_since_start_ms' => (int) round((microtime(true) - $t0) * 1000),
            'thread_events' => \count($list),
            'quote_events' => \count($quotes),
        ]);

        $commentLinks = [];
        $quoteLinks = [];
        $processedContent = [];

        $tLinks = microtime(true);
        foreach ($list as $comment) {
            $this->collectLinkPreviewsForEvent($comment, $commentLinks, $processedContent);
        }
        foreach ($quotes as $event) {
            $this->collectLinkPreviewsForEvent($event, $quoteLinks, $processedContent);
        }
        $this->logger->info('comments.loader.link_parse_done', [
            'elapsed_ms' => (int) round((microtime(true) - $tLinks) * 1000),
            'thread_events' => \count($list),
            'quote_events' => \count($quotes),
            'preview_buckets' => \count($commentLinks) + \count($quoteLinks),
        ]);

        $this->logger->info('comments.loader.complete', [
            'total_elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);

        return [
            'list' => $list,
            'quotes' => $quotes,
            'commentLinks' => $commentLinks,
            'quoteLinks' => $quoteLinks,
            'processedContent' => $processedContent,
        ];
    }

    /**
     * @param array<string, array<int, mixed>> $linkBucket
     * @param array<string, string>            $processedContent
     */
    private function collectLinkPreviewsForEvent(object $event, array &$linkBucket, array &$processedContent): void
    {
        $content = $event->content ?? '';
        if ($content === '') {
            return;
        }
        $id = $event->id ?? null;
        if ($id === null || $id === '') {
            return;
        }
        $idKey = (string) $id;
        $processedContent[$idKey] = (string) $content;
        try {
            $links = $this->nostrLinkParser->parseLinks((string) $content);
        } catch (\Throwable) {
            $links = [];
        }
        // naddr / nevent are already expanded as inline `nostr-preview` widgets in markdown
        // (NostrEventRenderer + NostrBareBech32Parser). Footer previews would duplicate the
        // same fetch/card (and looked like extra “OG” embeds next to the body).
        $links = array_values(array_filter(
            $links,
            static fn (array $link): bool => !\in_array($link['type'] ?? '', ['naddr', 'nevent'], true),
        ));
        if ($links !== []) {
            $linkBucket[$idKey] = $links;
        }
    }
}
