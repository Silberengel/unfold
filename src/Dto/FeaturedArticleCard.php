<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Minimal article row for home/category list cards (avoids loading long-form `content` from the DB).
 */
final readonly class FeaturedArticleCard
{
    public function __construct(
        private ?int $id,
        private ?string $slug,
        private ?string $title,
        private ?string $summary,
        private ?string $image,
        private ?\DateTimeImmutable $createdAt,
        private ?string $pubkey,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPubkey(): ?string
    {
        return $this->pubkey;
    }
}
