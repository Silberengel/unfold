<?php

namespace App\Twig\Components\Molecules;

use App\Service\CacheService;
use App\Service\NostrKeyHelper;
use App\Util\PubkeyAvatarSvg;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class UserFromNpub
{
    public string $pubkey = '';

    public string $npub;

    public $user = null;

    public string $fallbackSvg = '';

    /** When true (publication cards), missing/broken avatars use the site favicon instead of the generated SVG. */
    public bool $faviconFallback = false;

    public function __construct(
        private readonly CacheService $cacheService,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function mount(string $ident, bool $faviconFallback = false): void
    {
        $this->faviconFallback = $faviconFallback;
        if (!str_starts_with($ident, 'npub')) {
            $this->pubkey = $ident;
            $this->npub = $this->nostrKeyHelper->convertPublicKeyToBech32($ident);
        } else {
            $this->npub = $ident;
            $this->pubkey = $this->nostrKeyHelper->convertToHex($ident);
        }

        $this->user = $this->cacheService->getMetadata($this->npub);

        if (!$this->faviconFallback) {
            $seed = (\strlen($this->pubkey) === 64 && ctype_xdigit($this->pubkey))
                ? $this->pubkey
                : hash('sha256', $this->npub, false);
            $this->fallbackSvg = PubkeyAvatarSvg::generate($seed);
        }
    }
}
