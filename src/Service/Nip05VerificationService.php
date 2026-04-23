<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;

/**
 * Fetches <domain>/.well-known/nostr.json and checks the listed pubkey (NIP-05).
 * Results are stored in the app cache for UI badges and to avoid re-fetching on every request.
 */
final readonly class Nip05VerificationService
{
    private const CACHE_PREFIX = 'nip05v1_';

    private const FETCH_TIMEOUT_SEC = 8;

    public function __construct(
        private CacheItemPoolInterface $appCache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array{label: string, href: string, verified?: bool}> $rows
     *
     * @return list<array{label: string, href: string, verified: bool}>
     */
    public function enrichRowsWithCache(string $authorPubkeyHex, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $h = strtolower($authorPubkeyHex);
        if (64 !== \strlen($h) || !ctype_xdigit($h)) {
            return array_map(static function (array $r): array {
                return [...$r, 'verified' => false];
            }, $rows);
        }
        $out = [];
        foreach ($rows as $r) {
            $label = (string) ($r['label'] ?? '');
            $n = $this->normalizeNip05($label);
            if ($n === null) {
                $out[] = [...$r, 'verified' => false];

                continue;
            }
            $k = $this->cacheKey($h, $n);
            $verified = false;
            try {
                $item = $this->appCache->getItem($k);
                if ($item->isHit() && is_bool($item->get())) {
                    $verified = (bool) $item->get();
                }
            } catch (InvalidArgumentException) {
            }
            $out[] = [...$r, 'verified' => $verified];
        }

        return $out;
    }

    /**
     * Fetches the document and records success or failure in cache (24h).
     */
    public function verifyAndCache(string $authorPubkeyHex, string $nip05Label): bool
    {
        $h = strtolower($authorPubkeyHex);
        if (64 !== \strlen($h) || !ctype_xdigit($h)) {
            return false;
        }
        $n = $this->normalizeNip05($nip05Label);
        if ($n === null) {
            return false;
        }
        $k = $this->cacheKey($h, $n);
        $ok = $this->checkRemote($h, $n);
        try {
            $item = $this->appCache->getItem($k);
            $item->set($ok);
            $item->expiresAfter(86_400);
            $this->appCache->save($item);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('nip05.verify_cache_write_failed', [
                'message' => $e->getMessage(),
            ]);
        }

        return $ok;
    }

    private function cacheKey(string $hexLower, string $nip05Lower): string
    {
        return self::CACHE_PREFIX.hash('sha256', $hexLower."\0".$nip05Lower);
    }

    private function normalizeNip05(string $raw): ?string
    {
        $s = trim(strtolower($raw));
        if ($s === '' || !str_contains($s, '@')) {
            return null;
        }
        $p = explode('@', $s, 2);
        if (($p[0] ?? '') === '' || ($p[1] ?? '') === '' || str_contains($p[1], ' ')) {
            return null;
        }

        return $s;
    }

    private function checkRemote(string $expectedHex, string $nip05Lower): bool
    {
        $parts = explode('@', $nip05Lower, 2);
        $local = (string) ($parts[0] ?? '');
        $domain = (string) ($parts[1] ?? '');
        if ($local === '' || $domain === '') {
            return false;
        }
        $url = 'https://'.$domain.'/.well-known/nostr.json?name='.rawurlencode($local);
        $http_response_header = [];
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Unfold-NIP05-Verify/1.0\r\nAccept: application/json,\r\n",
                'timeout' => self::FETCH_TIMEOUT_SEC,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            $this->logger->info('nip05.verify_fetch_failed', [
                'nip05' => $nip05Lower,
            ]);

            return false;
        }
        $statusLine = (isset($http_response_header) && \is_array($http_response_header))
            ? (string) ($http_response_header[0] ?? '')
            : '';
        if (!preg_match('#\b200\b#', $statusLine)) {
            $this->logger->info('nip05.verify_not_200', [
                'nip05' => $nip05Lower,
                'status' => $statusLine,
            ]);

            return false;
        }
        try {
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!\is_array($data) || !isset($data['names']) || !\is_array($data['names'])) {
            return false;
        }
        $val = $this->lookupNameInNames($data['names'], $local);
        if (!\is_string($val) || $val === '') {
            return false;
        }
        $rowHex = $this->toHex64($val);
        if ($rowHex === null) {
            return false;
        }

        return hash_equals($expectedHex, $rowHex);
    }

    /**
     * @param array<array-key, mixed> $names
     */
    private function lookupNameInNames(array $names, string $localWanted): mixed
    {
        if (isset($names[$localWanted])) {
            return $names[$localWanted];
        }
        $lw = strtolower($localWanted);
        foreach ($names as $k => $v) {
            if (\is_string($k) && strtolower($k) === $lw) {
                return $v;
            }
        }

        return null;
    }

    private function toHex64(string $v): ?string
    {
        $v = trim($v);
        if (64 === \strlen($v) && ctype_xdigit($v)) {
            return strtolower($v);
        }
        if (str_starts_with($v, 'npub1')) {
            try {
                $k = new Key();
                $hex = $k->convertToHex($v);
                if (64 === \strlen($hex) && ctype_xdigit($hex)) {
                    return strtolower($hex);
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
