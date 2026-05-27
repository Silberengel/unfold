<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
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
            static fn (mixed $k): bool => \is_string($k) && $k !== '',
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
}
