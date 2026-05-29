<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Decides whether a fetched URL has enough Open Graph metadata to show a preview card.
 */
final class OpenGraphPreviewChecker
{
    public function hasMeaningfulMetadata(?string $title, ?string $description, ?string $image, string $url): bool
    {
        if (trim((string) $image) !== '') {
            return true;
        }
        if (trim((string) $description) !== '') {
            return true;
        }

        $title = trim((string) $title);
        if ($title === '') {
            return false;
        }

        return ! $this->isTrivialTitle($title, $url);
    }

    private function isTrivialTitle(string $title, string $url): bool
    {
        $titleNorm = strtolower(rtrim($title, '/'));
        $urlNorm = strtolower(rtrim($url, '/'));
        if ($titleNorm === $urlNorm) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (\is_string($host) && $host !== '') {
            $host = strtolower($host);
            if ($titleNorm === $host || $titleNorm === 'www.'.$host) {
                return true;
            }
        }

        return false;
    }
}
