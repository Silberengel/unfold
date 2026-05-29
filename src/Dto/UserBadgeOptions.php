<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Layout options for {@see \App\Service\UserBadgeHtmlRenderer}.
 */
final readonly class UserBadgeOptions
{
    public const SIZE_DEFAULT = 'default';
    public const SIZE_COMPACT = 'compact';
    public const SIZE_XS = 'xs';
    public const SIZE_MD = 'md';
    public const SIZE_LG = 'lg';

    public function __construct(
        public string $size = self::SIZE_DEFAULT,
        public bool $faviconFallback = false,
        public bool $avatarOnly = false,
        public bool $linked = true,
        public bool $inlineWrapper = false,
        public string $wrapperClass = 'nostr-user-badge-inline',
        public ?string $pictureOverride = null,
        public ?string $nameOverride = null,
    ) {
    }

    public static function inline(): self
    {
        return new self(inlineWrapper: true);
    }

    public static function faviconCard(): self
    {
        return new self(faviconFallback: true);
    }

    /** Highlight marks: tiny avatar only, no label. */
    public static function highlight(): self
    {
        return new self(size: self::SIZE_XS, avatarOnly: true);
    }

    /** Sidebar avatar grid (40px). */
    public static function sidebar(): self
    {
        return new self(size: self::SIZE_MD, faviconFallback: true, avatarOnly: true);
    }

    /** Featured-author card avatar (64px). */
    public static function featuredGrid(): self
    {
        return new self(size: self::SIZE_LG, avatarOnly: true);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function fromArray(array $overrides): self
    {
        return new self(
            size: \is_string($overrides['size'] ?? null) ? $overrides['size'] : self::SIZE_DEFAULT,
            faviconFallback: (bool) ($overrides['faviconFallback'] ?? $overrides['favicon_fallback'] ?? false),
            avatarOnly: (bool) ($overrides['avatarOnly'] ?? $overrides['avatar_only'] ?? false),
            linked: (bool) ($overrides['linked'] ?? true),
            inlineWrapper: (bool) ($overrides['inlineWrapper'] ?? $overrides['inline_wrapper'] ?? false),
            wrapperClass: \is_string($overrides['wrapperClass'] ?? $overrides['wrapper_class'] ?? null)
                ? (string) ($overrides['wrapperClass'] ?? $overrides['wrapper_class'])
                : 'nostr-user-badge-inline',
            pictureOverride: \is_string($overrides['pictureOverride'] ?? $overrides['picture_override'] ?? null)
                ? (string) ($overrides['pictureOverride'] ?? $overrides['picture_override'])
                : null,
            nameOverride: \is_string($overrides['nameOverride'] ?? $overrides['name_override'] ?? null)
                ? (string) ($overrides['nameOverride'] ?? $overrides['name_override'])
                : null,
        );
    }
}
