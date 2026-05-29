<?php

declare(strict_types=1);

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\UserBadgeHtmlRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

final class NostrMentionRenderer implements NodeRendererInterface
{
    public function __construct(
        private readonly UserBadgeHtmlRenderer $userBadgeHtmlRenderer,
    ) {
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (!($node instanceof NostrMentionLink)) {
            throw new \InvalidArgumentException('Incompatible inline node type: '.\get_class($node));
        }

        $html = $this->userBadgeHtmlRenderer->render($node->getNpub());

        return new HtmlElement('span', ['class' => 'nostr-user-badge-inline'], $html, false);
    }
}
