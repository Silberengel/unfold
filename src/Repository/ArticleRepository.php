<?php

namespace App\Repository;

use App\Dto\FeaturedArticleCard;
use App\Entity\Article;
use App\Enum\EventStatusEnum;
use App\Service\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($registry, Article::class);
    }

    /**
     * Search articles by title, content, and summary using database LIKE queries
     */
    public function searchArticles(string $query, int $limit = 12, int $offset = 0): array
    {
        $qb = $this->tenantQueryBuilder('a');

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

    public function countSearchArticles(string $query): int
    {
        $qb = $this->tenantQueryBuilder('a')
            ->select('COUNT(a.id)');

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

        if (\count($conditions->getParts()) === 0) {
            return 0;
        }

        return (int) $qb
            ->where($conditions)
            ->andWhere('a.content IS NOT NULL')
            ->andWhere('LENGTH(a.content) > 250')
            ->getQuery()
            ->getSingleScalarResult();
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
            ->select('a.id', 'a.slug', 'a.title', 'a.summary', 'a.image', 'a.created_at', 'a.published_at', 'a.pubkey')
            ->from('article', 'a')
            ->innerJoin('a', 'article_magazine', 'am', 'am.article_id = a.id AND am.magazine_slug = :mag')
            ->where($qb->expr()->in('a.slug', ':slugs'))
            ->setParameter('mag', $this->tenant->getMagazineSlug())
            ->setParameter('slugs', $slugs, ArrayParameterType::STRING)
            ->orderBy('a.created_at', 'DESC');

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $ca = $row['created_at'] ?? null;
            $pa = $row['published_at'] ?? null;
            $out[] = new FeaturedArticleCard(
                isset($row['id']) ? (int) $row['id'] : null,
                isset($row['slug']) ? (string) $row['slug'] : null,
                isset($row['title']) ? (string) $row['title'] : null,
                isset($row['summary']) ? (string) $row['summary'] : null,
                isset($row['image']) ? (string) $row['image'] : null,
                $ca !== null && $ca !== '' ? new \DateTimeImmutable((string) $ca) : null,
                $pa !== null && $pa !== '' ? new \DateTimeImmutable((string) $pa) : null,
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

        $qb = $this->tenantQueryBuilder('a');
        $orX = $qb->expr()->orX();
        foreach ($pairs as $i => $p) {
            $pkQ = strtolower((string) $p['pubkey']);
            $orX->add($qb->expr()->andX(
                $qb->expr()->eq('a.pubkey', ':pk'.$i),
                $qb->expr()->eq('a.slug', ':sl'.$i)
            ));
            $qb->setParameter('pk'.$i, $pkQ);
            $qb->setParameter('sl'.$i, $p['slug']);
        }
        $qb->where($orX);

        /** @var list<Article> $rows */
        $rows = $qb->getQuery()->getResult();
        $out = [];
        foreach ($rows as $a) {
            $pk = strtolower((string) $a->getPubkey());
            $sl = trim((string) $a->getSlug());
            if ($sl !== '') {
                $out[$pk."\0".$sl] = $a;
            }
        }

        return $out;
    }

    /**
     * Distinct hex pubkeys for prewarming Nostr profile cache (this magazine tenant only).
     *
     * @return list<string>
     */
    public function findDistinctAuthorPubkeys(): array
    {
        return $this->tenantQueryBuilder('a')
            ->select('a.pubkey')
            ->distinct()
            ->where('a.pubkey IS NOT NULL')
            ->andWhere("a.pubkey != ''")
            ->getQuery()
            ->getSingleColumnResult();
    }

    /** Global lookup by Nostr event id (shared across magazine tenants). */
    public function findOneByEventId(string $eventId): ?Article
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }

    /**
     * Newest row for a NIP-23/24 `d` value linked to this magazine tenant.
     */
    public function findLatestBySlug(string $slug): ?Article
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        return $this->tenantQueryBuilder('a')
            ->where('a.slug = :slug')
            ->setParameter('slug', $slug)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestBySlugForTenant(string $slug, string $npubHex): ?Article
    {
        $slug = trim($slug);
        if ($slug === '' || $npubHex === '') {
            return null;
        }

        return $this->tenantQueryBuilder('a')
            ->where('a.slug = :slug')
            ->andWhere('LOWER(a.pubkey) = :pk')
            ->setParameter('slug', $slug)
            ->setParameter('pk', strtolower($npubHex))
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByPubkeyPaginated(string $pubkey, int $limit, int $offset): array
    {
        return $this->tenantQueryBuilder('a')
            ->where('a.pubkey = :pubkey')
            ->setParameter('pubkey', $pubkey)
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByPubkey(string $pubkey): int
    {
        return (int) $this->tenantQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.pubkey = :pubkey')
            ->setParameter('pubkey', $pubkey)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForMagazine(): int
    {
        return (int) $this->tenantQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Article>
     */
    public function findForMagazinePaginated(int $limit, int $offset): array
    {
        return $this->tenantQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
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
        return $this->tenantQueryBuilder('a')
            ->where('a.slug IS NOT NULL')
            ->andWhere("TRIM(a.slug) != ''")
            ->andWhere('a.eventStatus IN (:st)')
            ->setParameter('st', [EventStatusEnum::PUBLISHED, EventStatusEnum::ARCHIVED])
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Published or archived long-form with at least one stored topic, matched case-insensitively.
     * Ordered newest first. Uses an in-process filter; suitable for moderate table sizes.
     *
     * @return list<Article>
     */
    public function findPublishedByTopic(string $topic, int $limit, int $offset): array
    {
        $all = $this->articlesMatchingTopicNormalized(
            $this->normalizeTopicLabel($topic)
        );

        return \array_slice($all, $offset, $limit);
    }

    public function countPublishedByTopic(string $topic): int
    {
        return \count(
            $this->articlesMatchingTopicNormalized(
                $this->normalizeTopicLabel($topic)
            )
        );
    }

    /**
     * @return list<Article>
     */
    private function articlesMatchingTopicNormalized(string $topicKey): array
    {
        if ($topicKey === '') {
            return [];
        }
        $qb = $this->tenantQueryBuilder('a')
            ->where('a.topics IS NOT NULL')
            ->andWhere('a.content IS NOT NULL')
            ->andWhere('LENGTH(a.content) > 250')
            ->andWhere('a.eventStatus IN (:st)')
            ->setParameter('st', [EventStatusEnum::PUBLISHED, EventStatusEnum::ARCHIVED])
            ->orderBy('a.createdAt', 'DESC');

        /** @var list<Article> $candidates */
        $candidates = $qb->getQuery()->getResult();
        $out = [];
        foreach ($candidates as $a) {
            $topics = $a->getTopics();
            if (!\is_array($topics) || $topics === []) {
                continue;
            }
            foreach ($topics as $t) {
                if (!\is_string($t)) {
                    continue;
                }
                $k = $this->normalizeTopicLabel($t);
                if ($k === $topicKey) {
                    $out[] = $a;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Public key for {@see findPublishedByTopic} and generating `/topic/…` URLs.
     */
    public function normalizeTopicParam(string $topic): string
    {
        return $this->normalizeTopicLabel($topic);
    }

    private function normalizeTopicLabel(string $topic): string
    {
        $t = \strtolower(\trim($topic));
        if ($t === '') {
            return '';
        }
        if (\str_starts_with($t, '#')) {
            $t = ltrim($t, '#');
        }

        return \trim($t);
    }

    private function tenantQueryBuilder(string $alias = 'a'): QueryBuilder
    {
        $qb = $this->createQueryBuilder($alias);
        $qb->innerJoin(
            'App\Entity\ArticleMagazine',
            'am',
            'WITH',
            'am.article = '.$alias.' AND am.magazineSlug = :_magazine_slug'
        );
        $qb->setParameter('_magazine_slug', $this->tenant->getMagazineSlug());

        return $qb;
    }
}
