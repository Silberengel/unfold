<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\UserBadgeOptions;
use App\Nostr\Nip19Codec;
use App\Util\ProfileDisplayName;
use App\Util\PubkeyAvatarSvg;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Server-side HTML for user badges (npub / hex pubkey / nprofile).
 */
final class UserBadgeHtmlRenderer
{
    private const FAVICON_ASSET = 'icons/favicon-96x96.png';

    public function __construct(
        private readonly HighlightAuthorMetadataProvider $metadataProvider,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly Nip19Codec $nip19,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Packages $assetPackages,
    ) {
    }

    public function render(string $ident, ?UserBadgeOptions $options = null): string
    {
        $options ??= new UserBadgeOptions();
        $npub = $this->resolveNpub($ident);
        if ($npub === '') {
            return htmlspecialchars(trim($ident), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $pubkey = $this->resolvePubkeyHex($ident, $npub);
        $user = $this->metadataProvider->getMetadata($npub);
        $name = trim((string) ($options->nameOverride ?? ''));
        if ($name === '') {
            $name = ProfileDisplayName::resolve($user, $npub);
        }
        $href = $this->urlGenerator->generate('author-profile', ['npub' => $npub]);
        $badgeClass = $this->badgeClass($options);
        $avatarHtml = $this->renderAvatar($user, $pubkey, $npub, $options);
        $nameHtml = $options->avatarOnly
            ? '<span class="visually-hidden">'.$this->escape($name).'</span>'
            : '<span class="user-badge__name">'.$this->escape($name).'</span>';

        $html = $options->linked
            ? sprintf(
                '<a href="%s" class="%s" title="%s">%s%s</a>',
                $this->escapeAttr($href),
                $this->escapeAttr($badgeClass),
                $this->escapeAttr($name),
                $avatarHtml,
                $nameHtml,
            )
            : sprintf(
                '<span class="%s" title="%s">%s%s</span>',
                $this->escapeAttr($badgeClass),
                $this->escapeAttr($name),
                $avatarHtml,
                $nameHtml,
            );

        if ($options->inlineWrapper) {
            return sprintf(
                '<span class="%s">%s</span>',
                $this->escapeAttr($options->wrapperClass),
                $html,
            );
        }

        return $html;
    }

    private function badgeClass(UserBadgeOptions $options): string
    {
        $classes = ['user-badge'];
        if ($options->size !== UserBadgeOptions::SIZE_DEFAULT) {
            $classes[] = 'user-badge--'.$options->size;
        }
        if ($options->avatarOnly) {
            $classes[] = 'user-badge--avatar-only';
        }
        if ($options->faviconFallback) {
            $classes[] = 'user-badge--favicon-fallback';
        }

        return implode(' ', $classes);
    }

    private function renderAvatar(\stdClass $user, string $pubkey, string $npub, UserBadgeOptions $options): string
    {
        if ($options->faviconFallback) {
            $avatarUrl = trim((string) ($options->pictureOverride ?? ''));
            if ($avatarUrl === '') {
                $avatarUrl = $this->resolveAvatarUrl($user);
            }
            $favicon = $this->assetPackages->getUrl(self::FAVICON_ASSET);

            return sprintf(
                '<span class="user-badge__avatar user-badge__avatar--wrap" aria-hidden="true">'
                .'<img class="user-badge__avatar-img" src="%s" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'%s\';" />'
                .'</span>',
                $this->escapeAttr($avatarUrl !== '' ? $avatarUrl : $favicon),
                $this->escapeAttr($favicon),
            );
        }

        $avatarUrl = trim((string) ($options->pictureOverride ?? ''));
        if ($avatarUrl === '') {
            $avatarUrl = $this->resolveAvatarUrl($user);
        }

        return $avatarUrl !== ''
            ? $this->renderAvatarWithImage($avatarUrl, $pubkey, $npub)
            : $this->renderGeneratedAvatar($pubkey, $npub);
    }

    private function resolveNpub(string $ident): string
    {
        $ident = trim($ident);
        if ($ident === '') {
            return '';
        }
        if (str_starts_with($ident, 'npub1')) {
            return $ident;
        }
        if (str_starts_with($ident, 'nprofile1')) {
            try {
                $decoded = $this->nip19->decode($ident);
                if ($decoded->type === 'nprofile' && isset($decoded->data->pubkey)) {
                    return $this->nostrKeyHelper->convertPublicKeyToBech32((string) $decoded->data->pubkey);
                }
            } catch (\Throwable) {
            }

            return '';
        }
        if (64 === strlen($ident) && ctype_xdigit($ident)) {
            try {
                return $this->nostrKeyHelper->convertPublicKeyToBech32(strtolower($ident));
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }

    private function resolvePubkeyHex(string $ident, string $npub): string
    {
        if (str_starts_with($ident, 'nprofile1')) {
            try {
                $decoded = $this->nip19->decode($ident);
                if ($decoded->type === 'nprofile' && isset($decoded->data->pubkey)) {
                    $hex = strtolower((string) $decoded->data->pubkey);
                    if (64 === strlen($hex) && ctype_xdigit($hex)) {
                        return $hex;
                    }
                }
            } catch (\Throwable) {
            }
        }
        if (64 === strlen($ident) && ctype_xdigit($ident)) {
            return strtolower($ident);
        }
        try {
            $hex = $this->nostrKeyHelper->convertToHex($npub);
        } catch (\Throwable) {
            return hash('sha256', $npub, false);
        }

        return (64 === strlen($hex) && ctype_xdigit($hex)) ? strtolower($hex) : hash('sha256', $npub, false);
    }

    private function resolveAvatarUrl(\stdClass $user): string
    {
        $picture = trim((string) ($user->picture ?? ''));
        if ($picture !== '') {
            return $picture;
        }

        return trim((string) ($user->image ?? ''));
    }

    private function renderAvatarWithImage(string $avatarUrl, string $pubkey, string $npub): string
    {
        $seed = (64 === strlen($pubkey) && ctype_xdigit($pubkey)) ? $pubkey : hash('sha256', $npub, false);
        $fallbackSvg = PubkeyAvatarSvg::generate($seed);

        return sprintf(
            '<span class="user-badge__avatar user-badge__avatar--wrap" aria-hidden="true">'
            .'<img class="user-badge__avatar-img" src="%s" alt="" loading="lazy" decoding="async" onerror="this.classList.add(\'is-broken\')" />'
            .'<span class="user-badge__avatar--generated user-badge__avatar-fallback">%s</span>'
            .'</span>',
            $this->escapeAttr($avatarUrl),
            $fallbackSvg,
        );
    }

    private function renderGeneratedAvatar(string $pubkey, string $npub): string
    {
        $seed = (64 === strlen($pubkey) && ctype_xdigit($pubkey)) ? $pubkey : hash('sha256', $npub, false);

        return sprintf(
            '<span class="user-badge__avatar user-badge__avatar--generated" aria-hidden="true">%s</span>',
            PubkeyAvatarSvg::generate($seed),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
