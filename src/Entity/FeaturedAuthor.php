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
class FeaturedAuthor
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $pubkeyHex = '';

    /**
     * NIP-05 local-part (a–z, 0–9, -, _, .) unique across all rows.
     */
    #[ORM\Column(length: 100, unique: true)]
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
