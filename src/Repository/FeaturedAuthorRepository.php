<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeaturedAuthor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeaturedAuthor>
 */
class FeaturedAuthorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeaturedAuthor::class);
    }

    public function findOneByPubkeyHex(string $pubkeyHex): ?FeaturedAuthor
    {
        $h = strtolower($pubkeyHex);

        return $this->findOneBy(['pubkeyHex' => $h]);
    }

    public function isLocalPartTaken(string $localPart, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.localPart = :lp')
            ->setParameter('lp', $localPart);
        if ($exceptId !== null) {
            $qb->andWhere('f.id != :eid')->setParameter('eid', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @return list<FeaturedAuthor>
     */
    public function findAllListedOrderByLocalPart(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.isListed = :t')
            ->setParameter('t', true)
            ->orderBy('f.localPart', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<FeaturedAuthor>
     */
    public function findListedOrderByLocalPartPaginated(int $limit, int $offset): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.isListed = :t')
            ->setParameter('t', true)
            ->orderBy('f.localPart', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countListed(): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.isListed = :t')
            ->setParameter('t', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

}
