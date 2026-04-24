<?php

declare(strict_types=1);

namespace App\Twig;

use App\Dto\NostrShareMenuContext;
use App\Service\NostrShareMenuBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NostrShareMenuExtension extends AbstractExtension
{
    public function __construct(
        private readonly NostrShareMenuBuilder $builder,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nostr_share_menu', [$this, 'getOrBuildContext']),
        ];
    }

    public function getOrBuildContext(): ?NostrShareMenuContext
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        return $this->builder->buildForRequest($request);
    }
}
