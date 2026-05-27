<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\ArticleHighlight;
use App\Enum\KindsEnum;
use App\Repository\ArticleHighlightRepository;
use App\Util\HighlightEventTags;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pulls kind-9802 highlights from relays and upserts into {@see ArticleHighlight}.
 */
final class HighlightSyncService
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly ArticleHighlightRepository $highlightRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int number of highlight rows written/updated
     */
    public function syncForArticle(Article $article): int
    {
        $id = $article->getId();
        if (null === $id || (int) $id < 1) {
            return 0;
        }

        $slug = trim((string) $article->getSlug());
        $pubkey = (string) $article->getPubkey();
        if ($slug === '' || 64 !== \strlen($pubkey) || !ctype_xdigit($pubkey)) {
            return 0;
        }

        $kind = $article->getKind()->value;
        $coordinate = $kind.':'.$pubkey.':'.$slug;

        $events = $this->nostrClient->fetchHighlightEventsForArticle($coordinate);
        $n = 0;
        foreach ($events as $ev) {
            if ((int) ($ev->kind ?? 0) !== KindsEnum::HIGHLIGHTS->value) {
                continue;
            }
            $eid = strtolower((string) ($ev->id ?? ''));
            if (64 !== \strlen($eid) || !ctype_xdigit($eid)) {
                continue;
            }
            $author = strtolower((string) ($ev->pubkey ?? ''));
            if (64 !== \strlen($author) || !ctype_xdigit($author)) {
                continue;
            }
            $tags = $ev->tags ?? [];
            if (!\is_array($tags)) {
                $tags = [];
            }
            $tags = HighlightEventTags::normalizeTagsForStorage($tags);
            $content = (string) ($ev->content ?? '');
            $ca = (int) ($ev->created_at ?? 0);
            if ($ca < 0) {
                $ca = 0;
            }
            $excerpt = HighlightEventTags::excerptForFeed($content, $tags);
            if ($excerpt === '') {
                $t = HighlightEventTags::trimNostrText($content);
                $excerpt = $t !== '' ? \mb_substr($t, 0, 240) : '';
            }

            $row = $this->highlightRepository->findOneBy(['eventId' => $eid]);
            if ($row === null) {
                $row = new ArticleHighlight();
                $row->setEventId($eid);
            }
            $row->setArticle($article);
            $row->setAuthorPubkey($author);
            $row->setContent($content);
            $row->setTags($tags);
            $row->setEventCreatedAt($ca);
            $row->setQuoteExcerpt($excerpt !== '' ? $excerpt : null);
            $this->entityManager->persist($row);
            ++$n;
        }
        if ($n > 0) {
            $this->entityManager->flush();
        }

        $this->logger->info('highlight_sync.article', [
            'article_id' => $id,
            'slug' => $slug,
            'ingested' => $n,
        ]);

        return $n;
    }
}
