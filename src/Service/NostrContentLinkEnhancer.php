<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Injects nostr-preview placeholders into HTML from Asciidoc (and other non-CommonMark) bodies.
 */
final class NostrContentLinkEnhancer
{
    public function __construct(
        private readonly NostrLinkParser $nostrLinkParser,
    ) {
    }

    public function enhanceHtml(string $html): string
    {
        if ($html === '' || !str_contains($html, 'naddr1') && !str_contains($html, 'nostr:naddr')) {
            return $html;
        }

        $links = $this->nostrLinkParser->parseLinks(strip_tags($html));
        if ($links === []) {
            return $html;
        }

        $append = '';
        foreach ($links as $link) {
            if (($link['type'] ?? '') !== 'naddr') {
                continue;
            }
            $identifier = (string) ($link['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            $decoded = json_encode($link['data'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $append .= sprintf(
                '<div class="nostr-preview nostr-preview--inline" data-controller="nostr-preview" data-nostr-preview-identifier-value="%s" data-nostr-preview-type-value="naddr" data-nostr-preview-decoded-value="%s"><div data-nostr-preview-target="container"><div class="nostr-preview__loading text-center my-2"><span class="nostr-preview__spinner" role="status"></span></div></div></div>',
                htmlspecialchars($identifier, ENT_QUOTES),
                htmlspecialchars($decoded, ENT_QUOTES),
            );
        }

        return $html.$append;
    }
}
