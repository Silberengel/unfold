<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\NostrPreviewPlaceholderRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

class NostrEventRenderer implements NodeRendererInterface
{
    public function __construct(
        private readonly NostrPreviewPlaceholderRenderer $nostrPreviewPlaceholderRenderer,
    ) {
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
    {
        if (!($node instanceof NostrSchemeData)) {
            throw new \InvalidArgumentException('Incompatible inline node type: '.get_class($node));
        }

        $type = $node->getType();
        if ($type === 'nevent' || $type === 'naddr') {
            return $this->renderPreviewOrFallback($node, $type);
        }

        return null;
    }

    private function renderPreviewOrFallback(NostrSchemeData $node, string $type): HtmlElement
    {
        $bech = $node->getSpecial();
        $html = $this->nostrPreviewPlaceholderRenderer->render($type, $bech, null, 'nostr:'.$bech);

        return new HtmlElement('div', [], $html, false);
    }
}
