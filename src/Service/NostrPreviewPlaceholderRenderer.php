<?php

declare(strict_types=1);

namespace App\Service;

use App\Nostr\Nip19Codec;

/**
 * Inline Stimulus placeholder for kind nevent / naddr previews (fetches card HTML client-side).
 */
final class NostrPreviewPlaceholderRenderer
{
    public function __construct(
        private readonly Nip19Codec $nip19,
    ) {
    }

    /**
     * @param array<string, mixed>|object|null $decodedData
     */
    public function render(string $type, string $bech, mixed $decodedData = null, ?string $fullMatch = null): string
    {
        if ($type !== 'nevent' && $type !== 'naddr') {
            return '';
        }

        if ($decodedData === null) {
            try {
                $decoded = $this->nip19->decode($bech);
                $decodedData = json_decode(json_encode($decoded->data), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $url = 'nevent' === $type ? '/e/'.$bech : '/article/'.$bech;

                return sprintf(
                    '<a href="%s" class="nostr-link">%s</a>',
                    htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars('@'.$this->labelFromKey($bech), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }
        }

        $encoded = json_encode($decodedData);
        if (!\is_string($encoded) || $encoded === '') {
            $encoded = '{}';
        }
        $payload = json_decode($encoded, true);
        if (!\is_array($payload)) {
            $payload = [];
        }
        $decodedJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $nostrUrl = $fullMatch ?? ('nostr:'.$bech);
        $safeNostr = htmlspecialchars($nostrUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBech = htmlspecialchars($bech, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeType = htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDecoded = htmlspecialchars($decodedJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="nostr-preview nostr-preview--inline"'
            .' data-controller="nostr-preview"'
            .' data-nostr-preview-identifier-value="'.$safeBech.'"'
            .' data-nostr-preview-type-value="'.$safeType.'"'
            .' data-nostr-preview-decoded-value="'.$safeDecoded.'"'
            .' data-nostr-preview-full-match-value="'.$safeNostr.'">'
            .'<div data-nostr-preview-target="container">'
            .'<div class="nostr-preview__loading text-center my-2">'
            .'<span class="nostr-preview__spinner" role="status" aria-label="Loading"></span>'
            .'<span class="nostr-preview__loading-text ms-2">Loading preview…</span>'
            .'</div>'
            .'<div class="nostr-preview-link mt-2"><a href="'.$safeNostr.'" target="_blank" rel="noopener noreferrer">'.$safeNostr.'</a></div>'
            .'</div>'
            .'</div>';
    }

    private function labelFromKey(string $key): string
    {
        return substr($key, 0, 8).'…'.substr($key, -8);
    }
}
