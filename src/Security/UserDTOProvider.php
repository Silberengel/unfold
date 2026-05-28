<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserEntityRepository;
use App\Service\CacheService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Provides user data transfer object (DTO) operations for authentication and user management.
 *
 * This class is responsible for refreshing user data from the database and cache,
 * and for determining if a given class is supported by the provider.
 */
readonly class UserDTOProvider implements UserProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserEntityRepository $userRepository,
        private CacheService $cacheService,
        private LoggerInterface $logger,
        private TenantContext $tenant,
    ) {
    }

    /**
     * Refreshes the user by reloading it from the database and updating its metadata from cache.
     *
     * @param UserInterface $user The user to refresh.
     * @return UserInterface The refreshed user instance.
     * @throws \InvalidArgumentException If the provided user is not an instance of User.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException('Invalid user type.');
        }
        $this->logger->info('Refresh user.', ['user' => $user->getUserIdentifier()]);
        $freshUser = $this->userRepository->findOneByNpub($user->getUserIdentifier());
        if ($freshUser === null) {
            throw new \InvalidArgumentException('User not found for this magazine tenant.');
        }
        $metadata = $this->cacheService->getMetadata($user->getUserIdentifier());
        $freshUser->setMetadata($metadata);

        return $freshUser;
    }

    /**
     * @inheritDoc
     */
    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }

    /**
     * @inheritDoc
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $this->logger->info('Load user by identifier.', ['identifier' => $identifier]);
        $user = $this->userRepository->findOneByNpub($identifier);

        if (!$user) {
            $user = new User();
            $user->setMagazineSlug($this->tenant->getMagazineSlug());
            $user->setNpub($identifier);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        $metadata = $this->cacheService->getMetadata($identifier);
        $user->setMetadata($metadata);
        $this->logger->debug('User metadata set.', ['metadata' => json_encode($user->getMetadata())]);

        return $user;
    }
}
