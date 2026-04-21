<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Loads Nostr comment threads (kind 1111) for a coordinate and parses inline nostr links for previews.
 */
final readonly class ArticleCommentThreadLoader
{
    public function __construct(
        private NostrClient $nostrClient,
        private NostrLinkParser $nostrLinkParser,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return array{list: array<int, object>, commentLinks: array<string, array<int, mixed>>, processedContent: array<string, string>}
     */
    public function load(string $coordinate): array
    {
        $cacheKey = 'comments_'.hash('sha256', $coordinate);

        try {
            $list = $this->cache->get($cacheKey, function (ItemInterface $item) use ($coordinate): array {
                $item->expiresAfter(120);
                try {
                    return $this->nostrClient->getComments($coordinate);
                } catch (\Throwable) {
                    return [];
                }
            });
        } catch (\Throwable) {
            $list = [];
        }

        $commentLinks = [];
        $processedContent = [];

        foreach ($list as $comment) {
            $content = $comment->content ?? '';
            if ($content === '') {
                continue;
            }
            $id = $comment->id ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $idKey = (string) $id;
            $processedContent[$idKey] = $content;
            try {
                $links = $this->nostrLinkParser->parseLinks($content);
            } catch (\Throwable) {
                $links = [];
            }
            if ($links !== []) {
                $commentLinks[$idKey] = $links;
            }
        }

        return [
            'list' => $list,
            'commentLinks' => $commentLinks,
            'processedContent' => $processedContent,
        ];
    }
}
