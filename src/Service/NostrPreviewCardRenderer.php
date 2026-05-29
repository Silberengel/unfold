<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\UserBadgeOptions;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Server-side Nostr link cards (nevent / naddr / npub / nprofile) for SSR contexts
 * such as comment bodies — avoids client-side /preview/ round-trips.
 */
final class NostrPreviewCardRenderer
{
    private const EVENT_CACHE_TTL_SEC = 86400;

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly UserBadgeHtmlRenderer $userBadgeHtmlRenderer,
        private readonly NostrPreviewBodyRenderer $nostrPreviewBodyRenderer,
        private readonly Environment $twig,
        private readonly CacheItemPoolInterface $appCachePool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $link from {@see NostrLinkParser::parseLinks()}
     */
    public function renderFromLink(array $link): string
    {
        $type = (string) ($link['type'] ?? '');
        $identifier = (string) ($link['identifier'] ?? '');
        if ($type === '' || $identifier === '') {
            return '';
        }

        if ($type === 'npub' || $type === 'nprofile') {
            $ident = $type === 'nprofile' && isset($link['data'])
                ? $this->pubkeyFromProfileData($link['data'])
                : $identifier;
            if ($ident === '') {
                return '<span class="text-subtle">Profile preview unavailable.</span>';
            }

            return $this->userBadgeHtmlRenderer->render($ident, UserBadgeOptions::inline());
        }

        if ($type !== 'nevent' && $type !== 'naddr') {
            return '';
        }

        $decoded = $link['data'] ?? null;
        $decodedJson = $this->encodeDecoded($decoded);

        return $this->renderEventCard($type, $identifier, $decodedJson);
    }

    /**
     * Replace client-side nostr-preview placeholders with SSR cards.
     */
    public function resolveInlinePlaceholders(string $html): string
    {
        if ($html === '' || !str_contains($html, 'data-controller="nostr-preview"')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#<div class="nostr-preview(?:\s+nostr-preview--inline)?"[^>]*data-controller="nostr-preview"[^>]*>'
            .'<div data-nostr-preview-target="container">[\s\S]*?</div>\s*</div>#',
            function (array $m): string {
                $chunk = $m[0];
                $type = $this->attr($chunk, 'data-nostr-preview-type-value');
                $identifier = $this->attr($chunk, 'data-nostr-preview-identifier-value');
                $decoded = $this->attr($chunk, 'data-nostr-preview-decoded-value');
                if ($type === '' || $identifier === '') {
                    return $chunk;
                }

                if ($type === 'npub' || $type === 'nprofile') {
                    $ident = $identifier;
                    if ($type === 'nprofile' && $decoded !== '') {
                        $hint = json_decode(html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                        if (\is_array($hint) && isset($hint['pubkey'])) {
                            $ident = (string) $hint['pubkey'];
                        }
                    }

                    return $this->userBadgeHtmlRenderer->render($ident, UserBadgeOptions::inline());
                }

                if ($type !== 'nevent' && $type !== 'naddr') {
                    return $chunk;
                }

                return $this->renderEventCard($type, $identifier, html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            },
            $html,
        );
    }

    /**
     * @param list<array<string, mixed>> $links
     */
    public function prefetchEventsForLinks(array $links): void
    {
        foreach ($links as $link) {
            $type = (string) ($link['type'] ?? '');
            if ($type !== 'nevent' && $type !== 'naddr') {
                continue;
            }
            $identifier = (string) ($link['identifier'] ?? '');
            $decoded = $this->encodeDecoded($link['data'] ?? null);
            if ($identifier === '' || $decoded === '') {
                continue;
            }
            try {
                $this->fetchEvent($type, $identifier, $decoded);
            } catch (\Throwable $e) {
                $this->logger->debug('nostr.preview.prewarm_skip', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function renderEventCard(string $type, string $identifier, string $decodedJson): string
    {
        if ($decodedJson === '') {
            return '<span class="text-subtle">Preview unavailable (missing data).</span>';
        }

        try {
            $previewData = $this->fetchEvent($type, $identifier, $decodedJson);
        } catch (\Throwable $e) {
            return '<span class="text-subtle">Error fetching preview: '
                .htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .'</span>';
        }

        if ($previewData === null) {
            return '<span class="text-subtle">No event found on the default relay for this preview.</span>';
        }

        $previewData->type = $type;
        $previewData->identifier = $identifier;
        if (isset($previewData->content) && \is_string($previewData->content) && trim($previewData->content) !== '') {
            $previewData->content_html = $this->resolveInlinePlaceholders(
                $this->nostrPreviewBodyRenderer->render($previewData->content),
            );
        }

        return $this->twig->render('components/Molecules/NostrPreviewContent.html.twig', [
            'preview' => $previewData,
        ]);
    }

    private function fetchEvent(string $type, string $identifier, string $decodedJson): ?\stdClass
    {
        $cacheKey = 'nostr_preview_ev_'.hash('sha256', $type."\0".$identifier."\0".$decodedJson);
        try {
            $item = $this->appCachePool->getItem($cacheKey);
            if ($item->isHit()) {
                $cached = $item->get();

                return $cached instanceof \stdClass ? $cached : null;
            }
        } catch (\Psr\Cache\InvalidArgumentException) {
        }

        $descriptor = (object) [
            'type' => $type,
            'decoded' => $decodedJson,
        ];
        $event = $this->nostrClient->getEventFromDescriptor($descriptor);
        if ($event !== null) {
            try {
                $item = $this->appCachePool->getItem($cacheKey);
                $item->set($event);
                $item->expiresAfter(self::EVENT_CACHE_TTL_SEC);
                $this->appCachePool->save($item);
            } catch (\Psr\Cache\InvalidArgumentException) {
            }
        }

        return $event;
    }

  /**
   * @param mixed $data
   */
    private function encodeDecoded(mixed $data): string
    {
        if ($data === null) {
            return '';
        }
        if (\is_string($data)) {
            return $data;
        }
        try {
            return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return '';
        }
    }

  /**
   * @param mixed $data
   */
    private function pubkeyFromProfileData(mixed $data): string
    {
        if (\is_object($data) && isset($data->pubkey)) {
            return (string) $data->pubkey;
        }
        if (\is_array($data) && isset($data['pubkey'])) {
            return (string) $data['pubkey'];
        }

        return '';
    }

    private function attr(string $html, string $name): string
    {
        $q = preg_quote($name, '#');
        if (preg_match('#'.$q.'="([^"]*)"#', $html, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
