<?php

namespace App\Factory;

use App\Entity\Article;
use App\Enum\EventStatusEnum;
use App\Enum\KindsEnum;
use InvalidArgumentException;

/**
 * Map long-form (30023/30024) and wiki (30817) Nostr events to the Article entity.
 */
class ArticleFactory
{
    public function createFromLongFormContentEvent($source): Article
    {
        $kind = (int) ($source->kind ?? 0);
        if (!\in_array($kind, KindsEnum::longformKindValues(), true)) {
            throw new InvalidArgumentException('Source event kind must be a longform kind (30023, 30024, 30817), got '.$kind);
        }
        $entity = new Article();
        $entity->setRaw($source);
        $entity->setEventId($source->id);
        $created = $this->parseEventTimeValue($source->created_at ?? null);
        if ($created === null) {
            throw new InvalidArgumentException('Long-form event has invalid or missing created_at');
        }
        $entity->setCreatedAt($created);
        $entity->setContent($source->content);
        $entity->setKind(KindsEnum::from($kind));
        $entity->setPubkey($source->pubkey);
        $entity->setSig($source->sig);
        $entity->setEventStatus(EventStatusEnum::PUBLISHED);
        $entity->setRatingNegative(0);
        $entity->setRatingPositive(0);
        // process tags
        $wikiKinds = $kind === KindsEnum::WIKI->value ? [] : null;
        foreach ($source->tags as $tag) {
            if (!\is_array($tag) || !isset($tag[0])) {
                continue;
            }
            switch ($tag[0]) {
                case 'd':
                    $entity->setSlug($tag[1] ?? null);
                    break;
                case 'title':
                    $entity->setTitle($tag[1] ?? null);
                    break;
                case 'summary':
                    $entity->setSummary($tag[1] ?? null);
                    break;
                case 'image':
                    $entity->setImage($tag[1] ?? null);
                    break;
                case 'published_at':
                    $parsed = $this->parseEventTimeValue($tag[1] ?? null);
                    if ($parsed !== null) {
                        $entity->setPublishedAt($parsed);
                    }
                    break;
                case 't':
                    if (isset($tag[1])) {
                        $entity->addTopic($tag[1]);
                    }
                    break;
                case 'k':
                    // NIP-54: `k` tags list the Nostr kinds this wiki page specifies
                    if ($wikiKinds !== null && isset($tag[1]) && ctype_digit((string) $tag[1])) {
                        $wikiKinds[] = (int) $tag[1];
                    }
                    break;
                case 'client':
                    break;
            }
        }
        $entity->setWikiKinds($wikiKinds);

        return $entity;
    }

    /**
     * NIP-23 times are usually Unix seconds; `published_at` may be ISO or other strings that make createFromFormat('U', …) return false.
     */
    private function parseEventTimeValue(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) && !\is_int($raw) && !\is_float($raw)) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (ctype_digit($s)) {
            $sec = (int) $s;
            if ($sec > 0) {
                return (new \DateTimeImmutable('@'.$sec))->setTimezone(new \DateTimeZone('UTC'));
            }

            return null;
        }
        $fromU = \DateTimeImmutable::createFromFormat('U', $s);
        if ($fromU instanceof \DateTimeImmutable) {
            return $fromU;
        }
        try {
            return new \DateTimeImmutable($s);
        } catch (\Exception) {
            return null;
        }
    }
}
