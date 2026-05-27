<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Subset of {@see CacheService} for {@see ArticleBodyHighlightInjector} (mockable; readonly services cannot be doubled in PHPUnit).
 */
interface HighlightAuthorMetadataProvider
{
    public function getMetadata(string $npub): \stdClass;

    /**
     * @param list<string> $npubs
     */
    public function prefetchMetadataForNpubs(array $npubs): void;
}
