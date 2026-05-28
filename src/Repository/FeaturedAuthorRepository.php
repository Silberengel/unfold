<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeaturedAuthor;
use App\Service\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeaturedAuthor>
 */
class FeaturedAuthorRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($registry, FeaturedAuthor::class);
    }

    public function findOneByPubkeyHex(string $pubkeyHex): ?FeaturedAuthor
    {
        $h = strtolower($pubkeyHex);

        return $this->findOneBy([
            'magazineSlug' => $this->tenant->getMagazineSlug(),
            'pubkeyHex' => $h,
        ]);
    }

    public function isLocalPartTaken(string $localPart, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.magazineSlug = :mag')
            ->andWhere('f.localPart = :lp')
            ->setParameter('mag', $this->tenant->getMagazineSlug())
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
            ->where('f.magazineSlug = :mag')
            ->andWhere('f.isListed = :t')
            ->setParameter('mag', $this->tenant->getMagazineSlug())
            ->setParameter('t', true)
            ->orderBy('f.localPart', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Listed authors who first appeared in a category index, most recently added first.
     * {@see FeaturedAuthor::createdAt} is set when the row is created (sync discovered the pubkey in an `a` tag).
     *
     * @return list<FeaturedAuthor>
     */
    public function findListedMostRecentlyAdded(int $limit, int $offset = 0): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.magazineSlug = :mag')
            ->andWhere('f.isListed = :t')
            ->setParameter('mag', $this->tenant->getMagazineSlug())
            ->setParameter('t', true)
            ->orderBy('f.createdAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<FeaturedAuthor>
     */
    public function findListedOrderByLocalPartPaginated(int $limit, int $offset): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.magazineSlug = :mag')
            ->andWhere('f.isListed = :t')
            ->setParameter('mag', $this->tenant->getMagazineSlug())
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
            ->where('f.magazineSlug = :mag')
            ->andWhere('f.isListed = :t')
            ->setParameter('mag', $this->tenant->getMagazineSlug())
            ->setParameter('t', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<FeaturedAuthor>
     */
    public function findAllForTenant(): array
    {
        return $this->findBy(['magazineSlug' => $this->tenant->getMagazineSlug()]);
    }
}
