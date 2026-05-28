<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeaturedAuthorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Site-assigned NIP-05 for authors who appear in a magazine category index.
 * Rows are only removed or deactivated manually (is_listed = false); sync only adds new pubkeys.
 */
#[ORM\Entity(repositoryClass: FeaturedAuthorRepository::class)]
#[ORM\Table(name: 'featured_author')]
#[ORM\UniqueConstraint(name: 'uniq_featured_author_mag_pubkey', columns: ['magazine_slug', 'pubkey_hex'])]
#[ORM\UniqueConstraint(name: 'uniq_featured_author_mag_local', columns: ['magazine_slug', 'local_part'])]
class FeaturedAuthor
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $magazineSlug = '';

    #[ORM\Column(length: 64)]
    private string $pubkeyHex = '';

    /**
     * NIP-05 local-part (a–z, 0–9, -, _, .) unique per magazine tenant.
     */
    #[ORM\Column(length: 100)]
    private string $localPart = '';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isListed = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMagazineSlug(): string
    {
        return $this->magazineSlug;
    }

    public function setMagazineSlug(string $magazineSlug): static
    {
        $this->magazineSlug = $magazineSlug;

        return $this;
    }

    public function getPubkeyHex(): string
    {
        return $this->pubkeyHex;
    }

    public function setPubkeyHex(string $pubkeyHex): static
    {
        $this->pubkeyHex = $pubkeyHex;

        return $this;
    }

    public function getLocalPart(): string
    {
        return $this->localPart;
    }

    public function setLocalPart(string $localPart): static
    {
        $this->localPart = $localPart;

        return $this;
    }

    public function isListed(): bool
    {
        return $this->isListed;
    }

    public function setIsListed(bool $isListed): static
    {
        $this->isListed = $isListed;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
