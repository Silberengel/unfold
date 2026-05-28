<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\NaddrInternalUrlResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NaddrUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly NaddrInternalUrlResolver $naddrInternalUrlResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('naddr_internal_url', $this->naddrInternalUrlResolver->urlForNaddrBech32(...)),
        ];
    }
}
