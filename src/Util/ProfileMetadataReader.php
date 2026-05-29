<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Kind-0 profile JSON fields used for avatars and bylines.
 */
final class ProfileMetadataReader
{
    public static function displayName(\stdClass $meta): string
    {
        if (isset($meta->display_name) && \is_string($meta->display_name)) {
            $n = \trim($meta->display_name);
            if ($n !== '') {
                return $n;
            }
        }
        if (isset($meta->name) && \is_string($meta->name)) {
            $n = \trim($meta->name);
            if ($n !== '') {
                return $n;
            }
        }

        return '';
    }

    public static function pictureUrl(\stdClass $meta): string
    {
        if (isset($meta->picture) && \is_string($meta->picture)) {
            $u = \trim($meta->picture);
            if ($u !== '') {
                return $u;
            }
        }
        if (isset($meta->image) && \is_string($meta->image)) {
            $u = \trim($meta->image);
            if ($u !== '') {
                return $u;
            }
        }

        return '';
    }
}
