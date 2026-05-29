<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\CommonMark\Converter;
use League\CommonMark\Exception\CommonMarkException;

/**
 * Markdown → HTML for comment / quote bodies with server-resolved Nostr preview cards.
 */
final class CommentBodyHtmlRenderer
{
    public function __construct(
        private readonly Converter $converter,
        private readonly NostrContentLinkEnhancer $nostrContentLinkEnhancer,
        private readonly NostrPreviewCardRenderer $nostrPreviewCardRenderer,
    ) {
    }

    public function render(string $markdown, bool $allowRelayFetch = true): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        try {
            $html = $this->converter->convertToHTML($markdown);
        } catch (CommonMarkException) {
            $html = nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $html = $this->nostrContentLinkEnhancer->enhanceHtml($html);

        return $this->nostrPreviewCardRenderer->resolveInlinePlaceholders($html, $allowRelayFetch);
    }
}
