<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Nostr\MagazineEventKeys;
use App\Service\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($registry, Event::class);
    }

    public function findOneByCoreRowKey(string $key): ?Event
    {
        return $this->findOneBy(['coreRowKey' => $key]);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, Event> keyed by coreRowKey
     */
    public function findByCoreRowKeys(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(
            $keys,
            static fn (mixed $k): bool => $k !== '',
        )));
        if ($keys === []) {
            return [];
        }
        /** @var list<Event> $rows */
        $rows = $this->createQueryBuilder('e')
            ->andWhere('e.coreRowKey IN (:keys)')
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getResult();
        $out = [];
        foreach ($rows as $row) {
            $k = $row->getCoreRowKey();
            if ($k !== null && $k !== '') {
                $out[$k] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<Event>
     */
    public function searchPublicationIndices(string $query, int $limit, int $offset): array
    {
        $qb = $this->createPublicationSearchQueryBuilder($query);
        if ($qb === null) {
            return [];
        }

        return $qb
            ->orderBy('e.created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countSearchPublicationIndices(string $query): int
    {
        $qb = $this->createPublicationSearchQueryBuilder($query);
        if ($qb === null) {
            return 0;
        }

        return (int) $qb
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createPublicationSearchQueryBuilder(string $query): ?\Doctrine\ORM\QueryBuilder
    {
        $prefix = MagazineEventKeys::tenantPrefix($this->tenant->getMagazineSlug()).'pub:';
        $qb = $this->createQueryBuilder('e');
        $qb
            ->andWhere('e.storageRole = :role')
            ->andWhere('e.coreRowKey LIKE :pfx')
            ->setParameter('role', Event::STORAGE_PUBLICATION_INDEX)
            ->setParameter('pfx', $prefix.'%');

        $searchTerms = explode(' ', trim($query));
        $conditions = $qb->expr()->orX();
        foreach ($searchTerms as $index => $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            $param = 'pt'.$index;
            $conditions->add($qb->expr()->like('e.content', ':'.$param));
            $conditions->add($qb->expr()->like('e.tags', ':'.$param));
            $qb->setParameter($param, '%'.$term.'%');
        }
        if (\count($conditions->getParts()) === 0) {
            return null;
        }
        $qb->andWhere($conditions);

        return $qb;
    }
}
