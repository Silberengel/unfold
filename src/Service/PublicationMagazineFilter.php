<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Util\NostrEventTags;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Excludes this tenant's kind-30040 magazine indices from community publication ingest.
 */
final class PublicationMagazineFilter
{
    public function __construct(
        private readonly ParameterBagInterface $params,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function isSiteMagazineIndex(Event $event): bool
    {
        if ($event->getKind() !== KindsEnum::PUBLICATION_INDEX->value) {
            return false;
        }

        $siteNpub = (string) $this->params->get('npub');
        $siteHex = strtolower($this->nostrKeyHelper->convertToHex($siteNpub));
        $eventHex = strtolower($event->getPubkey());
        if ($siteHex === '' || !hash_equals($siteHex, $eventHex)) {
            return false;
        }

        $d = $event->getSlug();
        if ($d === null || $d === '') {
            return false;
        }

        $rootD = (string) $this->params->get('d_tag');
        if ($d === $rootD) {
            return true;
        }

        return $this->hasMagazineTypeTag($event);
    }

    /**
     * @param object|array<string, mixed> $wire Nostr wire event before persistence
     */
    public function isSiteMagazineWire(mixed $wire): bool
    {
        $entity = $this->wireToEventShape($wire);
        if ($entity === null) {
            return false;
        }

        return $this->isSiteMagazineIndex($entity);
    }

    private function hasMagazineTypeTag(Event $event): bool
    {
        foreach ($event->getTags() as $tag) {
            if (!NostrEventTags::tagNameMatches($tag, 'type')) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($tag);
            if ($seq !== null && isset($seq[1]) && strtolower((string) $seq[1]) === 'magazine') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param object|array<string, mixed> $wire
     */
    private function wireToEventShape(mixed $wire): ?Event
    {
        if (\is_object($wire)) {
            $kind = (int) ($wire->kind ?? 0);
            $pubkey = (string) ($wire->pubkey ?? '');
            $tags = $wire->tags ?? [];
        } elseif (\is_array($wire)) {
            $kind = (int) ($wire['kind'] ?? 0);
            $pubkey = (string) ($wire['pubkey'] ?? '');
            $tags = $wire['tags'] ?? [];
        } else {
            return null;
        }

        $e = new Event();
        $e->setKind($kind);
        $e->setPubkey($pubkey);
        $e->setTags(\is_array($tags) ? $tags : []);
        foreach ($e->getTags() as $tag) {
            if (NostrEventTags::tagNameMatches($tag, 'd')) {
                $seq = NostrEventTags::rowToStringList($tag);
                if ($seq !== null && isset($seq[1])) {
                    $e->setId('tmp');
                    // slug via reflection-free: use getSlug after tags set
                    break;
                }
            }
        }

        return $e;
    }
}
