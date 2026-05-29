<?php

namespace App\Twig\Components\Molecules;

use App\Dto\UserBadgeOptions;
use App\Service\UserBadgeHtmlRenderer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class UserFromNpub
{
    public string $html = '';

    public function __construct(
        private readonly UserBadgeHtmlRenderer $userBadgeHtmlRenderer,
    ) {
    }

    public function mount(
        string $ident,
        bool $faviconFallback = false,
        string $size = UserBadgeOptions::SIZE_DEFAULT,
        bool $avatarOnly = false,
    ): void {
        $this->html = $this->userBadgeHtmlRenderer->render($ident, new UserBadgeOptions(
            size: $size,
            faviconFallback: $faviconFallback,
            avatarOnly: $avatarOnly,
        ));
    }
}
