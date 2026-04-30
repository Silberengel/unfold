<?php

declare(strict_types=1);

namespace App\Util;

use App\Enum\KindsEnum;

/**
 * NIP-51 kind 30004 (curation set) for the home strip: ordered `a` tags for kind **30023** only.
 * Other address kinds and `e` tags are ignored.
 */
final class CurationSet30004Home
{
    /**
     * @param iterable<int, mixed> $tags Event tag rows (Nostr JSON shape)
     *
     * @return array{title: string, items: list<array{type: 'article', pk: string, slug: string}>}
     */
    public static function parseTitleAndOrderedRefs(iterable $tags): array
    {
        $title = '';
        $items = [];
        foreach ($tags as $tag) {
            if (!\is_array($tag) || $tag === []) {
                continue;
            }
            $name = isset($tag[0]) ? strtolower((string) $tag[0]) : '';
            $v = isset($tag[1]) ? (string) $tag[1] : '';
            if ($v === '') {
                continue;
            }
            if ($name === 'title' && $title === '') {
                $title = trim($v);
                continue;
            }
            if ($name !== 'a') {
                continue;
            }
            $parts = explode(':', $v, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $kind = (int) $parts[0];
            if ($kind !== KindsEnum::LONGFORM->value) {
                continue;
            }
            $pk = strtolower(trim($parts[1]));
            $slug = trim($parts[2]);
            if (64 !== \strlen($pk) || !ctype_xdigit($pk) || $slug === '') {
                continue;
            }
            $items[] = ['type' => 'article', 'pk' => $pk, 'slug' => $slug];
        }

        return ['title' => $title, 'items' => $items];
    }
}
