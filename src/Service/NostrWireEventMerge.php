<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event as PublicationEventEntity;
use App\Enum\KindsEnum;

/**
 * NIP-33 / NIP-01 wire event merge, #d tags, npub→hex. See {@see NostrClient} call sites.
 */
final readonly class NostrWireEventMerge
{
    private const NIP33_PARAMETERIZED_KIND_MIN = 30_000;
    private const NIP33_PARAMETERIZED_KIND_MAX = 39_999;

    public function __construct(
        private NostrKeyHelper $keyHelper,
    ) {
    }

    public function isReplaceableByKindAndPubkeyNip(int $kind): bool
    {
        return $kind === 0
            || $kind === 3
            || ($kind >= 10_000 && $kind < 20_000);
    }

    public function isNip33ParameterizedKind(int $kind): bool
    {
        return $kind >= self::NIP33_PARAMETERIZED_KIND_MIN
            && $kind <= self::NIP33_PARAMETERIZED_KIND_MAX;
    }

    private function replaceableKindPubkeyAddressFromWire(mixed $e): ?string
    {
        if (!\is_object($e)) {
            return null;
        }
        $k = (int) ($e->kind ?? 0);
        if (!$this->isReplaceableByKindAndPubkeyNip($k)) {
            return null;
        }
        $pk = (string) ($e->pubkey ?? '');
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return null;
        }

        return (string) $k.':'.strtolower($pk);
    }

    private function isValidNostrEventIdString(string $id): bool
    {
        return 64 === \strlen($id) && ctype_xdigit($id);
    }

    public function wireEventSupersedes(mixed $candidate, mixed $incumbent): bool
    {
        $c = $this->magazineEventCreatedAt($candidate);
        $i = $this->magazineEventCreatedAt($incumbent);
        if ($c !== $i) {
            return $c > $i;
        }
        $idC = $this->magazineEventId($candidate);
        $idI = $this->magazineEventId($incumbent);
        $vC = $this->isValidNostrEventIdString($idC);
        $vI = $this->isValidNostrEventIdString($idI);
        if ($vC !== $vI) {
            return $vC && !$vI;
        }
        if (!$vC) {
            if ($idC === $idI) {
                return false;
            }

            return $idC < $idI;
        }
        if ($idC === $idI) {
            return false;
        }

        return $idC < $idI;
    }

    private function kind0Nip01ReplaceableAddress(mixed $ev): ?string
    {
        if (!\is_object($ev) || (int) ($ev->kind ?? -1) !== KindsEnum::METADATA->value) {
            return null;
        }
        $pk = (string) ($ev->pubkey ?? '');
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return null;
        }

        return '0:'.strtolower($pk);
    }

    private function kind0ReplaceableIsNewer(mixed $candidate, mixed $incumbent): bool
    {
        return $this->wireEventSupersedes($candidate, $incumbent);
    }

    /**
     * @param list<mixed> $events
     *
     * @return array<string, object>
     */
    public function mergeKind0EventsByReplaceableAddress(array $events): array
    {
        $byAddress = [];
        foreach ($events as $ev) {
            $addr = $this->kind0Nip01ReplaceableAddress($ev);
            if ($addr === null) {
                continue;
            }
            if (!isset($byAddress[$addr]) || $this->kind0ReplaceableIsNewer($ev, $byAddress[$addr])) {
                $byAddress[$addr] = $ev;
            }
        }

        return $byAddress;
    }

    public function nip33ParameterizedReplaceableAddress(mixed $event): ?string
    {
        $k = $this->magazineEventKind($event);
        if (!$this->isNip33ParameterizedKind($k)) {
            return null;
        }
        $pk = $this->magazineEventPubkeyHex($event);
        if ($pk === '' || 64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return null;
        }
        $d = $this->eventDTagValue($event);
        if ($d === null || $d === '') {
            return null;
        }

        return (string) $k.':'.strtolower($pk).':'.$d;
    }

    /**
     * @param list<mixed> $events
     *
     * @return list<object>
     */
    public function mergeNip33ParameterizedWireEvents(array $events): array
    {
        $byNip33Address = [];
        $byKindPubkey = [];
        $byId = [];
        foreach ($events as $e) {
            if (!\is_object($e)) {
                continue;
            }
            $k = (int) ($e->kind ?? 0);
            if ($this->isNip33ParameterizedKind($k)) {
                $a = $this->nip33ParameterizedReplaceableAddress($e);
                if ($a === null) {
                    continue;
                }
                if (!isset($byNip33Address[$a]) || $this->wireEventSupersedes($e, $byNip33Address[$a])) {
                    $byNip33Address[$a] = $e;
                }
            } elseif ($this->isReplaceableByKindAndPubkeyNip($k)) {
                $a = $this->replaceableKindPubkeyAddressFromWire($e);
                if ($a === null) {
                    continue;
                }
                if (!isset($byKindPubkey[$a]) || $this->wireEventSupersedes($e, $byKindPubkey[$a])) {
                    $byKindPubkey[$a] = $e;
                }
            } else {
                $id = (string) ($e->id ?? '');
                if ($id === '') {
                    continue;
                }
                if (!isset($byId[$id]) || $this->wireEventSupersedes($e, $byId[$id])) {
                    $byId[$id] = $e;
                }
            }
        }

        return array_values(array_merge($byId, $byKindPubkey, $byNip33Address));
    }

    /**
     * @param list<mixed> $events
     */
    public function pickLatestNip33ParameterizedForQuery(
        array $events,
        int $expectedKind,
        string $authorHexLower,
        string $dTag
    ): mixed {
        if (!$this->isNip33ParameterizedKind($expectedKind)) {
            return null;
        }
        $wantD = trim($dTag);
        $expectedAddr = (string) $expectedKind.':'.$authorHexLower.':'.$wantD;

        $merged = $this->mergeNip33ParameterizedWireEvents($events);
        foreach ($merged as $e) {
            if ($this->magazineEventKind($e) !== $expectedKind) {
                continue;
            }
            if (strtolower($this->magazineEventPubkeyHex($e)) !== $authorHexLower) {
                continue;
            }
            $addr = $this->nip33ParameterizedReplaceableAddress($e);
            if ($addr === $expectedAddr) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $events
     */
    public function pickEventForNip33OrFirst(array $events, int $kind, string $authorIdent, string $dTag): ?object
    {
        if ($events === []) {
            return null;
        }
        if ($this->isNip33ParameterizedKind($kind)) {
            $h = $this->authorIdentToHexLower($authorIdent);
            if ($h !== null) {
                $picked = $this->pickLatestNip33ParameterizedForQuery($events, $kind, $h, $dTag);
                if ($picked !== null && \is_object($picked)) {
                    return $picked;
                }
            }
            $merged = $this->mergeNip33ParameterizedWireEvents($events);
            $first = $merged[0] ?? null;

            return \is_object($first) ? $first : null;
        }
        if ($this->isReplaceableByKindAndPubkeyNip($kind)) {
            $h = $this->authorIdentToHexLower($authorIdent);
            if ($h !== null) {
                $best = null;
                foreach ($events as $e) {
                    if (!\is_object($e) || (int) ($e->kind ?? 0) !== $kind) {
                        continue;
                    }
                    if (strtolower((string) ($e->pubkey ?? '')) !== $h) {
                        continue;
                    }
                    if ($best === null || $this->wireEventSupersedes($e, $best)) {
                        $best = $e;
                    }
                }
                if ($best !== null) {
                    return $best;
                }
            }
            foreach ($this->mergeNip33ParameterizedWireEvents($events) as $e) {
                if ((int) ($e->kind ?? 0) === $kind) {
                    return $e;
                }
            }

            return null;
        }
        $e0 = $events[0] ?? null;

        return \is_object($e0) ? $e0 : null;
    }

    public function authorIdentToHexLower(mixed $ident): ?string
    {
        return $this->npubToHexPubkey($ident);
    }

    public function npubToHexPubkey(mixed $npub): ?string
    {
        $s = trim((string) $npub);
        if ($s === '') {
            return null;
        }
        if (64 === \strlen($s) && ctype_xdigit($s)) {
            return strtolower($s);
        }
        if (str_starts_with($s, 'npub')) {
            $hex = $this->keyHelper->convertToHex($s);

            return $hex !== '' && 64 === \strlen($hex) && ctype_xdigit($hex) ? strtolower($hex) : null;
        }

        return null;
    }

    public function eventDTagValue(mixed $event): ?string
    {
        $tags = null;
        if ($event instanceof PublicationEventEntity) {
            $tags = $event->getTags();
        } elseif (\is_object($event) && isset($event->tags) && \is_array($event->tags)) {
            $tags = $event->tags;
        }
        if (!\is_array($tags)) {
            return null;
        }
        foreach ($tags as $t) {
            $seq = $this->normalizeNostrTagRowToSequence($t);
            if ($seq === null || ($seq[0] ?? '') !== 'd' || !isset($seq[1]) || (string) $seq[1] === '') {
                continue;
            }

            return trim((string) $seq[1]);
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function normalizeNostrTagRowToSequence(mixed $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if (\is_object($row)) {
            $row = get_object_vars($row);
        }
        if (!\is_array($row) || $row === []) {
            return null;
        }
        $seq = array_values(
            array_map(
                static fn (mixed $v): string => (string) $v,
                $row
            )
        );
        if ($seq[0] === '') {
            return null;
        }

        return $seq;
    }

    public function longformIngestShortSlug(string $slug, int $max = 100): string
    {
        $t = trim($slug);
        if (strlen($t) > $max) {
            return substr($t, 0, $max - 1).'…';
        }

        return $t;
    }

    /**
     * @return array{kind: int, id: string, created_at: int, d: string, nip33: ?string}
     */
    public function longformIngestEventWireSummary(object $e): array
    {
        $d = $this->eventDTagValue($e);
        $nip = $this->nip33ParameterizedReplaceableAddress($e);

        return [
            'kind' => (int) ($e->kind ?? 0),
            'id' => (string) ($e->id ?? ''),
            'created_at' => (int) ($e->created_at ?? 0),
            'd' => $d !== null && $d !== '' ? $this->longformIngestShortSlug($d, 80) : '',
            'nip33' => $nip,
        ];
    }

    public function magazineEventToPublicationEntity(mixed $raw): ?PublicationEventEntity
    {
        if ($raw instanceof PublicationEventEntity) {
            return $raw;
        }
        if (\is_array($raw)) {
            $data = $raw;
        } elseif (\is_object($raw)) {
            try {
                $data = json_decode(json_encode($raw, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (!\is_array($data)) {
                return null;
            }
        } else {
            return null;
        }
        $entity = new PublicationEventEntity();
        $entity->setId((string) ($data['id'] ?? ''));
        $entity->setKind((int) ($data['kind'] ?? 0));
        $entity->setPubkey((string) ($data['pubkey'] ?? ''));
        $entity->setContent((string) ($data['content'] ?? ''));
        $entity->setCreatedAt((int) ($data['created_at'] ?? 0));
        $tags = $data['tags'] ?? [];
        $entity->setTags(\is_array($tags) ? $tags : []);
        $entity->setSig((string) ($data['sig'] ?? ''));

        return $entity;
    }

    public function magazineEventCreatedAt(mixed $event): int
    {
        if ($event instanceof PublicationEventEntity) {
            return $event->getCreatedAt();
        }
        if (\is_object($event) && isset($event->created_at)) {
            return (int) $event->created_at;
        }

        return 0;
    }

    private function magazineEventId(mixed $event): string
    {
        if ($event instanceof PublicationEventEntity) {
            return $event->getId();
        }
        if (\is_object($event) && isset($event->id)) {
            return (string) $event->id;
        }

        return '';
    }

    private function magazineEventKind(mixed $event): int
    {
        if ($event instanceof PublicationEventEntity) {
            return $event->getKind();
        }
        if (\is_object($event) && isset($event->kind)) {
            return (int) $event->kind;
        }

        return 0;
    }

    private function magazineEventPubkeyHex(mixed $event): string
    {
        if ($event instanceof PublicationEventEntity) {
            return (string) $event->getPubkey();
        }
        if (\is_object($event) && isset($event->pubkey)) {
            return (string) $event->pubkey;
        }

        return '';
    }
}
