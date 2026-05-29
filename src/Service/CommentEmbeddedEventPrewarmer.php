<?php

declare(strict_types=1);

namespace App\Service;

/**
 * When comment threads are warmed, prefetch nevent/naddr targets embedded in note bodies.
 */
final class CommentEmbeddedEventPrewarmer
{
    public function __construct(
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly NostrPreviewCardRenderer $nostrPreviewCardRenderer,
    ) {
    }

    /**
     * @param array{thread?: list<object>, quotes?: list<object>} $discussion
     */
    public function prewarmFromDiscussion(array $discussion): int
    {
        $links = [];
        foreach (['thread', 'quotes'] as $bucket) {
            foreach ($discussion[$bucket] ?? [] as $event) {
                if (!\is_object($event)) {
                    continue;
                }
                foreach ($this->linksFromText((string) ($event->content ?? '')) as $link) {
                    $links[$this->linkKey($link)] = $link;
                }
            }
        }
        if ($links === []) {
            return 0;
        }
        $this->nostrPreviewCardRenderer->prefetchEventsForLinks(array_values($links));

        return \count($links);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linksFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }
        try {
            $parsed = $this->nostrLinkParser->parseLinks($text);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            $parsed,
            static fn (array $link): bool => \in_array($link['type'] ?? '', ['nevent', 'naddr'], true),
        ));
    }

    /**
     * @param array<string, mixed> $link
     */
    private function linkKey(array $link): string
    {
        return ((string) ($link['type'] ?? '')).':'.((string) ($link['identifier'] ?? ''));
    }
}
