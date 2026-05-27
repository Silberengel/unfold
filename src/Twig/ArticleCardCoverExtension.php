<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CacheService;
use App\Service\NostrPathHelper;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Resolves a card hero image: article image, Nostr kind-0 profile {@see picture}, then site default image.
 */
final class ArticleCardCoverExtension extends AbstractExtension
{
    /**
     * Used when the article has no image and the author has no (or no usable) NIP-01 {@see picture} URL.
     * The portrait painting is shown at low opacity with a CSS pattern overlay (see `.card-header--no-cover`).
     */
    private const DEFAULT_PACKAGE_IMAGE = 'laeserin_logo.png';

    private const OG_FALLBACK_PACKAGE_IMAGE = 'og-image.jpg';

    /**
     * @var array<string, string> lowercase 64-hex pubkey → resolved cover URL (author picture or site default)
     */
    private array $authorCoverMemo = [];

    public function __construct(
        private readonly CacheService $cacheService,
        private readonly NostrPathHelper $nostrPathHelper,
        private readonly Packages $packages,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('article_card_cover', $this->articleCardCover(...)),
            new TwigFunction('prefetch_article_card_covers', $this->prefetchArticleCardCovers(...)),
            new TwigFunction('article_og_image', $this->articleOgImage(...)),
            new TwigFunction('site_og_image', $this->siteOgImage(...)),
        ];
    }

    /**
     * Batch kind-0 profile lookups before a list of cards (one relay REQ per chunk, not per tile).
     *
     * @param iterable<mixed> $items Rows with optional `pubkey` (64-char hex)
     */
    public function prefetchArticleCardCovers(iterable $items): void
    {
        $hexes = [];
        foreach ($items as $item) {
            if (\is_object($item) && isset($item->article)) {
                $item = $item->article;
            } elseif (\is_array($item) && isset($item['article'])) {
                $item = $item['article'];
            }
            $hex = $this->pubkeyHexFromItem($item);
            if ($hex !== null) {
                $hexes[] = $hex;
            }
        }
        $this->cacheService->prefetchMetadataForPubkeyHexes($hexes);
    }

    /**
     * Branded site Open Graph image (home, category lists, base layout default): not tied to any article or author.
     *
     * @return array{href: string, use_default_dimensions: bool}
     */
    public function siteOgImage(): array
    {
        $useDefaultDimensions = false;
        try {
            $chosen = $this->packages->getUrl(self::OG_FALLBACK_PACKAGE_IMAGE);
            $useDefaultDimensions = true;
        } catch (Throwable) {
            $chosen = $this->defaultSiteImageUrl();
        }

        return [
            'href' => $this->absolutizeForOpenGraph($chosen),
            'use_default_dimensions' => $useDefaultDimensions,
        ];
    }

    /**
     * Absolute URL + whether to emit og:image:width/height (1200×630) for the site default OG JPEG only.
     *
     * Unlike {@see articleCardCover}, this never uses the author’s Nostr profile picture: crawlers and
     * in-app link previews should get the article hero or the branded {@see OG_FALLBACK_PACKAGE_IMAGE}.
     *
     * @return array{href: string, use_default_dimensions: bool}
     */
    public function articleOgImage(?string $articleImage, ?string $_pubkeyHex): array
    {
        if ($articleImage !== null && trim($articleImage) !== '') {
            return [
                'href' => $this->absolutizeForOpenGraph(trim($articleImage)),
                'use_default_dimensions' => false,
            ];
        }

        return $this->siteOgImage();
    }

    /**
     * Crawlers require absolute https URLs; article/profile images are often https, //, or site-relative.
     */
    private function absolutizeForOpenGraph(string $urlOrPath): string
    {
        $u = trim($urlOrPath);
        if ($u === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $u) === 1) {
            return $u;
        }
        if (str_starts_with($u, '//')) {
            return 'https:'.$u;
        }
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return $u;
        }
        $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();
        if (str_starts_with($u, '/')) {
            return $base.$u;
        }

        return $base.'/'.ltrim($u, '/');
    }

    /**
     * @param string|null $articleImage Cover URL stored on the article, if any
     * @param string|null $pubkeyHex    64-char hex (lowercase) Nostr public key, if any
     */
    public function articleCardCover(?string $articleImage, ?string $pubkeyHex): string
    {
        if ($articleImage !== null && trim($articleImage) !== '') {
            return trim($articleImage);
        }

        $pubkeyHex = $pubkeyHex !== null ? strtolower(trim($pubkeyHex)) : '';
        if (64 !== strlen($pubkeyHex) || !ctype_xdigit($pubkeyHex)) {
            return $this->defaultSiteImageUrl();
        }

        if (\array_key_exists($pubkeyHex, $this->authorCoverMemo)) {
            return $this->authorCoverMemo[$pubkeyHex];
        }

        try {
            $npub = $this->nostrPathHelper->npubFromPubkeyHex($pubkeyHex);
            if ($npub === '') {
                $url = $this->defaultSiteImageUrl();
                $this->authorCoverMemo[$pubkeyHex] = $url;

                return $url;
            }

            $meta = $this->cacheService->getMetadata($npub);
            $pic = isset($meta->picture) ? trim((string) $meta->picture) : '';
            if ($pic !== '') {
                $this->authorCoverMemo[$pubkeyHex] = $pic;

                return $pic;
            }
        } catch (Throwable) {
            $out = $this->defaultSiteImageUrl();
            $this->authorCoverMemo[$pubkeyHex] = $out;

            return $out;
        }

        $out = $this->defaultSiteImageUrl();
        $this->authorCoverMemo[$pubkeyHex] = $out;

        return $out;
    }

    private function defaultSiteImageUrl(): string
    {
        return $this->packages->getUrl(self::DEFAULT_PACKAGE_IMAGE);
    }

    private function pubkeyHexFromItem(mixed $item): ?string
    {
        $raw = null;
        if (\is_object($item) && isset($item->pubkey)) {
            $raw = $item->pubkey;
        } elseif (\is_array($item) && isset($item['pubkey'])) {
            $raw = $item['pubkey'];
        }
        if (!\is_string($raw)) {
            return null;
        }
        $hex = strtolower(trim($raw));
        if (64 !== \strlen($hex) || !ctype_xdigit($hex)) {
            return null;
        }

        return $hex;
    }
}
