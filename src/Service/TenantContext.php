<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Current deployment's magazine tenant id (from config/unfold.yaml).
 * Scopes magazine indices, article visibility, featured authors, and admin users on a shared MySQL.
 */
final readonly class TenantContext
{
    public function __construct(
        private string $magazineSlug,
    ) {
        if ($magazineSlug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $magazineSlug)) {
            throw new \InvalidArgumentException('magazine_slug must be 1–63 lowercase alnum/hyphen characters.');
        }
    }

    public function getMagazineSlug(): string
    {
        return $this->magazineSlug;
    }
}
