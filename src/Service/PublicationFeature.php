<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Tenant flag {@see community_publications}: NKBIP-01 publication feed, ingest, and search.
 */
final class PublicationFeature
{
    public function __construct(
        private readonly ParameterBagInterface $params,
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->params->has('community_publications')) {
            return false;
        }

        return (bool) $this->params->get('community_publications');
    }
}
