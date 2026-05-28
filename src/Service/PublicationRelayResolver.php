<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use Psr\Log\LoggerInterface;

/**
 * DB/store first, then relays, for publication indices and section articles.
 */
final class PublicationRelayResolver
{
    public function __construct(
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly NostrClient $nostrClient,
        private readonly ArticleRepository $articleRepository,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly LoggerInterface $logger,
        private readonly int $publicationRelayTimeoutSec = PublicationTreeWarmer::READER_RELAY_TIMEOUT_SEC,
    ) {
    }

    public function resolvePublicationIndex(string $npub, string $dTag, ?int $relayTimeoutSec = null): ?Event
    {
        $dTag = trim($dTag);
        if ($dTag === '') {
            return null;
        }

        $cached = $this->publicationIndexStore->getByNpubAndD($npub, $dTag);
        if ($cached !== null) {
            return $cached;
        }

        $timeout = $relayTimeoutSec ?? $this->publicationRelayTimeoutSec;

        $this->logger->info('publication.relay_fallback', [
            'kind' => KindsEnum::PUBLICATION_INDEX->value,
            'd' => $dTag,
            'npub' => substr($npub, 0, 16).'…',
            'timeout_sec' => $timeout,
        ]);

        return $this->nostrClient->fetchAndStorePublicationIndex($npub, $dTag, $timeout);
    }

    public function resolveArticleByCoordinate(string $coordinate, ?int $relayTimeoutSec = null): ?Article
    {
        $parts = explode(':', $coordinate, 3);
        if (\count($parts) < 3) {
            return null;
        }
        $kind = (int) $parts[0];
        if (!\in_array($kind, KindsEnum::articleBodyKindValues(), true)) {
            return null;
        }
        $pubkey = strtolower(trim($parts[1]));
        $slug = trim((string) $parts[2]);
        if ($slug === '' || 64 !== \strlen($pubkey)) {
            return null;
        }

        $article = $this->articleRepository->findLatestBySlugForTenant($slug, $pubkey);
        if ($article !== null) {
            return $article;
        }

        $timeout = $relayTimeoutSec ?? $this->publicationRelayTimeoutSec;

        $this->logger->info('publication.relay_fallback', [
            'coordinate' => $kind.':'.$pubkey.':…',
            'timeout_sec' => $timeout,
        ]);

        $this->nostrClient->ingestLongformForCategoryCoordinates([$coordinate], $timeout);

        return $this->articleRepository->findLatestBySlugForTenant($slug, $pubkey);
    }
}
