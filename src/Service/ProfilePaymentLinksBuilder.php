<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;

/**
 * Lightning (lud06 / lud16) and NIP-A3 payto: kind-0 JSON, kind-0 tags, and kind 10133 events.
 *
 * NIP-A3: `["payto", "<type>", "<authority>", ...]` → `payto://<type>/<authority>…` (RFC 8905 family).
 *
 * NIP-A3: kind 10133 replaceable payment target events; tag `["payto", type, authority, ...]`.
 * @see https://github.com/CodyTseng/jumble/blob/master/src/lib/lightning.ts getLightningAddressFromProfile
 */
final class ProfilePaymentLinksBuilder
{
    public const TYPE_LIGHTNING_ADDRESS = 'lightning_address';

    public const TYPE_LNURL_PAY = 'lnurl_pay';

    public const TYPE_PAYTO = 'payto';

    /**
     * @param list<list<string>> $kind0Tags
     * @param list<string>       $extraPaytoUris from kind 10133
     *
     * @return list<array{
     *     type: string,
     *     type_label: string,
     *     label: string,
     *     href: string,
     *     sort: int,
     *     group_key: string,
     *     display_type_label: string
     * }>
     */
    public function buildPaymentRows(object $content, array $kind0Tags, array $extraPaytoUris = []): array
    {
        $a = $this->stringField($content, 'lud16');
        $b = $this->stringField($content, 'lud06');
        $resolved = $this->resolveLud16Lud06($a, $b);

        $rows = [];
        $seen = [];

        if ($resolved['lightning_address'] !== null) {
            $addr = $resolved['lightning_address'];
            $norm = 'la:'.strtolower($addr);
            $seen[$norm] = true;
            $rows[] = [
                'type' => self::TYPE_LIGHTNING_ADDRESS,
                'type_label' => 'Lightning',
                'label' => $addr,
                'href' => 'lightning:'.$addr,
                'sort' => 0,
            ];
        }

        if ($resolved['lnurl_pay'] !== null) {
            $ln = $resolved['lnurl_pay'];
            $norm = 'lnurl:'.strtolower($ln);
            if (!isset($seen[$norm])) {
                $seen[$norm] = true;
                $rows[] = [
                    'type' => self::TYPE_LNURL_PAY,
                    'type_label' => 'Lightning',
                    'label' => $this->shortenLnurl($ln),
                    'href' => 'lightning:'.$ln,
                    'sort' => 1,
                ];
            }
        }

        $lud16ForDedup = $resolved['lightning_address'];

        $allPayto = array_merge(
            $this->paytoUrisFromJsonObject($content),
            $this->paytoUrisFromNipA3StyleTags($kind0Tags),
            $extraPaytoUris
        );
        foreach ($allPayto as $uri) {
            $uri = self::trimPaytoString($uri);
            if ($uri === null) {
                continue;
            }
            if (!self::isPaytoOrLegacyPaytoScheme($uri)) {
                continue;
            }
            if ($lud16ForDedup !== null && self::paytoLightningUriMatchesLightningAddress($uri, $lud16ForDedup)) {
                continue;
            }
            $canon = self::normalizePaytoUriForDedup($uri);
            if (isset($seen[$canon])) {
                continue;
            }
            $seen[$canon] = true;
            $rows[] = [
                'type' => self::TYPE_PAYTO,
                'type_label' => 'Pay to',
                'label' => $this->labelForPaytoUri($uri),
                'href' => $uri,
                'sort' => 2,
            ];
        }

        $rows = array_values(
            array_filter(
                $rows,
                static fn (array $r): bool => self::isAllowedPaymentHref($r['href'])
            )
        );

        usort(
            $rows,
            static function (array $x, array $y): int {
                if ($x['sort'] !== $y['sort']) {
                    return $x['sort'] <=> $y['sort'];
                }

                return strcasecmp($x['label'], $y['label']);
            }
        );

        return $this->collapseGroupLabels($rows);
    }

    /**
     * Consecutive rows with the same {@see group_key} only show the first column label on the first row
     * (e.g. multiple Lightning lines, then Monero).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function collapseGroupLabels(array $rows): array
    {
        $prevKey = null;
        $out = [];
        foreach ($rows as $r) {
            $gk = $this->rowGroupKey($r);
            $col = $this->rowGroupColumnLabel($r, $gk);
            $r['group_key'] = $gk;
            $r['display_type_label'] = $gk === $prevKey ? '' : $col;
            $prevKey = $gk;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function rowGroupKey(array $r): string
    {
        $t = (string) ($r['type'] ?? '');
        if ($t === self::TYPE_LIGHTNING_ADDRESS || $t === self::TYPE_LNURL_PAY) {
            return 'lightning';
        }
        if ($t === self::TYPE_PAYTO) {
            $h = strtolower((string) ($r['href'] ?? ''));
            if (1 === preg_match('#^payto://([a-z0-9-]+)/#i', $h, $m)) {
                $sc = strtolower($m[1]);
                if ($sc === 'lightning') {
                    return 'lightning';
                }

                return 'payto:'.$sc;
            }

            return 'payto:other';
        }

        return 'other';
    }

    /**
     * @param array<string, mixed> $r
     */
    private function rowGroupColumnLabel(array $r, string $groupKey): string
    {
        if ($groupKey === 'lightning') {
            return 'Lightning';
        }
        if (str_starts_with($groupKey, 'payto:')) {
            $s = substr($groupKey, 6);
            if ($s === 'other') {
                return 'Pay to';
            }

            return $this->stylizePaytoTypeName($s);
        }

        return (string) ($r['type_label'] ?? 'Pay to');
    }

    /**
     * @param list<object> $kind10133Events
     *
     * @return list<string>
     */
    public function collectPaytoUrisFromNipA3Kind10133Events(array $kind10133Events): array
    {
        $out = [];
        foreach ($kind10133Events as $ev) {
            if ((int) ($ev->kind ?? 0) !== KindsEnum::PAYMENT_TARGETS->value) {
                continue;
            }
            $tags = self::normalizeTagsArray($ev->tags ?? null);
            foreach ($tags as $t) {
                $u = $this->buildPaytoUriFromNipA3Tag($t);
                if ($u !== null) {
                    $out[] = $u;
                }
            }
        }

        return $out;
    }

    /**
     * NIP-A3: `["payto", type, authority, ...]` or legacy `["payto", "payto:..."]` / `["payto", "payto://..."]`.
     *
     * @param list<string> $t
     */
    private function buildPaytoUriFromNipA3Tag(array $t): ?string
    {
        if (!isset($t[0]) || strtolower((string) $t[0]) !== 'payto') {
            return null;
        }
        if (\count($t) >= 3) {
            $type = strtolower(trim((string) $t[1]));
            if ($type === '' || 1 !== preg_match('/^[a-z0-9-]+$/', $type)) {
                return null;
            }
            $rawParts = [];
            for ($i = 2; $i < \count($t); ++$i) {
                $p = trim((string) $t[$i]);
                if ($p !== '') {
                    $rawParts[] = $p;
                }
            }
            if ($rawParts === []) {
                return null;
            }
            $segs = array_map(static fn (string $p): string => rawurlencode($p), $rawParts);

            return 'payto://'.$type.'/'.implode('/', $segs);
        }
        if (isset($t[1])) {
            $s = self::trimPaytoString((string) $t[1]);
            if ($s !== null && self::isPaytoOrLegacyPaytoScheme($s)) {
                return $s;
            }
        }

        return null;
    }

    /**
     * @param list<list<string>> $tags
     *
     * @return list<string>
     */
    private function paytoUrisFromNipA3StyleTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) {
            $u = $this->buildPaytoUriFromNipA3Tag($t);
            if ($u !== null) {
                $out[] = $u;
            }
        }

        return $out;
    }

    private static function normalizeTagsArray(mixed $tags): array
    {
        if (!\is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $row) {
            if (!\is_array($row) && !\is_object($row)) {
                continue;
            }
            $seq = \is_object($row) ? get_object_vars($row) : $row;
            if ($seq === []) {
                continue;
            }
            $r = array_values(
                array_map(
                    static fn (mixed $v): string => (string) $v,
                    $seq
                )
            );
            $out[] = $r;
        }

        return $out;
    }

    private static function isPaytoOrLegacyPaytoScheme(string $s): bool
    {
        $l = strtolower($s);

        return str_starts_with($l, 'payto:');
    }

    /**
     * Deduplication key (lowercase; paths may be lossy for odd encodings, acceptable for same-target merge).
     */
    private static function normalizePaytoUriForDedup(string $uri): string
    {
        if (str_starts_with(strtolower($uri), 'payto://')) {
            if (1 === preg_match('#^payto://([a-z0-9-]+)/(.+)$#i', $uri, $m)) {
                return 'payto://'.strtolower($m[1]).'/'.strtolower($m[2]);
            }
        }

        return strtolower($uri);
    }

    private static function trimPaytoString(string $s): ?string
    {
        $s = trim($s);

        return $s === '' ? null : $s;
    }

    private function labelForPaytoUri(string $u): string
    {
        if (1 === preg_match('#^payto://([a-z0-9-]+)/(.+)$#i', $u, $m)) {
            $t = $this->stylizePaytoTypeName($m[1]);
            $path = rawurldecode((string) $m[2]);
            if (str_contains($path, '/')) {
                $a = (string) strstr($path, '/', true);
            } else {
                $a = $path;
            }
            if (strlen($a) > 42) {
                $a = substr($a, 0, 20).'…'.substr($a, -10);
            }

            return $t.' · '.$a;
        }
        if (strlen($u) > 64) {
            return substr($u, 0, 36).'…';
        }

        return $u;
    }

    private function stylizePaytoTypeName(string $type): string
    {
        $type = strtolower($type);

        return match ($type) {
            'bitcoin' => 'Bitcoin',
            'lightning' => 'Lightning',
            'ethereum' => 'Ethereum',
            'nano' => 'Nano',
            'monero' => 'Monero',
            'cashme' => 'Cash App',
            'revolut' => 'Revolut',
            'venmo' => 'Venmo',
            default => $type,
        };
    }

    private static function isAllowedPaymentHref(string $href): bool
    {
        if ($href === '') {
            return false;
        }
        $h = strtolower($href);

        return str_starts_with($h, 'lightning:') || str_starts_with($h, 'payto:');
    }

    /**
     * @return array{lightning_address: ?string, lnurl_pay: ?string}
     */
    private function resolveLud16Lud06(?string $a, ?string $b): array
    {
        $lightningAddress = null;
        if ($a !== null && $this->isEmail($a)) {
            $lightningAddress = $a;
        } elseif ($b !== null && $this->isEmail($b)) {
            $lightningAddress = $b;
        }

        $lnurl = null;
        if ($a !== null && $this->isLnurlBech32($a) && $a !== $lightningAddress) {
            $lnurl = $a;
        } elseif ($b !== null && $this->isLnurlBech32($b) && $b !== $lightningAddress) {
            $lnurl = $b;
        }

        return [
            'lightning_address' => $lightningAddress,
            'lnurl_pay' => $lnurl,
        ];
    }

    private function stringField(object $o, string $key): ?string
    {
        if (!isset($o->{$key})) {
            return null;
        }
        $v = $o->{$key};
        if (!\is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }

        return $v;
    }

    private function isEmail(string $s): bool
    {
        return 1 === preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s);
    }

    private function isLnurlBech32(string $s): bool
    {
        $lower = strtolower($s);

        return str_starts_with($lower, 'lnurl');
    }

    private function shortenLnurl(string $lnurl): string
    {
        if (strlen($lnurl) <= 24) {
            return $lnurl;
        }

        return substr($lnurl, 0, 10).'…'.substr($lnurl, -8);
    }

    /**
     * Skips NIP-A3 / JSON {@see payto://lightning/…} rows that repeat the LUD16 lightning address
     * (e.g. same as {@see TYPE_LIGHTNING_ADDRESS} with {@code lightning:user@host}).
     */
    private static function paytoLightningUriMatchesLightningAddress(string $uri, string $lud16Email): bool
    {
        if (!str_starts_with(strtolower($uri), 'payto://lightning/')) {
            return false;
        }
        $lud = strtolower(trim($lud16Email));
        if ($lud === '' || !str_contains($lud, '@')) {
            return false;
        }
        if (1 !== preg_match('#^payto://lightning/(.+)$#i', $uri, $m)) {
            return false;
        }
        $tail = (string) $m[1];
        $first = (string) (str_contains($tail, '/') ? strstr($tail, '/', true) : $tail);
        if ($first === '') {
            $first = $tail;
        }
        $first = strtolower(rawurldecode($first));

        return $first === $lud;
    }

    /**
     * JSON: strings (full `payto:` / `payto://` URI), or objects with `type`+`authority` (NIP-A3-style).
     *
     * @return list<string>
     */
    private function paytoUrisFromJsonObject(object $metadata): array
    {
        $out = [];
        if (!isset($metadata->payto)) {
            return $out;
        }
        $p = $metadata->payto;
        if (\is_string($p)) {
            if (trim($p) !== '') {
                $out[] = $p;
            }

            return $out;
        }
        if (!\is_array($p)) {
            return $out;
        }
        foreach ($p as $item) {
            if (\is_string($item) && trim($item) !== '') {
                $out[] = $item;

                continue;
            }
            if (!\is_object($item) && !\is_array($item)) {
                continue;
            }
            $o = (object) $item;
            if (isset($o->type, $o->authority) && \is_string($o->type) && \is_string($o->authority)) {
                $t = strtolower(trim($o->type));
                $auth = trim($o->authority);
                if ($t !== '' && $auth !== '' && 1 === preg_match('/^[a-z0-9-]+$/', $t)) {
                    $out[] = 'payto://'.$t.'/'.rawurlencode($auth);

                    continue;
                }
            }
            foreach (['uri', 'url', 'payto', 'href', 'value'] as $k) {
                if (isset($o->{$k}) && \is_string($o->{$k}) && trim($o->{$k}) !== '') {
                    $out[] = trim($o->{$k});
                    break;
                }
            }
        }

        return $out;
    }
}
