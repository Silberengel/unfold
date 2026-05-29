<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArticleHighlightRepository;
use App\Util\HighlightEventTags;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nostr kind 9802 (highlight) events that reference a long-form article by `a` / `A` address.
 * Ingested from relays and served from MySQL (not from the comment-thread cache).
 */
#[ORM\Entity(repositoryClass: ArticleHighlightRepository::class)]
#[ORM\Table(name: 'article_highlight')]
#[ORM\Index(name: 'IDX_highlight_event_created', columns: ['event_created_at'])]
class ArticleHighlight
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    /** Event id (hex, lowercase) — globally unique. */
    #[ORM\Column(length: 64, unique: true)]
    private string $eventId = '';

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: null)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Article $article = null;

    /** Pubkey (hex) of the account that created the highlight. */
    #[ORM\Column(length: 64)]
    private string $authorPubkey = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    /** Full tag array as returned on the wire (includes textquoteselector, a/A, etc.). */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    /** Nostr `created_at` (unix seconds). */
    #[ORM\Column(type: Types::BIGINT)]
    private int $eventCreatedAt = 0;

    /** Short quote line for list UI / deep-link hint (from textquoteselector or content). */
    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    private ?string $quoteExcerpt = null;

    /** Kind-0 display name at last highlight sync (denormalized for fast avatar rendering). */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $authorDisplayName = null;

    /** Kind-0 picture URL at last highlight sync (denormalized for fast avatar rendering). */
    #[ORM\Column(type: Types::STRING, length: 2048, nullable: true)]
    private ?string $authorPictureUrl = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): static
    {
        $this->eventId = strtolower($eventId);

        return $this;
    }

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(?Article $article): static
    {
        $this->article = $article;

        return $this;
    }

    public function getAuthorPubkey(): string
    {
        return $this->authorPubkey;
    }

    public function setAuthorPubkey(string $authorPubkey): static
    {
        $this->authorPubkey = $authorPubkey;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }

    public function getEventCreatedAt(): int
    {
        return $this->eventCreatedAt;
    }

    public function setEventCreatedAt(int $eventCreatedAt): static
    {
        $this->eventCreatedAt = $eventCreatedAt;

        return $this;
    }

    public function getQuoteExcerpt(): ?string
    {
        return $this->quoteExcerpt;
    }

    public function setQuoteExcerpt(?string $quoteExcerpt): static
    {
        $this->quoteExcerpt = $quoteExcerpt;

        return $this;
    }

    public function getAuthorDisplayName(): ?string
    {
        return $this->authorDisplayName;
    }

    public function setAuthorDisplayName(?string $authorDisplayName): static
    {
        $this->authorDisplayName = $authorDisplayName;

        return $this;
    }

    public function getAuthorPictureUrl(): ?string
    {
        return $this->authorPictureUrl;
    }

    public function setAuthorPictureUrl(?string $authorPictureUrl): static
    {
        $this->authorPictureUrl = $authorPictureUrl;

        return $this;
    }

    /** The full quote from the `context` tag (empty if absent). */
    public function getContextText(): string
    {
        return HighlightEventTags::contextFromTags($this->tags);
    }

    /**
     * Card body HTML (home aside, line-clamp): `context` = full quote, `content` = highlighted part.
     * If there is no `context` (or it is empty), the passage is the same as `content`. The passage
     * is aligned so the clamped block starts at the highlight, not with long unmarked lead-in text.
     */
    public function getBodyHtml(): string
    {
        $c = (string) $this->getContent();

        return HighlightEventTags::buildHighlightedBodyHtmlForNarrowList(
            HighlightEventTags::fullPassageForHighlightDisplay($c, $this->tags),
            $c,
            0
        );
    }
}
