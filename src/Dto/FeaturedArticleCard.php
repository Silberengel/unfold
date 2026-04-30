<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Article;

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
        private ?\DateTimeImmutable $publishedAt,
        private ?string $pubkey,
    ) {
    }

    public static function fromArticle(Article $a): self
    {
        $rawId = $a->getId();
        $id = null;
        if (\is_int($rawId)) {
            $id = $rawId;
        } elseif (\is_string($rawId) && ctype_digit($rawId)) {
            $id = (int) $rawId;
        }

        return new self(
            $id,
            $a->getSlug(),
            $a->getTitle(),
            $a->getSummary(),
            $a->getImage(),
            $a->getCreatedAt(),
            $a->getPublishedAt(),
            $a->getPubkey(),
        );
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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getDisplayAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt ?? $this->createdAt;
    }

    public function getPubkey(): ?string
    {
        return $this->pubkey;
    }
}
