<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\UserBadgeOptions;

/**
 * Replaces nostr:… and bare NIP-19 identifiers in text/HTML with user badges or preview cards.
 */
final class NostrContentLinkEnhancer
{
    public function __construct(
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly UserBadgeHtmlRenderer $userBadgeHtmlRenderer,
        private readonly NostrPreviewPlaceholderRenderer $nostrPreviewPlaceholderRenderer,
    ) {
    }

    public function enhancePlainText(string $text): string
    {
        if ($text === '' || !$this->mightContainNostrReferences($text)) {
            return $text;
        }

        return $this->replaceLinks($text, $this->nostrLinkParser->parseLinks($text));
    }

    public function enhanceHtml(string $html): string
    {
        if ($html === '' || !$this->mightContainNostrReferences($html)) {
            return $html;
        }

        $links = $this->nostrLinkParser->parseLinks(strip_tags($html));
        if ($links === []) {
            return $html;
        }

        return $this->replaceLinks($html, $links);
    }

    private function mightContainNostrReferences(string $text): bool
    {
        return str_contains($text, 'npub1')
            || str_contains($text, 'nprofile1')
            || str_contains($text, 'nevent1')
            || str_contains($text, 'naddr1')
            || str_contains($text, 'nostr:');
    }

    /**
     * @param list<array<string, mixed>> $links
     */
    private function replaceLinks(string $text, array $links): string
    {
        if ($links === []) {
            return $text;
        }

        usort(
            $links,
            static function (array $a, array $b): int {
                $pos = ($b['position'] ?? 0) <=> ($a['position'] ?? 0);
                if ($pos !== 0) {
                    return $pos;
                }

                return strlen((string) ($b['full_match'] ?? '')) <=> strlen((string) ($a['full_match'] ?? ''));
            },
        );

        foreach ($links as $link) {
            $replacement = $this->renderLink($link);
            if ($replacement === null) {
                continue;
            }
            $match = (string) ($link['full_match'] ?? '');
            if ($match === '') {
                continue;
            }
            $pos = (int) ($link['position'] ?? -1);
            if ($pos >= 0 && substr($text, $pos, strlen($match)) === $match) {
                $text = substr($text, 0, $pos).$replacement.substr($text, $pos + strlen($match));
                continue;
            }
            $escaped = htmlspecialchars($match, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($escaped !== $match && str_contains($text, $escaped)) {
                $text = str_replace($escaped, $replacement, $text);
            } else {
                $text = str_replace($match, $replacement, $text);
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $link
     */
    private function renderLink(array $link): ?string
    {
        $type = (string) ($link['type'] ?? '');
        $identifier = (string) ($link['identifier'] ?? '');

        return match ($type) {
            'npub' => $identifier !== ''
                ? $this->userBadgeHtmlRenderer->render($identifier, UserBadgeOptions::inline())
                : null,
            'nprofile' => $this->renderNprofileBadge($link),
            'nevent', 'naddr' => $identifier !== ''
                ? $this->nostrPreviewPlaceholderRenderer->render(
                    $type,
                    $identifier,
                    $link['data'] ?? null,
                    (string) ($link['full_match'] ?? null),
                )
                : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $link
     */
    private function renderNprofileBadge(array $link): ?string
    {
        $identifier = (string) ($link['identifier'] ?? '');
        $data = $link['data'] ?? null;
        $pubkey = '';
        if (\is_object($data) && isset($data->pubkey)) {
            $pubkey = (string) $data->pubkey;
        } elseif (\is_array($data) && isset($data['pubkey'])) {
            $pubkey = (string) $data['pubkey'];
        }
        $ident = $pubkey !== '' ? $pubkey : $identifier;
        if ($ident === '') {
            return null;
        }

        return $this->userBadgeHtmlRenderer->render($ident, UserBadgeOptions::inline());
    }
}
