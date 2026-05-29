<?php

declare(strict_types=1);

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\HighlightAuthorMetadataProvider;
use App\Util\ProfileDisplayName;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

final class NostrMentionRenderer implements NodeRendererInterface
{
    public function __construct(
        private readonly HighlightAuthorMetadataProvider $metadataProvider,
    ) {
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (!($node instanceof NostrMentionLink)) {
            throw new \InvalidArgumentException('Incompatible inline node type: '.\get_class($node));
        }

        $npub = $node->getNpub();
        $label = $this->resolveLabel($node);
        $url = '/p/'.\rawurlencode($npub);

        return new HtmlElement('a', ['href' => $url, 'class' => 'mention-link'], '@'.$label);
    }

    private function resolveLabel(NostrMentionLink $node): string
    {
        $npub = $node->getNpub();
        $explicit = \trim($node->getLabel() ?? '');

        if (\str_starts_with($npub, 'npub1')) {
            if ($explicit !== '' && ! ProfileDisplayName::isShortNpubPlaceholder($explicit, $npub)) {
                return $explicit;
            }

            return ProfileDisplayName::resolve($this->metadataProvider->getMetadata($npub), $npub);
        }

        return $explicit !== '' ? $explicit : ProfileDisplayName::shortNpubLabel($npub);
    }
}
