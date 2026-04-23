<?php

namespace App\Repository;

use App\Dto\FeaturedArticleCard;
use App\Entity\Article;
use App\Enum\EventStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;

class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * Search articles by title, content, and summary using database LIKE queries
     */
    public function searchArticles(string $query, int $limit = 12, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('a');

        $searchTerms = explode(' ', trim($query));
        $conditions = $qb->expr()->orX();

        foreach ($searchTerms as $index => $term) {
            $term = trim($term);
            if (empty($term)) {
                continue;
            }

            $paramName = 'term' . $index;
            $termCondition = $qb->expr()->orX(
                $qb->expr()->like('a.title', ':' . $paramName),
                $qb->expr()->like('a.content', ':' . $paramName),
                $qb->expr()->like('a.summary', ':' . $paramName)
            );
            $conditions->add($termCondition);
            $qb->setParameter($paramName, '%' . $term . '%');
        }

        return $qb
            ->where($conditions)
            ->andWhere('a.content IS NOT NULL')
            ->andWhere('LENGTH(a.content) > 250') // Only articles with substantial content
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * List-card fields only: avoids loading `content` / `raw` (can be very large) for home/category featured rows.
     *
     * @return list<FeaturedArticleCard>
     */
    public function findFeaturedCardsBySlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb
            ->select('a.id', 'a.slug', 'a.title', 'a.summary', 'a.image', 'a.created_at', 'a.pubkey')
            ->from('article', 'a')
            ->where($qb->expr()->in('a.slug', ':slugs'))
            ->setParameter('slugs', $slugs, ArrayParameterType::STRING)
            ->orderBy('a.created_at', 'DESC');

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $ca = $row['created_at'] ?? null;
            $out[] = new FeaturedArticleCard(
                isset($row['id']) ? (int) $row['id'] : null,
                isset($row['slug']) ? (string) $row['slug'] : null,
                isset($row['title']) ? (string) $row['title'] : null,
                isset($row['summary']) ? (string) $row['summary'] : null,
                isset($row['image']) ? (string) $row['image'] : null,
                $ca !== null && $ca !== '' ? new \DateTimeImmutable((string) $ca) : null,
                isset($row['pubkey']) ? (string) $row['pubkey'] : null,
            );
        }

        return $out;
    }

    /**
     * Resolve NIP-33 `a` tags (kind:pubkey:identifier) to articles without conflating the same
     * #d value across different authors.
     *
     * @param list<array{pubkey: string, slug: string}> $pairs
     * @return array<string, Article> key "pubkey\0slug" (lowercase hex pubkey, trimmed slug)
     */
    public function findByAuthorAndSlugIndexed(array $pairs): array
    {
        $pairs = array_values(array_filter($pairs, static fn (array $p): bool => $p['pubkey'] !== '' && $p['slug'] !== ''));
        if ($pairs === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('a');
        $orX = $qb->expr()->orX();
        foreach ($pairs as $i => $p) {
            $orX->add($qb->expr()->andX(
                $qb->expr()->eq('a.pubkey', ':pk'.$i),
                $qb->expr()->eq('a.slug', ':sl'.$i)
            ));
            $qb->setParameter('pk'.$i, $p['pubkey']);
            $qb->setParameter('sl'.$i, $p['slug']);
        }
        $qb->where($orX);

        /** @var list<Article> $rows */
        $rows = $qb->getQuery()->getResult();
        $out = [];
        foreach ($rows as $a) {
            $pk = (string) $a->getPubkey();
            $sl = trim((string) $a->getSlug());
            if ($sl !== '') {
                $out[$pk."\0".$sl] = $a;
            }
        }

        return $out;
    }

    /**
     * Distinct hex pubkeys for prewarming Nostr profile cache.
     *
     * @return list<string>
     */
    public function findDistinctAuthorPubkeys(): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.pubkey')
            ->distinct()
            ->where('a.pubkey IS NOT NULL')
            ->andWhere("a.pubkey != ''")
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function findOneByEventId(string $eventId): ?Article
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }

    /**
     * Find articles by author's public key
     */
    public function findByPubkey(string $pubkey, int $limit = 25): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.pubkey = :pubkey')
            ->setParameter('pubkey', $pubkey)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Published or archived long-form rows for sitemap/Atom (may include multiple rows per slug);
     * callers should dedupe by slug if URLs are slug-only.
     *
     * @return list<Article>
     */
    public function findPublishedForSyndication(int $limit = 5000): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.slug IS NOT NULL')
            ->andWhere("TRIM(a.slug) != ''")
            ->andWhere('a.eventStatus IN (:st)')
            ->setParameter('st', [EventStatusEnum::PUBLISHED, EventStatusEnum::ARCHIVED])
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
