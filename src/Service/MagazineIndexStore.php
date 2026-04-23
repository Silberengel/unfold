<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Read/write persisted magazine Nostr index events (kinds 30040) without callback-based relay I/O
 * on the request path. Updated by {@see MagazineRefresher} or the /ux/magazine-sync action.
 */
final class MagazineIndexStore
{
    private const ROOT_PREFIX = 'mroot_v1_';
    private const CAT_PREFIX = 'mcat_v1_';

    /** 30 days — we refresh on page load, TTL is a safety cap if sync stops working. */
    private const PERSIST_TTL = 2_592_000;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    public function getRoot(string $npub, string $dTag): ?Event
    {
        $item = $this->pool->getItem($this->rootKey($npub, $dTag));
        if (!$item->isHit()) {
            return null;
        }

        return $this->unwrap($item->get());
    }

    public function getCategory(string $slug): ?Event
    {
        if ($slug === '') {
            return null;
        }
        $item = $this->pool->getItem($this->categoryKey($slug));
        if (!$item->isHit()) {
            return null;
        }

        return $this->unwrap($item->get());
    }

    /**
     * @throws InvalidArgumentException
     */
    public function putRoot(string $npub, string $dTag, Event $event): void
    {
        $item = $this->pool->getItem($this->rootKey($npub, $dTag));
        $item->set(serialize($event));
        $item->expiresAfter(self::PERSIST_TTL);
        $this->pool->save($item);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function putCategory(string $slug, Event $event): void
    {
        if ($slug === '') {
            return;
        }
        $item = $this->pool->getItem($this->categoryKey($slug));
        $item->set(serialize($event));
        $item->expiresAfter(self::PERSIST_TTL);
        $this->pool->save($item);
    }

    /**
     * Remove a cached category index (NIP-09 / local invalidation).
     *
     * @throws InvalidArgumentException
     */
    public function deleteCategory(string $slug): void
    {
        if ($slug === '') {
            return;
        }
        $this->pool->deleteItem($this->categoryKey($slug));
    }

    /**
     * Remove the cached root magazine index for this npub + d_tag.
     *
     * @throws InvalidArgumentException
     */
    public function deleteRoot(string $npub, string $dTag): void
    {
        $this->pool->deleteItem($this->rootKey($npub, $dTag));
    }

    private function rootKey(string $npub, string $dTag): string
    {
        return self::ROOT_PREFIX.hash('sha256', $npub."\0".$dTag);
    }

    /**
     * Category `d` / slug strings may contain colons (NIP-33 `a` segments); PSR-6 keys must not use `{}()/\@:`.
     */
    private function categoryKey(string $slug): string
    {
        return self::CAT_PREFIX.hash('sha256', $slug);
    }

    private function unwrap(mixed $value): ?Event
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        $e = unserialize($value, ['allowed_classes' => [Event::class]]);
        if (!$e instanceof Event) {
            return null;
        }

        return $e;
    }
}
