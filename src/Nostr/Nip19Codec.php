<?php

declare(strict_types=1);

namespace App\Nostr;

use swentel\nostr\Event\Event;
use swentel\nostr\Nip19\Nip19Helper;

/**
 * NIP-19 encode/decode using swentel/nostr-php, output-shaped for code that previously used nostriphant\NIP19\Bech32
 * (objects with {@see $type} and {@see $data} for decoded entities).
 */
final class Nip19Codec
{
    private Nip19Helper $nip19Helper;

    public function __construct(?Nip19Helper $nip19Helper = null)
    {
        $this->nip19Helper = $nip19Helper ?? new Nip19Helper();
    }

    public function decode(string $bech32): object
    {
        $pos = strrpos($bech32, '1');
        if (false === $pos || $pos < 1) {
            throw new \InvalidArgumentException('Invalid bech32 string');
        }
        $hrp = substr($bech32, 0, $pos);
        $raw = $this->nip19Helper->decode($bech32);

        $out = new \stdClass();

        if ($hrp === 'npub' || $hrp === 'nsec') {
            if (!isset($raw[1]) || !\is_array($raw[1])) {
                throw new \RuntimeException('Unexpected npub/nsec decode shape');
            }
            $out->type = $hrp;
            $d = new \stdClass();
            $hex = '';
            foreach ($raw[1] as $byte) {
                $hex .= str_pad(\dechex($byte & 0xff), 2, '0', STR_PAD_LEFT);
            }
            $d->data = $hex;
            $out->data = $d;

            return $out;
        }

        if ($hrp === 'note') {
            if (!isset($raw['event_id']) || !\is_string($raw['event_id'])) {
                throw new \RuntimeException('Unexpected note decode shape');
            }
            $out->type = 'note';
            $d = new \stdClass();
            $d->data = $raw['event_id'];
            $d->relays = [];
            $out->data = $d;

            return $out;
        }

        $out->type = $hrp;
        $d = new \stdClass();
        if ($hrp === 'nprofile') {
            $d->pubkey = $raw['pubkey'] ?? '';
            $d->relays = isset($raw['relays']) && \is_array($raw['relays']) ? $raw['relays'] : [];
        } elseif ($hrp === 'nevent') {
            $d->id = (string) ($raw['event_id'] ?? '');
            $d->relays = isset($raw['relays']) && \is_array($raw['relays']) ? $raw['relays'] : [];
            $d->author = \array_key_exists('author', $raw) ? (string) $raw['author'] : null;
            if ($d->author === '') {
                $d->author = null;
            }
            $d->pubkey = $d->author;
            $d->kind = \array_key_exists('kind', $raw) && $raw['kind'] !== null && $raw['kind'] !== '' ? (int) $raw['kind'] : null;
        } elseif ($hrp === 'naddr') {
            $d->identifier = (string) ($raw['identifier'] ?? '');
            $d->pubkey = (string) ($raw['author'] ?? '');
            $d->relays = isset($raw['relays']) && \is_array($raw['relays']) ? $raw['relays'] : [];
            $d->kind = \array_key_exists('kind', $raw) && $raw['kind'] !== null && $raw['kind'] !== '' ? (int) $raw['kind'] : 0;
        } else {
            throw new \InvalidArgumentException('Unsupported NIP-19 prefix: '.$hrp);
        }
        $out->data = $d;

        return $out;
    }

    public function encodeNevent(string $eventIdHex, array $relays, string $authorHex, int $kind): string
    {
        $e = new Event();
        $e->setId(strtolower($eventIdHex));
        $e->setPublicKey(strtolower($authorHex));
        $e->setKind($kind);

        return $this->nip19Helper->encodeEvent($e, $relays, $authorHex, $kind);
    }

    public function encodeNaddr(int $kind, string $pubkeyHex, string $dTag, array $relays = []): string
    {
        $pk = strtolower($pubkeyHex);
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            throw new \InvalidArgumentException('Invalid pubkey hex for naddr.');
        }
        if ($dTag === '') {
            throw new \InvalidArgumentException('d tag required for naddr');
        }
        $e = new Event();
        $e->setPublicKey($pk);
        $e->setKind($kind);
        $e->setId(str_repeat('0', 64));

        return $this->nip19Helper->encodeAddr($e, $dTag, $kind, $pk, $relays);
    }
}
