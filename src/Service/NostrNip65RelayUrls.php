<?php

declare(strict_types=1);

namespace App\Service;

/**
 * NIP-65 kind-10002: collect `r` tag values as relay URLs (wss, excluding localhost). Used for author
 * relay lists from wire and from {@see NostrClient::getNpubRelays()}.
 */
final class NostrNip65RelayUrls
{
    /**
     * All `r` tags (read + write + unmarked).
     *
     * @return list<string>
     */
    public function wssListFromKind10002Wire(object $wire): array
    {
        $urls = [];
        foreach ($this->collectRTagsFromWire($wire) as $row) {
            $urls[] = (string) $row[1];
        }

        return $this->filterWssRelayUrls($urls);
    }

    /**
     * NIP-65 outbox: `write` markers and unmarked `r` tags (legacy = read+write).
     *
     * @return list<string>
     */
    public function outboxWssListFromKind10002Wire(object $wire): array
    {
        $out = [];
        foreach ($this->collectRTagsFromWire($wire) as $row) {
            if (!$this->isOutboxRelayTag($row)) {
                continue;
            }
            $out[] = (string) $row[1];
        }

        return $this->filterWssRelayUrls($out);
    }

    /**
     * @return list<array<int, string>>
     */
    private function collectRTagsFromWire(object $wire): array
    {
        $relays = [];
        foreach ($wire->tags ?? [] as $tag) {
            if (!\is_array($tag) && !\is_object($tag)) {
                continue;
            }
            $r = \is_object($tag) ? array_values((array) $tag) : $tag;
            if (!isset($r[0], $r[1]) || (string) $r[0] !== 'r') {
                continue;
            }
            $relays[] = array_map(static fn ($v) => (string) $v, $r);
        }

        return $relays;
    }

    /**
     * @param array<int, string> $tagRow
     */
    private function isOutboxRelayTag(array $tagRow): bool
    {
        if (!isset($tagRow[2])) {
            return true;
        }
        foreach (\array_slice($tagRow, 2) as $marker) {
            if ($marker === 'write') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $urls
     *
     * @return list<string>
     */
    private function filterWssRelayUrls(array $urls): array
    {
        return array_values(array_filter(array_unique($urls), static function (string $relay): bool {
            return str_starts_with($relay, 'wss:') && !str_contains($relay, 'localhost');
        }));
    }
}
