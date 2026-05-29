<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Loads Nostr article discussion: NIP-22 (1111) + legacy kind 1 replies, plus quotes/reposts (q / a tags).
 * Kind-9802 highlights are not in this response; they live in `article_highlight`.
 *
 * Reply blurbs mirror the jumble client: resolve the parent from `e` / `E` tags (NIP-10, `reply` marker,
 * last-of-sequence), then show a short preview of the parent’s body (see jumble `ParentNotePreview`). Inline
 * NIP-22 blockquotes with `nostr:` in the child still take precedence when present.
 */
final readonly class ArticleCommentThreadLoader
{
    private const PARENT_REPLY_TEXT_PREVIEW_MAX = 200;

    /** Partial thread cache: long enough to avoid relay-storm on flaky relays; prewarm still fills full TTL. */
    private const PARTIAL_THREAD_CACHE_TTL_SEC = 300;
    /** PSR-6 pool backing {@see $cache}; used for true cache-only reads (SSR) without invoking Nostr. */
    public function __construct(
        private NostrClient $nostrClient,
        private NostrLinkParser $nostrLinkParser,
        private NostrArticleDiscussionSupport $articleDiscussion,
        private CacheInterface $cache,
        private CacheItemPoolInterface $appCachePool,
        private CommentBodyHtmlRenderer $commentBodyHtmlRenderer,
        private CommentEmbeddedEventPrewarmer $embeddedEventPrewarmer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     superchats: list<array<string,mixed>>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>
     * }|null
     *
     * Each object in `list` may be enriched with: unfold_reply_blurb, unfold_body, unfold_depth
     * (0–3, for UI indentation).
     * `superchats` contains attested NIP-A3 kind-9740 items sorted by amount desc.
     */
    public function tryLoadFromCacheOnly(string $coordinate, ?string $articleEventHexId = null): ?array
    {
        $coordinate = $this->normalizeCoordinate($coordinate);
        $articleEventHexId = $this->normalizeArticleEventHexId($articleEventHexId);
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

        // Fast path: no relay prewarm or blocking fetches — use cache-only SSR for embedded previews.
        return $this->expandFromDiscussion($discussion, microtime(true), $articleEventHexId, allowRelayFetch: false);
    }

    /**
     * @return array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     superchats: list<array<string,mixed>>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>
     * }
     *
     * @see self::tryLoadFromCacheOnly() for list object enrichments
     */
    public function load(string $coordinate, ?string $articleEventHexId = null, bool $incrementalCache = false): array
    {
        $coordinate = $this->normalizeCoordinate($coordinate);
        $articleEventHexId = $this->normalizeArticleEventHexId($articleEventHexId);
        $t0 = microtime(true);
        $cacheKey = $this->cacheKeyForThread($coordinate, $articleEventHexId);
        $this->logger->info('comments.loader.start', [
            'cache_key_suffix' => substr($cacheKey, -16),
            'coordinate' => $coordinate,
            'article_event_hex' => $articleEventHexId,
            'incremental_cache' => $incrementalCache,
        ]);

        try {
            $item = $this->appCachePool->getItem($cacheKey);
            if ($item->isHit()) {
                $cached = $item->get();
                if (\is_array($cached) && !($cached['partial'] ?? false)) {
                    $this->logger->info('comments.loader.cache_hit_complete', ['coordinate' => $coordinate]);

                    return $this->expandFromDiscussion($cached, $t0, $articleEventHexId);
                }
            }
        } catch (InvalidArgumentException) {
        }

        $discussion = ['thread' => [], 'quotes' => []];
        $existing = $this->readRawDiscussion($cacheKey);
        $onProgress = null;
        if ($incrementalCache) {
            $onProgress = function (array $partial) use ($cacheKey, &$existing): void {
                $merged = $existing !== null
                    ? $this->mergeDiscussionArrays($existing, $partial)
                    : $partial;
                $merged['partial'] = true;
                try {
                    $this->saveRawDiscussion($cacheKey, $merged, self::PARTIAL_THREAD_CACHE_TTL_SEC);
                    $existing = $merged;
                } catch (\Throwable) {
                }
            };
        }

        try {
            $this->logger->info('comments.loader.cache_miss', [
                'elapsed_since_load_start_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
            $tNostr = microtime(true);
            $out = $this->nostrClient->getArticleDiscussion($coordinate, $articleEventHexId, $onProgress);
            if ($existing !== null) {
                $out = $this->mergeDiscussionArrays($existing, $out);
            }
            $partial = (bool) ($out['partial'] ?? false);
            $ttl = $partial ? self::PARTIAL_THREAD_CACHE_TTL_SEC : 86400;
            $this->saveRawDiscussion($cacheKey, $out, $ttl);
            $this->logger->info('comments.loader.nostr_ok', [
                'nostr_elapsed_ms' => (int) round((microtime(true) - $tNostr) * 1000),
                'thread' => \count($out['thread']),
                'quotes' => \count($out['quotes']),
                'partial' => $partial,
            ]);
            $discussion = $out;
        } catch (\Throwable $e) {
            $this->logger->error('comments.loader.cache_or_nostr_failed', [
                'message' => $e->getMessage(),
                'exception_class' => \get_class($e),
            ]);
            if ($existing !== null) {
                $discussion = $existing;
            }
        }

        $this->embeddedEventPrewarmer->prewarmFromDiscussion($discussion);

        return $this->expandFromDiscussion($discussion, $t0, $articleEventHexId);
    }

    /**
     * Drop cached thread so the next load refetches from relays (e.g. after publishing a comment).
     */
    public function invalidateThread(string $coordinate, ?string $articleEventHexId): void
    {
        $coordinate = $this->normalizeCoordinate($coordinate);
        $articleEventHexId = $this->normalizeArticleEventHexId($articleEventHexId);
        $key = $this->cacheKeyForThread($coordinate, $articleEventHexId);
        try {
            $this->cache->delete($key);
        } catch (\Throwable) {
        }
        try {
            $this->appCachePool->deleteItem($key);
        } catch (InvalidArgumentException) {
        }
    }

    /**
     * Merge a just-published thread reply into the filesystem cache so the UI can show it immediately
     * without waiting for relays to echo the event back.
     *
     * @param array<string, mixed> $rawEvent Verified signed event JSON (same shape as the publish POST body)
     */
    public function mergePublishedThreadEvent(string $coordinate, ?string $articleEventHexId, array $rawEvent): bool
    {
        $coordinate = $this->normalizeCoordinate($coordinate);
        $articleEventHexId = $this->normalizeArticleEventHexId($articleEventHexId);
        try {
            $wire = json_decode(
                json_encode($rawEvent, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                false,
                512,
                \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return false;
        }
        if (!\is_object($wire)) {
            return false;
        }
        $id = isset($wire->id) ? strtolower((string) $wire->id) : '';
        if (64 !== \strlen($id) || !ctype_xdigit($id)) {
            return false;
        }

        $kind = (int) ($wire->kind ?? 0);
        $isThread = false;
        if ($kind === KindsEnum::COMMENTS->value && $this->articleDiscussion->eventIsNip22ArticleThreadReply($wire, $coordinate)) {
            $isThread = true;
        } elseif ($kind === KindsEnum::TEXT_NOTE->value && $this->articleDiscussion->eventIsLegacyThreadReply($wire, $coordinate, $articleEventHexId)) {
            $isThread = true;
        }
        if (!$isThread) {
            return false;
        }

        $key = $this->cacheKeyForThread($coordinate, $articleEventHexId);
        $discussion = ['thread' => [], 'quotes' => [], 'superchats' => []];
        try {
            $item = $this->appCachePool->getItem($key);
            if ($item->isHit()) {
                $cached = $item->get();
                if (\is_array($cached)) {
                    $discussion['thread'] = \is_array($cached['thread'] ?? null) ? $cached['thread'] : [];
                    $discussion['quotes'] = \is_array($cached['quotes'] ?? null) ? $cached['quotes'] : [];
                    $discussion['superchats'] = \is_array($cached['superchats'] ?? null) ? $cached['superchats'] : [];
                }
            }
        } catch (InvalidArgumentException) {
            return false;
        }

        foreach ($discussion['thread'] as $ev) {
            if (!\is_object($ev) || !isset($ev->id)) {
                continue;
            }
            if (hash_equals($id, strtolower((string) $ev->id))) {
                return true;
            }
        }

        $discussion['thread'][] = $wire;
        usort(
            $discussion['thread'],
            static fn ($a, $b): int => ((int) ($a->created_at ?? 0)) <=> ((int) ($b->created_at ?? 0)),
        );
        unset($discussion['partial']);

        try {
            $this->saveRawDiscussion($key, $discussion, 86400);
        } catch (\Throwable $e) {
            $this->logger->warning('comments.loader.merge_published_failed', [
                'coordinate' => $coordinate,
                'event_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $this->logger->info('comments.loader.merge_published', [
            'coordinate' => $coordinate,
            'event_id' => $id,
            'thread_count' => \count($discussion['thread']),
        ]);

        return true;
    }

    /**
     * Same key for CLI prewarm, anonymous, and logged-in readers so cached threads are shared.
     * (Relay selection for misses may still add aggr for signed-in users in {@see NostrClient::getArticleDiscussion}.)
     */
    private function cacheKeyForThread(string $coordinate, ?string $articleEventHexId): string
    {
        $coord = $this->normalizeCoordinate($coordinate);
        $eid = $articleEventHexId !== null && $articleEventHexId !== ''
            ? strtolower($articleEventHexId)
            : '';

        return 'comments_v6_'.hash('sha256', $coord."\0".$eid);
    }

    private function normalizeCoordinate(string $coordinate): string
    {
        $parts = explode(':', $coordinate, 3);
        if (\count($parts) !== 3) {
            return $coordinate;
        }
        $parts[1] = strtolower(trim($parts[1]));

        return implode(':', $parts);
    }

    private function normalizeArticleEventHexId(?string $articleEventHexId): ?string
    {
        if ($articleEventHexId === null || $articleEventHexId === '') {
            return null;
        }
        $id = strtolower(trim($articleEventHexId));
        if (64 !== \strlen($id) || !ctype_xdigit($id)) {
            return null;
        }

        return $id;
    }

    /**
     * @return array{thread?: array<int, mixed>, quotes?: array<int, mixed>, superchats?: list<array<string,mixed>>, partial?: bool}|null
     */
    private function readRawDiscussion(string $key): ?array
    {
        try {
            $item = $this->appCachePool->getItem($key);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (!$item->isHit()) {
            return null;
        }
        $discussion = $item->get();

        return \is_array($discussion) ? $discussion : null;
    }

    /**
     * @param array{thread?: array<int, mixed>, quotes?: array<int, mixed>, superchats?: list<array<string,mixed>>, partial?: bool} $discussion
     */
    private function saveRawDiscussion(string $key, array $discussion, int $ttlSec): void
    {
        try {
            $item = $this->appCachePool->getItem($key);
            $item->set($discussion);
            $item->expiresAfter($ttlSec);
            $this->appCachePool->save($item);
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        try {
            $this->cache->delete($key);
            $this->cache->get($key, function (ItemInterface $item) use ($discussion, $ttlSec): array {
                $item->expiresAfter($ttlSec);

                return $discussion;
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Keep locally merged / optimistic thread events when a relay fetch has not caught up yet.
     *
     * @param array{thread?: array<int, mixed>, quotes?: array<int, mixed>, superchats?: list<array<string,mixed>>, partial?: bool} $base
     * @param array{thread?: array<int, mixed>, quotes?: array<int, mixed>, superchats?: list<array<string,mixed>>, partial?: bool} $incoming
     *
     * @return array{thread: array<int, object>, quotes: array<int, object>, superchats: list<array<string,mixed>>, partial?: bool}
     */
    private function mergeDiscussionArrays(array $base, array $incoming): array
    {
        $merged = $incoming;
        $merged['thread'] = $this->mergeWireEventLists($base['thread'] ?? [], $incoming['thread'] ?? [], true);
        $merged['quotes'] = $this->mergeWireEventLists($base['quotes'] ?? [], $incoming['quotes'] ?? [], false);
        if (!isset($merged['superchats']) || !\is_array($merged['superchats'])) {
            $merged['superchats'] = \is_array($base['superchats'] ?? null) ? $base['superchats'] : [];
        }

        return $merged;
    }

    /**
     * @param array<int, mixed> $base
     * @param array<int, mixed> $incoming
     *
     * @return array<int, object>
     */
    private function mergeWireEventLists(array $base, array $incoming, bool $ascCreatedAt): array
    {
        /** @var array<string, object> $byId */
        $byId = [];
        foreach ($incoming as $ev) {
            if (!\is_object($ev) || !isset($ev->id)) {
                continue;
            }
            $id = strtolower((string) $ev->id);
            if (64 === \strlen($id) && ctype_xdigit($id)) {
                $byId[$id] = $ev;
            }
        }
        foreach ($base as $ev) {
            if (!\is_object($ev) || !isset($ev->id)) {
                continue;
            }
            $id = strtolower((string) $ev->id);
            if (64 !== \strlen($id) || !ctype_xdigit($id) || isset($byId[$id])) {
                continue;
            }
            $byId[$id] = $ev;
        }
        $out = array_values($byId);
        usort(
            $out,
            static fn ($a, $b): int => $ascCreatedAt
                ? ((int) ($a->created_at ?? 0)) <=> ((int) ($b->created_at ?? 0))
                : ((int) ($b->created_at ?? 0)) <=> ((int) ($a->created_at ?? 0)),
        );

        return $out;
    }

    /**
     * @param array{thread: array<int, object>, quotes: array<int, object>, superchats?: list<array<string,mixed>>, partial?: bool} $discussion
     *
     * @return array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     superchats: list<array<string,mixed>>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>
     * }
     */
    private function expandFromDiscussion(array $discussion, float $t0, ?string $articleEventHexId = null, bool $allowRelayFetch = true): array
    {
        $list = $discussion['thread'];
        $quotes = $discussion['quotes'];
        $superchats = $discussion['superchats'] ?? [];
        $this->logger->info('comments.loader.cache_resolved', [
            'elapsed_since_start_ms' => (int) round((microtime(true) - $t0) * 1000),
            'thread_events' => \count($list),
            'quote_events' => \count($quotes),
            'superchat_count' => \count($superchats),
        ]);

        $this->enrichThreadListForDisplay($list, $articleEventHexId);
        $this->stripRepostEventBodies($list, $quotes);
        $this->attachRenderedBodies($list, $quotes, $allowRelayFetch);

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
            'superchats' => $discussion['superchats'] ?? [],
            'commentLinks' => $commentLinks,
            'quoteLinks' => $quoteLinks,
            'processedContent' => $processedContent,
            'comments_partial' => (bool) ($discussion['partial'] ?? false),
        ];
    }

    /**
     * NIP-18 reposts (kinds 6 and 16) carry a JSON-wrapped copy of the original; we only show who reposted, not the body.
     *
     * @param array<int, object> $list
     * @param array<int, object> $quotes
     */
    private function stripRepostEventBodies(array $list, array $quotes): void
    {
        $strip = static function (object $ev): void {
            $k = (int) ($ev->kind ?? 0);
            if ($k !== KindsEnum::REPOST->value && $k !== KindsEnum::GENERIC_REPOST->value) {
                return;
            }
            $ev->content = '';
            if (isset($ev->unfold_reply_blurb)) {
                $ev->unfold_reply_blurb = null;
            }
            if (isset($ev->unfold_body)) {
                $ev->unfold_body = '';
            }
        };
        foreach ($list as $ev) {
            $strip($ev);
        }
        foreach ($quotes as $ev) {
            $strip($ev);
        }
    }

    /**
     * Server-render markdown bodies so comment fragments do not rely on client-side /preview/.
     *
     * @param array<int, object> $list
     * @param array<int, object> $quotes
     */
    private function attachRenderedBodies(array $list, array $quotes, bool $allowRelayFetch = true): void
    {
        foreach ($list as $ev) {
            $raw = trim((string) ($ev->unfold_body ?? $ev->content ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                $ev->unfold_body_html = $this->commentBodyHtmlRenderer->render($raw, $allowRelayFetch);
            } catch (\Throwable $e) {
                $this->logger->warning('comments.loader.body_render_failed', [
                    'event_id' => $ev->id ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        foreach ($quotes as $ev) {
            $raw = trim((string) ($ev->content ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                $ev->unfold_body_html = $this->commentBodyHtmlRenderer->render($raw, $allowRelayFetch);
            } catch (\Throwable $e) {
                $this->logger->warning('comments.loader.body_render_failed', [
                    'event_id' => $ev->id ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }
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
        // Inline widgets in markdown already render npub/nprofile badges and nevent/naddr cards.
        $links = array_values(array_filter(
            $links,
            static fn (array $link): bool => !\in_array($link['type'] ?? '', ['naddr', 'nevent', 'npub', 'nprofile'], true),
        ));
        if ($links !== []) {
            $linkBucket[$idKey] = $links;
        }
    }

    /**
     * Adds reply blurb / body split and capped thread depth (0–3) on each thread event for Twig/CSS.
     *
     * @param array<int, object> $list
     */
    private function enrichThreadListForDisplay(array $list, ?string $articleEventHexId): void
    {
        $threadIdSet = [];
        foreach ($list as $ev) {
            $hid = isset($ev->id) ? strtolower((string) $ev->id) : '';
            if (64 === \strlen($hid) && ctype_xdigit($hid)) {
                $threadIdSet[$hid] = true;
            }
        }

        $idToEvent = [];
        foreach ($list as $ev) {
            $hid = isset($ev->id) ? strtolower((string) $ev->id) : '';
            if (64 === \strlen($hid) && ctype_xdigit($hid)) {
                $idToEvent[$hid] = $ev;
            }
        }

        $parentOf = [];
        foreach ($list as $ev) {
            $id = isset($ev->id) ? strtolower((string) $ev->id) : '';
            if (64 !== \strlen($id) || !ctype_xdigit($id)) {
                continue;
            }
            $p = $this->resolveInThreadParentId($ev, $threadIdSet, $articleEventHexId);
            if ($p !== null) {
                $parentOf[$id] = $p;
            }
        }

        foreach ($list as $ev) {
            $id = isset($ev->id) ? strtolower((string) $ev->id) : '';
            $raw = isset($ev->content) ? (string) $ev->content : '';
            $split = $this->splitNip22ReplyBlurb($raw);
            $blurb = $split['blurb'];
            if ($blurb === null || trim($blurb) === '') {
                $blurb = $this->replyBlurbFromAddressTag($ev);
            }
            if (($blurb === null || trim($blurb) === '') && $id !== '' && isset($parentOf[$id])) {
                $pid = $parentOf[$id];
                if (isset($idToEvent[$pid])) {
                    $parent = $idToEvent[$pid];
                    $pRaw = isset($parent->content) ? (string) $parent->content : '';
                    $preview = $this->parentEventTextPreviewForBlurb($pRaw);
                    if ($preview !== '') {
                        $blurb = '> *'.'Reply to'.'* — '."\n> ".$preview;
                    }
                }
            }
            $ev->unfold_reply_blurb = $this->formatReplyBlurbForDisplay($blurb);
            $ev->unfold_body = $split['body'];
            $ev->unfold_depth = $id === '' || !ctype_xdigit($id) ? 0 : $this->threadDepthCapped($id, $parentOf, 3);
        }
    }

    /**
     * NIP-22 storage often includes a markdown link to the parent; hide that in the UI and show plain “replying to …” text.
     */
    private function formatReplyBlurbForDisplay(?string $blurb): ?string
    {
        if ($blurb === null) {
            return null;
        }
        $s = trim($blurb);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/\s*\[[^\]]+\]\(nostr:[^)]+\)/u', '', $s) ?? $s;
        $s = preg_replace('/\s*\(nostr:[^)]+\)/u', '', $s) ?? $s;
        $s = rtrim($s, " \t");
        $s = preg_replace('/\s*—\s*$/u', '', $s) ?? $s;
        $s = rtrim($s, " \t");
        $s = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $s) ?? $s;
        $s = trim($s);

        return $s === '' ? null : $s;
    }

    /**
     * @return array{blurb: string|null, body: string}
     */
    private function splitNip22ReplyBlurb(string $content): array
    {
        if (!str_contains($content, "\n\n")) {
            return ['blurb' => null, 'body' => $content];
        }
        $parts = explode("\n\n", $content, 2);
        $first = trim($parts[0]);
        $rest = (string) ($parts[1] ?? '');
        if ($first === '' || !str_starts_with($first, '>')) {
            return ['blurb' => null, 'body' => $content];
        }
        if (!str_contains($first, 'nostr:')) {
            return ['blurb' => null, 'body' => $content];
        }

        return ['blurb' => $first, 'body' => $rest];
    }

    private function replyBlurbFromAddressTag(object $event): ?string
    {
        if (!isset($event->tags) || !\is_array($event->tags)) {
            return null;
        }
        foreach ($event->tags as $row) {
            if (!\is_array($row) || ($row[0] ?? null) === null || ($row[1] ?? null) === null) {
                continue;
            }
            $name = (string) $row[0];
            // Use only direct lowercase `a` tags here; uppercase `A` is often thread-root context.
            // Nested replies should derive blurbs from the direct `e` parent (handled via parentOf fallback).
            if ($name !== 'a') {
                continue;
            }
            $coord = (string) $row[1];
            if ($coord === '') {
                continue;
            }
            $parts = explode(':', $coord, 3);
            if (\count($parts) !== 3) {
                continue;
            }
            $kind = ctype_digit((string) $parts[0]) ? (int) $parts[0] : 0;
            if (!\in_array($kind, KindsEnum::articleBodyKindValues(), true)) {
                continue;
            }
            $dTag = trim((string) $parts[2]);
            if ($dTag === '') {
                $dTag = $coord;
            }

            return '> *'.'Replying to'.'* — '."\n> ".$dTag;
        }

        return null;
    }

    /**
     * Truncated single-line text from a parent’s content (strips a leading NIP-22 quote block when present),
     * similar in spirit to Jumble’s {@see ParentNotePreview} + compact ContentPreview.
     */
    private function parentEventTextPreviewForBlurb(string $raw): string
    {
        $split = $this->splitNip22ReplyBlurb($raw);
        $use = (string) $split['body'];
        if (trim($use) === '' && $raw !== '') {
            $use = $raw;
        }
        $one = trim((string) (preg_replace('/\s+/', ' ', $use) ?? ''));
        if ($one === '') {
            return '';
        }
        if (mb_strlen($one) > self::PARENT_REPLY_TEXT_PREVIEW_MAX) {
            return mb_substr($one, 0, self::PARENT_REPLY_TEXT_PREVIEW_MAX).'…';
        }

        return $one;
    }

    /**
     * In-thread parent id for a reply, mirroring jumble’s {@code getParentETag} / kind-1111 branch: prefer
     * {@code e}/{@code E} with marker {@code reply} when that id is another event in the loaded thread, else
     * the last in-thread id when several {@code e}/{@code E} apply (NIP-10), else the only in-thread id.
     *
     * The article’s root event id is never returned (blurbs/depth are about comments in the fetched list only).
     *
     * @param array<string, true> $threadIdSet lower-hex id keys
     *
     * @return string|null lower-hex parent id, or null
     */
    private function resolveInThreadParentId(object $event, array $threadIdSet, ?string $articleEventHexId): ?string
    {
        $selfId = isset($event->id) ? strtolower((string) $event->id) : '';
        if (64 !== \strlen($selfId) || !ctype_xdigit($selfId)) {
            $selfId = '';
        }
        $article = ($articleEventHexId !== null && $articleEventHexId !== '' && 64 === \strlen($articleEventHexId) && ctype_xdigit($articleEventHexId))
            ? strtolower($articleEventHexId) : null;

        $isThreadTag = static function (string $n): bool {
            return $n === 'e' || $n === 'E';
        };
        $validInThread = function (string $pid) use ($selfId, $article, $threadIdSet): bool {
            if (64 !== \strlen($pid) || !ctype_xdigit($pid)) {
                return false;
            }
            if ($selfId !== '' && hash_equals($pid, $selfId)) {
                return false;
            }
            if ($article !== null && hash_equals($pid, $article)) {
                return false;
            }

            return isset($threadIdSet[$pid]);
        };

        // 1) Explicit NIP-10 "reply" marker
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            if (!$isThreadTag((string) ($tag[0] ?? ''))) {
                continue;
            }
            if (($tag[3] ?? '') !== 'reply') {
                continue;
            }
            $pid = strtolower((string) ($tag[1] ?? ''));
            if ($validInThread($pid)) {
                return $pid;
            }
        }

        // 2) All in-thread references in tag order; last wins when multiple (cf. jumble getParentETagCommentOrDiscussion)
        $candidates = [];
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            if (!$isThreadTag((string) ($tag[0] ?? ''))) {
                continue;
            }
            $pid = strtolower((string) ($tag[1] ?? ''));
            if ($validInThread($pid)) {
                $candidates[] = $pid;
            }
        }
        if ($candidates === []) {
            return null;
        }
        if (\count($candidates) >= 2) {
            return $candidates[\count($candidates) - 1];
        }

        return $candidates[0];
    }

    /**
     * @param array<string, string> $parentOf child id => parent id
     */
    private function threadDepthCapped(string $id, array $parentOf, int $max): int
    {
        $depth = 0;
        $current = $id;
        for ($i = 0; $i < 64; ++$i) {
            if (!isset($parentOf[$current])) {
                break;
            }
            $current = $parentOf[$current];
            ++$depth;
        }

        return $depth > $max ? $max : $depth;
    }
}
