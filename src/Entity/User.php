<?php

namespace App\Entity;

use App\Repository\UserEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Entity storing local user representations
 */
#[ORM\Entity(repositoryClass: UserEntityRepository::class)]
#[ORM\Table(name: "app_user")]
#[ORM\UniqueConstraint(name: 'uniq_app_user_mag_npub', columns: ['magazine_slug', 'npub'])]
class User implements UserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $magazineSlug = '';

    #[ORM\Column]
    private ?string $npub = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $roles = [];

    private $metadata = null;
    private $relays = null;

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return $roles;
    }

    public function setRoles(?array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function addRole(string $role): self
    {
        if (!in_array($role, $this->roles)) {
            $this->roles[] = $role;
        }

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getNpub(): ?string
    {
        return $this->npub;
    }

    public function setNpub(?string $npub): void
    {
        $this->npub = $npub;
    }

    public function getMagazineSlug(): string
    {
        return $this->magazineSlug;
    }

    public function setMagazineSlug(string $magazineSlug): void
    {
        $this->magazineSlug = $magazineSlug;
    }

    public function eraseCredentials(): void
    {
        $this->metadata = null;
        $this->relays = null;
    }

    public function getUserIdentifier(): string
    {
        return $this->getNpub();
    }

    public function setMetadata(?object $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getMetadata(): ?object
    {
        return $this->metadata;
    }

    public function setRelays(?array $relays): void
    {
        $this->relays = $relays;
    }

    public function getRelays(): ?array
    {
        return $this->relays;
    }

    public function getName(): ?string
    {
        return $this->getMetadata()->name ?? $this->getUserIdentifier();
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $this->getUserIdentifier() === $user->getUserIdentifier();
    }

    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'magazineSlug' => $this->magazineSlug,
            'npub' => $this->npub,
            'roles' => $this->roles,
            'metadata' => $this->metadata,
            'relays' => $this->relays
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->magazineSlug = $data['magazineSlug'] ?? '';
        $this->npub = $data['npub'];
        $this->roles = $data['roles'];
        $this->metadata = $data['metadata'];
        $this->relays = $data['relays'];
    }
}
