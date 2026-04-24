<?php

declare(strict_types=1);

namespace App\Twig;

use App\Dto\NostrShareMenuContext;
use App\Entity\Article;
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
            new TwigFunction('nostr_event_share', [$this, 'getEventShareContext']),
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

    /**
     * Share menu for a specific event: {@see Article} row, wire object (comment / quote / preview), etc.
     *
     * @param mixed $data Article entity or wire-like object (id, pubkey, kind, tags)
     */
    public function getEventShareContext(mixed $data): ?NostrShareMenuContext
    {
        if ($data instanceof Article) {
            return $this->builder->shareContextForArticle($data);
        }
        if ($data === null) {
            return null;
        }
        if (\is_array($data)) {
            $json = json_encode($data);
            $data = \is_string($json) ? json_decode($json) : null;
        }
        if (!\is_object($data)) {
            return null;
        }

        return $this->builder->shareContextFromWireEvent($data, []);
    }
}
