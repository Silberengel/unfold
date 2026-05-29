<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Resolve a human-readable profile label from kind-0 metadata (display_name, then name).
 */
final class ProfileDisplayName
{
    public static function resolve(\stdClass $meta, string $npub): string
    {
        $display = \trim((string) ($meta->display_name ?? ''));
        if ($display !== '') {
            return $display;
        }
        $name = \trim((string) ($meta->name ?? ''));
        if ($name !== '' && !self::isShortNpubPlaceholder($name, $npub)) {
            return $name;
        }

        return self::shortNpubLabel($npub);
    }

    public static function isShortNpubPlaceholder(string $label, string $npub): bool
    {
        if (! \str_starts_with($npub, 'npub1')) {
            return false;
        }

        return $label === \substr($npub, 0, 8).'…'.\substr($npub, -4)
            || $label === self::shortNpubLabel($npub);
    }

    public static function shortNpubLabel(string $npub): string
    {
        if (\strlen($npub) < 12) {
            return $npub;
        }

        return \substr($npub, 0, 5).'...'.\substr($npub, -5);
    }
}
