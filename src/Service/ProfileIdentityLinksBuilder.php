<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Website and NIP-05 links from kind-0 JSON and kind-0 tags, normalized and deduplicated.
 */
final class ProfileIdentityLinksBuilder
{
    /**
     * @param list<list<string>> $kind0Tags
     *
     * @return list<array{label: string, href: string}>
     */
    public function buildWebsites(object $content, array $kind0Tags): array
    {
        $raw = [];
        foreach ($this->stringsFromJsonField($content, 'website') as $s) {
            $raw[] = $s;
        }
        foreach ($this->stringsFromJsonField($content, 'websites') as $s) {
            $raw[] = $s;
        }
        foreach (self::tagValuesForNames($kind0Tags, ['url', 'website', 'web']) as $s) {
            $raw[] = $s;
        }
        $out = [];
        $seen = [];
        foreach ($raw as $u) {
            $u = trim($u);
            if ($u === '') {
                continue;
            }
            $href = $this->normalizeHttpUrl($u);
            if ($href === null) {
                continue;
            }
            $k = self::urlDedupKey($href);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = [
                'label' => $this->displayLabelForHttpUrl($href),
                'href' => $href,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * @param list<list<string>> $kind0Tags
     *
     * @return list<array{label: string, href: string}>
     */
    public function buildNip05(object $content, array $kind0Tags): array
    {
        $raw = [];
        foreach ($this->stringsFromJsonField($content, 'nip05') as $s) {
            $raw[] = $s;
        }
        foreach (self::tagValuesForNames($kind0Tags, ['nip05']) as $s) {
            $raw[] = $s;
        }
        $out = [];
        $seen = [];
        foreach ($raw as $id) {
            $id = trim(strtolower($id));
            if ($id === '' || !str_contains($id, '@')) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $parts = explode('@', $id, 2);
            $local = $parts[0];
            $domain = $parts[1];
            if ($local === '' || $domain === '' || str_contains($domain, ' ')) {
                continue;
            }
            $href = 'https://'.$domain.'/.well-known/nostr.json?name='.rawurlencode($local);
            $out[] = [
                'label' => $id,
                'href' => $href,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * Adds a site-assigned NIP-05 (e.g. under the blog domain) into the same list as profile NIP-05,
     * with the same link shape as {@see buildNip05}, deduped by label.
     *
     * @param list<array{label: string, href: string}> $rows
     *
     * @return list<array{label: string, href: string}>
     */
    public function mergeSiteNip05IntoList(array $rows, string $siteNip05): array
    {
        $siteNip05 = trim(strtolower($siteNip05));
        if ($siteNip05 === '' || !str_contains($siteNip05, '@')) {
            return $rows;
        }
        $seen = [];
        foreach ($rows as $r) {
            $seen[strtolower((string) $r['label'])] = true;
        }
        if (isset($seen[$siteNip05])) {
            return $rows;
        }
        $parts = explode('@', $siteNip05, 2);
        $local = $parts[0];
        $domain = $parts[1];
        if ($local === '' || $domain === '' || str_contains($domain, ' ')) {
            return $rows;
        }
        $href = 'https://'.$domain.'/.well-known/nostr.json?name='.rawurlencode($local);
        $rows[] = [
            'label' => $siteNip05,
            'href' => $href,
        ];
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function stringsFromJsonField(object $o, string $key): array
    {
        if (!isset($o->{$key})) {
            return [];
        }
        $v = $o->{$key};
        if (\is_string($v)) {
            return [trim($v)];
        }
        if (!\is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            if (\is_string($x) && trim($x) !== '') {
                $out[] = trim($x);
            }
        }

        return $out;
    }

    /**
     * @param list<list<string>>         $tags
     * @param list<string>               $tagNames lowercased
     * @return list<string>
     */
    private static function tagValuesForNames(array $tags, array $tagNames): array
    {
        $want = array_fill_keys(array_map(static fn (string $s): string => strtolower($s), $tagNames), true);
        $out = [];
        foreach ($tags as $t) {
            if (!isset($t[0], $t[1])) {
                continue;
            }
            $name = strtolower((string) $t[0]);
            if (!isset($want[$name])) {
                continue;
            }
            $v = trim((string) $t[1]);
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    private function normalizeHttpUrl(string $url): ?string
    {
        $u = trim($url);
        if ($u === '') {
            return null;
        }
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $u)) {
            $u = 'https://'.$u;
        }
        if (!str_starts_with(strtolower($u), 'http://') && !str_starts_with(strtolower($u), 'https://')) {
            return null;
        }

        return $u;
    }

    private static function urlDedupKey(string $href): string
    {
        $p = @parse_url($href);
        if (!\is_array($p)) {
            return strtolower($href);
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        $path = (string) ($p['path'] ?? '');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $host.$path.($p['query'] ?? '');
    }

    private function displayLabelForHttpUrl(string $href): string
    {
        $p = @parse_url($href);
        if (\is_array($p) && !empty($p['host'])) {
            $host = (string) $p['host'];
            $path = (string) ($p['path'] ?? '');
            if ($path === '' || $path === '/') {
                return $host;
            }
            if (strlen($path) > 32) {
                return $host.$path;
            }

            return $host.$path;
        }

        if (strlen($href) > 48) {
            return substr($href, 0, 32).'…';
        }

        return $href;
    }
}
