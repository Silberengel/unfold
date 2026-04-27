<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Enum\EventStatusEnum;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * MySQL `article` row updates for NIP-23 / long-form ingest: find-by-(pubkey,slug), merge wire onto row,
 * persist. Orchestrated by {@see NostrClient::saveEachArticleToTheDatabase()}.
 */
final class NostrLongformArticleStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
        private readonly NostrWireEventMerge $wireMerge,
    ) {
    }

    public function isEventIdAlreadyStored(string $eventId): bool
    {
        if ($eventId === '') {
            return false;
        }

        return $this->entityManager->getRepository(Article::class)->findOneBy(['eventId' => $eventId]) !== null;
    }

    public function findLatestByAuthorAndSlug(string $pubkey, string $slug): ?Article
    {
        $pubkey = strtolower($pubkey);
        /** @var ?Article $row */
        $row = $this->entityManager->getRepository(Article::class)->createQueryBuilder('a')
            ->where('LOWER(a.pubkey) = :pk')
            ->andWhere('a.slug = :sl')
            ->setParameter('pk', $pubkey)
            ->setParameter('sl', $slug)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * Minimal Nostr event shape for {@see NostrWireEventMerge::wireEventSupersedes()} when `raw` is not a full wire object.
     */
    public function longFormWireStubFromArticle(Article $a): object
    {
        $raw = $a->getRaw();
        if (\is_object($raw) && isset($raw->id) && (isset($raw->created_at) || isset($raw->createdAt))) {
            return $raw;
        }
        $o = new \stdClass();
        $o->id = (string) ($a->getEventId() ?? '');
        $ca = $a->getCreatedAt();
        $o->created_at = $ca !== null ? $ca->getTimestamp() : 0;
        $o->pubkey = (string) ($a->getPubkey() ?? '');
        $k = $a->getKind();

        $o->kind = $k !== null ? $k->value : KindsEnum::LONGFORM->value;

        return $o;
    }

    public function applySourceOntoTarget(Article $source, Article $target): void
    {
        $target->setEventId((string) $source->getEventId());
        $target->setContent($source->getContent());
        $target->setTitle($source->getTitle());
        $target->setSummary($source->getSummary());
        $target->setImage($source->getImage());
        if ($source->getCreatedAt() !== null) {
            $target->setCreatedAt($source->getCreatedAt());
        }
        $target->setSig($source->getSig());
        if ($source->getPublishedAt() !== null) {
            $target->setPublishedAt($source->getPublishedAt());
        }
        $target->setTopics($source->getTopics());
        if ($source->getKind() !== null) {
            $target->setKind($source->getKind());
        }
        $es = $source->getEventStatus();
        $target->setEventStatus($es ?? EventStatusEnum::PUBLISHED);
        $target->setRaw($source->getRaw());
    }

    public function persistNew(Article $article, string $reason = 'unspecified'): void
    {
        try {
            $this->logger->info('[longform_ingest] persistNewArticle', [
                'reason' => $reason,
                'eventId' => $article->getEventId(),
                'slug' => $this->wireMerge->longformIngestShortSlug((string) ($article->getSlug() ?? '')),
            ]);
            $this->entityManager->persist($article);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('[longform_ingest] persistNewArticle failed: '.$e->getMessage(), [
                'reason' => $reason,
                'eventId' => $article->getEventId(),
            ]);
            $this->managerRegistry->resetManager();
        }
    }
}
