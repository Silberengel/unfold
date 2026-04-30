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
     * Same asset as the header mark so empty hero slots read as the site, not a blank gray field.
     */
    private const DEFAULT_PACKAGE_IMAGE = 'icons/favicon-96x96.png';

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
            new TwigFunction('article_og_image', $this->articleOgImage(...)),
        ];
    }

    /**
     * Absolute URL + whether to emit og:image:width/height (1200×630) for the site default OG JPEG only.
     *
     * @return array{href: string, use_default_dimensions: bool}
     */
    public function articleOgImage(?string $articleImage, ?string $pubkeyHex): array
    {
        $cover = $this->articleCardCover($articleImage, $pubkeyHex);
        $defaultCover = $this->defaultSiteImageUrl();
        $useDefaultDimensions = false;
        $chosen = $cover;
        if ($chosen === $defaultCover) {
            try {
                $chosen = $this->packages->getUrl(self::OG_FALLBACK_PACKAGE_IMAGE);
                $useDefaultDimensions = true;
            } catch (Throwable) {
                $useDefaultDimensions = false;
            }
        }

        return [
            'href' => $this->absolutizeForOpenGraph($chosen),
            'use_default_dimensions' => $useDefaultDimensions,
        ];
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
}
