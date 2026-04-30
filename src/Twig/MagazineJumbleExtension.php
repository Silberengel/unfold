<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\KindsEnum;
use App\Nostr\Nip19Addressable;
use App\Service\NostrKeyHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Footer “View this magazine on Jumble Imwald”: Jumble /feed/notes/{naddr} for the site root kind 30040 index.
 */
final class MagazineJumbleExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire('%npub%')]
        private readonly string $siteNpub,
        #[Autowire('%d_tag%')]
        private readonly string $rootMagazineDTag,
        #[Autowire('%jumble_feed_notes_base%')]
        private readonly string $jumbleFeedNotesBase,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('magazine_on_jumble_url', $this->magazineOnJumbleUrl(...)),
        ];
    }

    public function magazineOnJumbleUrl(): string
    {
        try {
            $pubkeyHex = $this->nostrKeyHelper->convertToHex($this->siteNpub);
        } catch (\Throwable) {
            return '#';
        }
        if (64 !== \strlen($pubkeyHex) || !ctype_xdigit($pubkeyHex)) {
            return '#';
        }
        $d = \trim($this->rootMagazineDTag);
        if ($d === '') {
            return '#';
        }
        try {
            $naddr = Nip19Addressable::naddrBech32(
                KindsEnum::PUBLICATION_INDEX->value,
                strtolower($pubkeyHex),
                $d,
                [],
            );
        } catch (\Throwable) {
            return '#';
        }
        $b = \rtrim($this->jumbleFeedNotesBase, '/');

        return $b === '' ? $naddr : $b.'/'.$naddr;
    }
}
