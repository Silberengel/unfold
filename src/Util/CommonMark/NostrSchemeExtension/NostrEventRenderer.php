<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Nostr\Nip19Codec;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

class NostrEventRenderer implements NodeRendererInterface
{
    public function __construct(
        private readonly Nip19Codec $nip19,
    ) {
    }
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        if (!($node instanceof NostrSchemeData)) {
            throw new \InvalidArgumentException('Incompatible inline node type: '.get_class($node));
        }

        $type = $node->getType();
        if ($type === 'nevent' || $type === 'naddr') {
            return $this->renderPreviewOrFallback($node, $type);
        }

        return false;
    }

    private function renderPreviewOrFallback(NostrSchemeData $node, string $type): HtmlElement
    {
        $bech = $node->getSpecial();
        try {
            $decoded = $this->nip19->decode($bech);
            $payload = json_decode(json_encode($decoded->data), true, 512, JSON_THROW_ON_ERROR);
            $decodedJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $url = 'nevent' === $type ? '/e/'.$bech : '/article/'.$bech;

            return new HtmlElement('a', ['href' => $url, 'class' => 'nostr-link'], '@'.$this->labelFromKey($bech));
        }

        $nostrUrl = 'nostr:'.$bech;
        $safeNostr = htmlspecialchars($nostrUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inner = '<div data-nostr-preview-target="container">'
            .'<div class="nostr-preview__loading text-center my-2">'
            .'<span class="nostr-preview__spinner" role="status" aria-label="Loading"></span>'
            .'<span class="nostr-preview__loading-text ms-2">Loading preview…</span>'
            .'</div>'
            .'<div class="nostr-preview-link mt-2"><a href="'.$safeNostr.'" target="_blank" rel="noopener noreferrer">'.$safeNostr.'</a></div>'
            .'</div>';

        return new HtmlElement('div', [
            'class' => 'nostr-preview nostr-preview--inline',
            'data-controller' => 'nostr-preview',
            'data-nostr-preview-identifier-value' => $bech,
            'data-nostr-preview-type-value' => $type,
            'data-nostr-preview-decoded-value' => $decodedJson,
            'data-nostr-preview-full-match-value' => $nostrUrl,
        ], $inner, false);
    }

    private function labelFromKey(string $key): string
    {
        $start = substr($key, 0, 8);
        $end = substr($key, -8);

        return $start.'…'.$end;
    }
}
