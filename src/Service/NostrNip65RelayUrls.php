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
     * @return list<string>
     */
    public function wssListFromKind10002Wire(object $wire): array
    {
        $relays = [];
        foreach ($wire->tags ?? [] as $tag) {
            if (!\is_array($tag) && !\is_object($tag)) {
                continue;
            }
            $r = \is_object($tag) ? array_values((array) $tag) : $tag;
            if (!isset($r[0], $r[1])) {
                continue;
            }
            if ((string) $r[0] === 'r') {
                $relays[] = (string) $r[1];
            }
        }

        return array_values(array_filter(array_unique($relays), static function (string $relay) {
            return str_starts_with($relay, 'wss:') && !str_contains($relay, 'localhost');
        }));
    }
}
