<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Persists long-form / publication-section {@see Article} rows from relay wire events (NIP-33 merge rules).
 */
final class LongformArticleIngest
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
        private readonly NostrWireEventMerge $wireMerge,
        private readonly NostrLongformArticleStore $longformArticleStore,
    ) {
    }

    public function ingest(Article $article): void
    {
        $newId = (string) ($article->getEventId() ?? '');
        if ($newId === '') {
            return;
        }
        if ($this->longformArticleStore->isEventIdAlreadyStored($newId)) {
            $existing = $this->longformArticleStore->findByEventId($newId);
            if ($existing !== null) {
                $this->longformArticleStore->linkExistingArticle($existing);
            }

            return;
        }
        $pubkey = strtolower((string) ($article->getPubkey() ?? ''));
        $slug = trim((string) ($article->getSlug() ?? ''));
        if ($pubkey === '' || $slug === '') {
            $this->longformArticleStore->persistNew($article, 'relay_cache_missing_pubkey_or_slug');

            return;
        }
        $incumbent = $this->longformArticleStore->findLatestByAuthorAndSlug($pubkey, $slug);
        if ($incumbent === null) {
            $this->longformArticleStore->persistNew($article, 'relay_cache_new_address');

            return;
        }
        $candidate = $article->getRaw();
        if (!\is_object($candidate)) {
            $this->longformArticleStore->persistNew($article, 'relay_cache_no_raw');

            return;
        }
        $iWire = $this->longformArticleStore->longFormWireStubFromArticle($incumbent);
        if ($this->wireMerge->wireEventSupersedes($candidate, $iWire)) {
            $this->longformArticleStore->applySourceOntoTarget($article, $incumbent);
            if ($incumbent->getPubkey() !== $pubkey) {
                $incumbent->setPubkey($pubkey);
            }
            try {
                $this->entityManager->flush();
                $this->longformArticleStore->linkExistingArticle($incumbent);
            } catch (\Exception $e) {
                $this->logger->warning('longform_article_ingest.flush_failed', ['message' => $e->getMessage()]);
                $this->managerRegistry->resetManager();
            }

            return;
        }
        if ($this->wireMerge->wireEventSupersedes($iWire, $candidate)) {
            $this->longformArticleStore->linkExistingArticle($incumbent);
        }
    }
}
