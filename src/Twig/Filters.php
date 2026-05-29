<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CacheService;
use App\Util\ProfileDisplayName;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class Filters extends AbstractExtension
{
    public function __construct(
        private readonly CacheService $cacheService,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('shortenNpub', [$this, 'shortenNpub']),
            new TwigFilter('linkify', [$this, 'linkify'], ['is_safe' => ['html']]),
            new TwigFilter('mentionify', [$this, 'mentionify'], ['is_safe' => ['html']]),
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
        if (! preg_match_all('/@(?<npub>npub1[0-9a-z]{10,})/i', $text, $matches, PREG_SET_ORDER)) {
            return $text;
        }

        $npubs = array_values(array_unique(array_column($matches, 'npub')));
        $this->cacheService->prefetchMetadataForNpubs($npubs);

        return preg_replace_callback(
            '/@(?<npub>npub1[0-9a-z]{10,})/i',
            function ($matches) {
                $npub = $matches['npub'];
                $label = ProfileDisplayName::resolve($this->cacheService->getMetadata($npub), $npub);

                return sprintf(
                    '<a href="/p/%s" class="mention-link">@%s</a>',
                    htmlspecialchars($npub, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                );
            },
            $text
        );
    }
}
