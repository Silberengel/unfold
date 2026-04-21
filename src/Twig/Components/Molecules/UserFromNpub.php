<?php

namespace App\Twig\Components\Molecules;

use App\Service\CacheService;
use App\Util\PubkeyAvatarSvg;
use swentel\nostr\Key\Key;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class UserFromNpub
{
    public string $pubkey = '';

    public string $npub;

    public $user = null;

    public string $fallbackSvg = '';

    public function __construct(private readonly CacheService $cacheService)
    {
    }

    public function mount(string $ident): void
    {
        $keys = new Key();
        if (!str_starts_with($ident, 'npub')) {
            $this->pubkey = $ident;
            $this->npub = $keys->convertPublicKeyToBech32($ident);
        } else {
            $this->npub = $ident;
            $this->pubkey = $keys->convertToHex($ident);
        }

        $this->user = $this->cacheService->getMetadata($this->npub);

        $seed = (\strlen($this->pubkey) === 64 && ctype_xdigit($this->pubkey))
            ? $this->pubkey
            : hash('sha256', $this->npub, false);
        $this->fallbackSvg = PubkeyAvatarSvg::generate($seed);
    }
}
