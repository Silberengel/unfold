<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nostr events stored in MySQL (kind-0 profiles, 30040 indices, kind-3 relay lists, etc.).
 * Ephemeral reply/comment UI data must not use this table.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
class Event
{
    public const STORAGE_MAGAZINE_ROOT = 'magazine_root';

    public const STORAGE_MAGAZINE_CATEGORY = 'magazine_category';

    public const STORAGE_PROFILE_KIND0 = 'profile';

    public const STORAGE_RELAY_LIST_10002 = 'relay_list';

    public const STORAGE_PAYTO_10133 = 'payto_10133';

    #[ORM\Id]
    #[ORM\Column(length: 225)]
    private string $id;
    #[ORM\Column(length: 225, nullable: true)]
    private ?string $eventId = null;
    #[ORM\Column(type: Types::INTEGER)]
    private int $kind = 0;
    #[ORM\Column(length: 255)]
    private string $pubkey = '';
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';
    #[ORM\Column(type: Types::BIGINT)]
    private int $created_at = 0;
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];
    #[ORM\Column(length: 255)]
    private string $sig = '';

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $coreRowKey = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $storageRole = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): static
    {
        $this->eventId = $eventId;

        return $this;
    }

    public function getKind(): int
    {
        return $this->kind;
    }

    public function setKind(int $kind): void
    {
        $this->kind = $kind;
    }

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    public function setPubkey(string $pubkey): void
    {
        $this->pubkey = $pubkey;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getCreatedAt(): int
    {
        return $this->created_at;
    }

    public function setCreatedAt(int $created_at): void
    {
        $this->created_at = $created_at;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    public function getSig(): string
    {
        return $this->sig;
    }

    public function setSig(string $sig): void
    {
        $this->sig = $sig;
    }

    public function getCoreRowKey(): ?string
    {
        return $this->coreRowKey;
    }

    public function setCoreRowKey(?string $coreRowKey): void
    {
        $this->coreRowKey = $coreRowKey;
    }

    public function getStorageRole(): ?string
    {
        return $this->storageRole;
    }

    public function setStorageRole(?string $storageRole): void
    {
        $this->storageRole = $storageRole;
    }

    public function getTitle(): ?string
    {
        foreach ($this->getTags() as $tag) {
            if (array_key_first($tag) === 'title') {
                return $tag['title'];
            }
        }
        return null;
    }

    public function getSummary(): ?string
    {
        foreach ($this->getTags() as $tag) {
            if ($tag[0] === 'summary') {
                return $tag[1];
            }
        }
        return null;
    }

    public function getSlug(): ?string
    {
        foreach ($this->getTags() as $tag) {
            if ($tag[0] === 'd') {
                return $tag[1];
            }
        }

        return null;
    }

    public function addTag(array $tag): static
    {
        $this->tags[] = $tag;

        return $this;
    }
}
