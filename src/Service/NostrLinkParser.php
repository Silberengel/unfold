<?php

namespace App\Service;

use App\Nostr\Nip19Codec;
use Psr\Log\LoggerInterface;

readonly class NostrLinkParser
{
    private const NOSTR_LINK_PATTERN = '/(?:nostr:)(nevent1[a-z0-9]+|naddr1[a-z0-9]+|nprofile1[a-z0-9]+|note1[a-z0-9]+|npub1[a-z0-9]+)/';

    private const URL_PATTERN = '/https?:\/\/[\w\-\.\?\,\'\/\\\+&%@\?\$#_=:\(\)~;]+/i';

    public function __construct(
        private LoggerInterface $logger,
        private Nip19Codec $nip19,
    ) {}

    /**
     * Parse content for Nostr links and return structured data
     *
     * @param string $content The content to parse
     * @return array Array of detected Nostr links with their type and decoded data
     */
    public function parseLinks(string $content): array
    {
        $links = [];
        $links = array_merge(
            $this->parseUrlsWithNostrIds($content),
            $this->parseBareNostrIdentifiers($content)
        );
        // Sort by position to maintain the original order in the text
        usort($links, fn($a, $b) => $a['position'] <=> $b['position']);

        return $this->dedupeLinksForPreviews($links);
    }

    /**
     * One preview per target. A single `nostr:naddr1…` line is matched both as a prefixed
     * link and again as a bare `naddr1…` substring; URL + bare overlaps can happen too.
     *
     * @param list<array<string, mixed>> $links
     *
     * @return list<array<string, mixed>>
     */
    private function dedupeLinksForPreviews(array $links): array
    {
        $seen = [];
        $out = [];
        foreach ($links as $link) {
            $key = $this->linkPreviewDedupeKey($link);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $link;
        }

        return $out;
    }

    private function linkPreviewDedupeKey(array $link): string
    {
        $identifier = $link['identifier'] ?? null;
        if (\is_string($identifier) && $identifier !== '') {
            $type = (string) ($link['type'] ?? '');

            return $type."\0".strtolower($identifier);
        }

        return 'match:' . (string) ($link['full_match'] ?? '');
    }

    private function parseUrlsWithNostrIds(string $content): array
    {
        $links = [];
        if (preg_match_all(self::URL_PATTERN, $content, $urlMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($urlMatches as $urlMatch) {
                $url = $urlMatch[0][0];
                $position = $urlMatch[0][1];
                $nostrId = null;
                $nostrType = null;
                $nostrData = null;
                if (preg_match(self::NOSTR_LINK_PATTERN, $url, $nostrMatch)) {
                    $nostrId = $nostrMatch[1];
                    try {
                        $decoded = $this->nip19->decode($nostrId);
                        $nostrType = $decoded->type;
                        $nostrData = $decoded->data;
                    } catch (\Exception $e) {
                        $this->logger->info('Failed to decode Nostr identifier in URL', [
                            'identifier' => $nostrId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Inline image URLs are already rendered in the body; skip OG/footer preview for them.
                if ($nostrId === null && $this->isDirectImageUrl($url)) {
                    continue;
                }

                $links[] = [
                    'type' => $nostrType ?? 'url',
                    'identifier' => $nostrId,
                    'full_match' => $url,
                    'position' => $position,
                    'data' => $nostrData,
                    'is_url' => true
                ];
            }
        }
        return $links;
    }

    private function isDirectImageUrl(string $url): bool
    {
        // Ends in image extension, or CDN style `…/name.jpg/…` (thumb, width, etc.)
        if (1 === preg_match('~\.(?:jpe?g|png|gif|webp|avif)(?:\?[^#]*)?(?:#.*)?$~i', $url)) {
            return true;
        }

        return 1 === preg_match('~\.(?:jpe?g|png|gif|webp|avif)/~i', $url);
    }

    private function parseBareNostrIdentifiers(string $content): array
    {
        $links = [];


        if (preg_match_all(self::NOSTR_LINK_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {

            // If match starts with nostr:, continue otherwise check if part of URL
            if (!(str_starts_with($matches[0][0][0], 'nostr:'))) {
                // Check if the match is part of a URL, as path or query parameter
                $urlPattern = '/https?:\/\/[\w\-.?,\'\/+&%$#@_=:()~;]+/i';
                foreach ($matches as $key => $match) {
                    $position = $match[0][1];
                    // Check if the match is preceded by a URL
                    $precedingContent = substr($content, 0, $position);

                    if (preg_match($urlPattern, $precedingContent)) {
                        // If the match is preceded by a URL, skip it
                        unset($matches[$key]);
                    }
                }
            }

            foreach ($matches as $match) {
                $identifier = $match[1][0];
                $position = $match[0][1];
                // This check will be handled in parseLinks by sorting and merging
                try {
                    $decoded = $this->nip19->decode($identifier);
                    $links[] = [
                        'type' => $decoded->type,
                        'identifier' => $identifier,
                        'full_match' => $match[0][0],
                        'position' => $position,
                        'data' => $decoded->data,
                        'is_url' => false
                    ];
                } catch (\Exception $e) {
                    $this->logger->info('Failed to decode Nostr identifier', [
                        'identifier' => $identifier,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        if (preg_match_all(
            '~(?<![\w#])(?:@)?(naddr1[0-9a-z]+|nevent1[0-9a-z]+|npub1[0-9a-z]+|nprofile1[0-9a-z]+)(?![0-9a-z])~i',
            $content,
            $bare,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($bare as $match) {
                $raw = $match[0][0];
                $position = $match[0][1];
                $identifier = ltrim($raw, '@');
                try {
                    $decoded = $this->nip19->decode($identifier);
                    if (!\in_array($decoded->type, ['naddr', 'nevent', 'npub', 'nprofile'], true)) {
                        continue;
                    }
                    $links[] = [
                        'type' => $decoded->type,
                        'identifier' => $identifier,
                        'full_match' => $raw,
                        'position' => $position,
                        'data' => $decoded->data,
                        'is_url' => false,
                    ];
                } catch (\Exception $e) {
                    $this->logger->info('Failed to decode bare Nostr identifier', [
                        'identifier' => $identifier,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $links;
    }

}
