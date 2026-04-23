<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

readonly class CacheService
{
    public function __construct(
        private NostrClient            $nostrClient,
        private CacheInterface         $cache,
        private LoggerInterface        $logger,
        private CacheItemPoolInterface $appCache,
    ) {
    }

    /**
     * @param string $npub
     * @return \stdClass
     */
    public function getMetadata(string $npub): \stdClass
    {
        $cacheKey = '0_' . $npub;
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(3600); // 1 hour, adjust as needed
                try {
                    $meta = $this->nostrClient->getNpubMetadata($npub);

                    return json_decode($meta->content);
                } catch (\Exception $e) {
                    throw new MetadataRetrievalException('Failed to retrieve metadata', 0, $e);
                }
            });
        } catch (\Exception|InvalidArgumentException $e) {
            $root = $e->getPrevious() ?? $e;
            $this->logger->warning('Profile metadata fetch failed; using npub placeholder.', [
                'npub' => $npub,
                'exception' => $root,
            ]);
            $content = new \stdClass();
            $content->name = substr($npub, 0, 8) . '…' . substr($npub, -4);

            return $content;
        }
    }

    /**
     * @param list<string> $authorPubkeyHex
     * @param array<string, \stdClass> $metadataByHex from {@see NostrClient::fetchKind0MetadataForAuthors}
     */
    public function putPrewarmMetadataBatch(array $authorPubkeyHex, array $metadataByHex, Key $key): int
    {
        $n = 0;
        foreach ($authorPubkeyHex as $hex) {
            if (strlen($hex) !== 64) {
                continue;
            }
            $npub = $key->convertPublicKeyToBech32($hex);
            if (isset($metadataByHex[$hex]) && $metadataByHex[$hex] instanceof \stdClass) {
                $this->putProfileInCache($npub, $metadataByHex[$hex]);
            } else {
                $this->putProfilePlaceholderInCache($npub);
            }
            ++$n;
        }

        return $n;
    }

    public function getRelays($npub)
    {
        $cacheKey = '3_' . $npub;
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(3600); // 1 hour
                try {
                    return $this->nostrClient->getNpubRelays($npub);
                } catch (\Exception $e) {
                    $this->logger->error('Error getting relays.', ['exception' => $e]);
                    return [];
                }
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error getting relay data.', ['exception' => $e]);
            return [];
        }
    }

    private function putProfileInCache(string $npub, \stdClass $content): void
    {
        try {
            $item = $this->appCache->getItem('0_'.$npub);
            $item->set($content);
            $item->expiresAfter(3600);
            $this->appCache->save($item);
        } catch (InvalidArgumentException $e) {
            $this->logger->error('putProfileInCache', ['npub' => $npub, 'exception' => $e]);
        }
    }

    private function putProfilePlaceholderInCache(string $npub): void
    {
        try {
            $item = $this->appCache->getItem('0_'.$npub);
            if ($item->isHit()) {
                // Prewarm miss: keep an earlier good (or any) value — do not downgrade to placeholder.
                return;
            }
        } catch (InvalidArgumentException $e) {
            $this->logger->error('putProfilePlaceholderInCache', ['npub' => $npub, 'exception' => $e]);

            return;
        }
        $c = new \stdClass();
        $c->name = substr($npub, 0, 8).'…'.substr($npub, -4);
        $this->putProfileInCache($npub, $c);
    }
}
