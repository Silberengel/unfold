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

    /** The full quote from the optional `context` tag. Event `content` is highlighted *inside* this when present. */
    public function getContextText(): string
    {
        return HighlightEventTags::contextFromTags($this->tags);
    }

    /**
     * HTML for the home aside and article hover cards: when a `context` tag exists, the full quote is
     * shown with `content` marked inside it; otherwise the event `content` only in a <mark>. The
     * rendered article body still only wraps the `content` passage (see ArticleBodyHighlightInjector).
     */
    public function getBodyHtml(): string
    {
        return HighlightEventTags::buildHighlightedBodyHtml(
            $this->getContextText(),
            (string) $this->getContent()
        );
    }
}
