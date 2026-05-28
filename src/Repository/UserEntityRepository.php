<?php

namespace App\Repository;

use App\Entity\User;
use App\Service\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserEntityRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($registry, User::class);
    }

    public function findOneByNpub(string $npub): ?User
    {
        return $this->findOneBy([
            'magazineSlug' => $this->tenant->getMagazineSlug(),
            'npub' => $npub,
        ]);
    }
}
