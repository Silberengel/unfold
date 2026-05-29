<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\CommonMark\Converter;
use League\CommonMark\Exception\CommonMarkException;

/**
 * Markdown + inline nostr badges/cards for kind-preview card bodies (embedded in comments, etc.).
 */
final class NostrPreviewBodyRenderer
{
    public function __construct(
        private readonly Converter $converter,
        private readonly NostrContentLinkEnhancer $nostrContentLinkEnhancer,
    ) {
    }

    public function render(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        try {
            $html = $this->converter->convertToHTML($content);
        } catch (CommonMarkException) {
            $html = nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $this->nostrContentLinkEnhancer->enhanceHtml($html);
    }
}
