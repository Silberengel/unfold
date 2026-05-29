<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PublicationIndexMetadata;
use App\Entity\Event;
use App\Util\NostrEventTags;

/**
 * Extracts kind-30040 index tags for the publication reader header (repeatable tags preserved in order).
 */
final class PublicationIndexMetadataBuilder
{
    public function __construct(
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function build(Event $index): PublicationIndexMetadata
    {
        $publisherHex = strtolower(trim($index->getPubkey()));
        $publisherNpub = '';
        if (64 === \strlen($publisherHex) && ctype_xdigit($publisherHex)) {
            try {
                $publisherNpub = $this->nostrKeyHelper->convertPublicKeyToBech32($publisherHex);
            } catch (\Throwable) {
            }
        }

        $nostrAuthorHexes = [];
        foreach ($this->collectTagValues($index, 'p') as $raw) {
            $hex = $this->normalizePubkeyHex($raw);
            if ($hex !== null) {
                $nostrAuthorHexes[] = $hex;
            }
        }

        $type = $this->firstTagValue($index, 'type');
        $version = $this->firstTagValue($index, 'version');
        $autoUpdate = $this->firstTagValue($index, 'auto-update');

        return new PublicationIndexMetadata(
            publisherPubkeyHex: $publisherHex,
            publisherNpub: $publisherNpub,
            authors: $this->collectTagValues($index, 'author'),
            nostrAuthorHexes: $nostrAuthorHexes,
            topics: $this->collectTagValues($index, 't'),
            sources: $this->collectTagValues($index, 'source'),
            identifiers: $this->collectTagValues($index, 'i'),
            type: $type !== '' ? $type : null,
            version: $version !== '' ? $version : null,
            publishedOn: $this->collectTagValues($index, 'published_on'),
            publishedBy: $this->collectTagValues($index, 'published_by'),
            summary: $this->firstTagValue($index, 'summary'),
            autoUpdate: $autoUpdate !== '' ? $autoUpdate : null,
            eventCreatedAt: $index->getCreatedAt(),
        );
    }

    /**
     * @return list<string>
     */
    private function collectTagValues(Event $index, string $tagName): array
    {
        $out = [];
        foreach ($index->getTags() as $tag) {
            if (!NostrEventTags::tagNameMatches($tag, $tagName)) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($tag);
            $val = trim((string) ($seq[1] ?? ''));
            if ($val !== '') {
                $out[] = $val;
            }
        }

        return $out;
    }

    private function firstTagValue(Event $index, string $tagName): string
    {
        $all = $this->collectTagValues($index, $tagName);

        return $all[0] ?? '';
    }

    private function normalizePubkeyHex(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (64 === \strlen($raw) && ctype_xdigit($raw)) {
            return strtolower($raw);
        }
        if (str_starts_with($raw, 'npub1')) {
            try {
                $hex = $this->nostrKeyHelper->convertToHex($raw);
            } catch (\Throwable) {
                return null;
            }
            if (64 === \strlen((string) $hex) && ctype_xdigit((string) $hex)) {
                return strtolower((string) $hex);
            }
        }

        return null;
    }
}
