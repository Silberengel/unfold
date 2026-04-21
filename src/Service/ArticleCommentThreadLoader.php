<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Loads Nostr article discussion: NIP-22 (1111) + legacy kind 1 replies, plus quotes/reposts (q / a tags).
 */
final readonly class ArticleCommentThreadLoader
{
    public function __construct(
        private NostrClient $nostrClient,
        private NostrLinkParser $nostrLinkParser,
        private CacheInterface $cache,
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
     * }
     */
    public function load(string $coordinate, ?string $articleEventHexId = null): array
    {
        $t0 = microtime(true);
        $cacheKey = 'comments_v4_'.hash('sha256', $coordinate."\0".($articleEventHexId ?? ''));
        $this->logger->info('comments.loader.start', [
            'cache_key_suffix' => substr($cacheKey, -16),
            'coordinate' => $coordinate,
            'article_event_hex' => $articleEventHexId,
        ]);

        try {
            $discussion = $this->cache->get($cacheKey, function (ItemInterface $item) use ($coordinate, $articleEventHexId, $t0): array {
                $item->expiresAfter(120);
                $this->logger->info('comments.loader.cache_miss', [
                    'elapsed_since_load_start_ms' => (int) round((microtime(true) - $t0) * 1000),
                ]);
                $tNostr = microtime(true);
                try {
                    $out = $this->nostrClient->getArticleDiscussion($coordinate, $articleEventHexId);
                    $this->logger->info('comments.loader.nostr_ok', [
                        'nostr_elapsed_ms' => (int) round((microtime(true) - $tNostr) * 1000),
                        'thread' => \count($out['thread'] ?? []),
                        'quotes' => \count($out['quotes'] ?? []),
                    ]);

                    return $out;
                } catch (\Throwable $e) {
                    $this->logger->error('comments.loader.nostr_failed', [
                        'message' => $e->getMessage(),
                        'exception_class' => \get_class($e),
                        'nostr_elapsed_ms' => (int) round((microtime(true) - $tNostr) * 1000),
                    ]);

                    return ['thread' => [], 'quotes' => []];
                }
            });
        } catch (\Throwable $e) {
            $this->logger->error('comments.loader.cache_failed', [
                'message' => $e->getMessage(),
                'exception_class' => \get_class($e),
            ]);
            $discussion = ['thread' => [], 'quotes' => []];
        }

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
