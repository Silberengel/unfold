<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\NostrContentLinkEnhancer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class Filters extends AbstractExtension
{
    public function __construct(
        private readonly NostrContentLinkEnhancer $nostrContentLinkEnhancer,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('shortenNpub', [$this, 'shortenNpub']),
            new TwigFilter('linkify', [$this, 'linkify'], ['is_safe' => ['html']]),
            new TwigFilter('mentionify', [$this, 'mentionify'], ['is_safe' => ['html']]),
            new TwigFilter('nostrify', [$this, 'nostrify'], ['is_safe' => ['html']]),
        ];
    }

    public function shortenNpub(string $npub): string
    {
        return substr($npub, 0, 8).'…'.substr($npub, -4);
    }

    public function linkify(string $text): string
    {
        return preg_replace_callback(
            '#\b((https?://|www\.)[^\s<]+)#i',
            function ($matches) {
                $url = $matches[0];
                $href = str_starts_with($url, 'http') ? $url : 'https://'.$url;

                return sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                );
            },
            $text
        );
    }

    public function mentionify(string $text): string
    {
        return $this->nostrContentLinkEnhancer->enhancePlainText($text);
    }

    public function nostrify(string $text): string
    {
        return $this->nostrContentLinkEnhancer->enhancePlainText($text);
    }
}
