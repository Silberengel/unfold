<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;
use App\Nostr\Nip19Codec;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Maps decoded naddr kinds to in-app routes (publication reader vs article).
 */
final class NaddrInternalUrlResolver
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Nip19Codec $nip19,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly PublicationFeature $publicationFeature,
    ) {
    }

    public function urlForNaddrBech32(string $naddr): ?string
    {
        try {
            $decoded = $this->nip19->decode($naddr);
        } catch (\Throwable) {
            return null;
        }
        if ($decoded->type !== 'naddr' || !\is_object($decoded->data)) {
            return null;
        }

        $data = $decoded->data;
        $kind = (int) ($data->kind ?? 0);
        $pubkey = (string) ($data->pubkey ?? '');
        $identifier = (string) ($data->identifier ?? $data->specifier ?? '');
        if ($identifier === '' || $pubkey === '') {
            return null;
        }

        $npub = str_starts_with($pubkey, 'npub')
            ? $pubkey
            : $this->nostrKeyHelper->convertPublicKeyToBech32($pubkey);

        if ($kind === KindsEnum::PUBLICATION_INDEX->value && $this->publicationFeature->isEnabled()) {
            return $this->urlGenerator->generate('publication', [
                'npub' => $npub,
                'slug' => $identifier,
            ]);
        }

        if (\in_array($kind, KindsEnum::articleBodyKindValues(), true)) {
            return $this->urlGenerator->generate('article', [
                'npub' => $npub,
                'slug' => $identifier,
            ]);
        }

        return null;
    }

    public function urlForKindPubkeyD(int $kind, string $pubkeyHex, string $d): ?string
    {
        try {
            $npub = $this->nostrKeyHelper->convertPublicKeyToBech32($pubkeyHex);
        } catch (\Throwable) {
            return null;
        }

        if ($kind === KindsEnum::PUBLICATION_INDEX->value && $this->publicationFeature->isEnabled()) {
            return $this->urlGenerator->generate('publication', ['npub' => $npub, 'slug' => $d]);
        }

        if (\in_array($kind, KindsEnum::articleBodyKindValues(), true)) {
            return $this->urlGenerator->generate('article', ['npub' => $npub, 'slug' => $d]);
        }

        return null;
    }
}
