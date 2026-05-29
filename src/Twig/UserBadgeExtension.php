<?php

declare(strict_types=1);

namespace App\Twig;

use App\Dto\UserBadgeOptions;
use App\Service\UserBadgeHtmlRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UserBadgeExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserBadgeHtmlRenderer $userBadgeHtmlRenderer,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_badge', [$this, 'userBadge'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function userBadge(string $ident, array $options = []): string
    {
        return $this->userBadgeHtmlRenderer->render($ident, UserBadgeOptions::fromArray($options));
    }
}
