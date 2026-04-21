<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Deterministic soft avatar from a hex pubkey (or any hex-ish seed).
 * Inline SVG uses theme CSS variables (brand, primary, surfaces).
 */
final class PubkeyAvatarSvg
{
    public static function generate(string $hexSeed): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-f]/', '', $hexSeed) ?? '');
        if ($hex === '') {
            $hex = hash('sha256', 'empty', false);
        }

        $bin = hex2bin(\strlen($hex) >= 64 ? substr($hex, 0, 64) : hash('sha256', $hex, false));
        if ($bin === false || $bin === '') {
            $bin = hash('sha256', $hex, true);
        }

        /** @var list<int> $b */
        $b = array_values(unpack('C*', substr($bin, 0, 32)));
        while (\count($b) < 32) {
            $b[] = 0;
        }

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $id = $e(substr($hex, 0, 16));

        $cx1 = 8 + ($b[0] % 40);
        $cy1 = 6 + ($b[1] % 36);
        $r1 = 22 + ($b[2] % 14);
        $o1 = $e((string) round(0.22 + ($b[3] % 18) / 100, 3));

        $cx2 = 18 + ($b[4] % 38);
        $cy2 = 20 + ($b[5] % 32);
        $r2 = 18 + ($b[6] % 12);
        $o2 = $e((string) round(0.18 + ($b[7] % 20) / 100, 3));

        $cx3 = 28 + ($b[8] % 30);
        $cy3 = 28 + ($b[9] % 28);
        $r3 = 14 + ($b[10] % 10);
        $o3 = $e((string) round(0.28 + ($b[11] % 22) / 100, 3));

        $rot = ($b[12] << 8 | $b[13]) % 140 - 70;

        $ring = '';
        if (($b[14] & 1) === 1) {
            $rop = $e((string) round(0.12 + ($b[15] % 10) / 100, 3));
            $ring = '<circle cx="32" cy="32" r="30" fill="none" stroke="var(--color-border)" stroke-opacity="' . $rop . '" stroke-width="1"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="pubkey-avatar-svg" aria-hidden="true" focusable="false">'
            . '<defs><linearGradient id="pubkey-av-' . $id . '" x1="0%" y1="0%" x2="100%" y2="100%">'
            . '<stop offset="0%" stop-color="var(--color-bg)" stop-opacity="0.94"/>'
            . '<stop offset="100%" stop-color="var(--color-bg-light)" stop-opacity="1"/>'
            . '</linearGradient></defs>'
            . '<circle cx="32" cy="32" r="32" fill="url(#pubkey-av-' . $id . ')"/>'
            . '<g transform="rotate(' . $rot . ' 32 32)">'
            . '<circle cx="' . $cx1 . '" cy="' . $cy1 . '" r="' . $r1 . '" fill="var(--brand-color)" opacity="' . $o1 . '"/>'
            . '<circle cx="' . $cx2 . '" cy="' . $cy2 . '" r="' . $r2 . '" fill="var(--color-secondary)" opacity="' . $o2 . '"/>'
            . '<circle cx="' . $cx3 . '" cy="' . $cy3 . '" r="' . $r3 . '" fill="var(--color-primary)" opacity="' . $o3 . '"/>'
            . '</g>'
            . $ring
            . '</svg>';
    }
}
