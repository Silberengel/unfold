<?php

namespace App\Twig\Components\Organisms;

use App\Service\NostrClient;
use App\Service\NostrLinkParser;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Comments
{
    public array $list = [];

    public array $commentLinks = [];

    public array $processedContent = [];

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function mount($current): void
    {
        $cacheKey = 'comments_' . hash('sha256', (string) $current);

        $this->list = $this->cache->get($cacheKey, function (ItemInterface $item) use ($current) {
            $item->expiresAfter(120);

            return $this->nostrClient->getComments($current);
        });

        $this->parseNostrLinks();
    }

    /**
     * Parse Nostr links in comments for client-side loading
     */
    private function parseNostrLinks(): void
    {
        foreach ($this->list as $comment) {
            $content = $comment->content ?? '';
            if (empty($content)) {
                continue;
            }

            // Store the original content
            $this->processedContent[$comment->id] = $content;

            // Parse the content for Nostr links
            $links = $this->nostrLinkParser->parseLinks($content);

            if (!empty($links)) {
                // Save the links for the client-side to fetch
                $this->commentLinks[$comment->id] = $links;
            }
        }
    }
}
