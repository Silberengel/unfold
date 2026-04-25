<?php

namespace App\Factory;

use App\Entity\Article;
use App\Enum\EventStatusEnum;
use App\Enum\KindsEnum;
use InvalidArgumentException;

/**
 * Map nostr events of kind 30023 to local article entity
 */
class ArticleFactory
{
    public function createFromLongFormContentEvent($source): Article
    {
        if ($source->kind !== KindsEnum::LONGFORM->value) {
            throw new InvalidArgumentException('Source event kind should be 30023');
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
        $entity->setKind(KindsEnum::from($source->kind));
        $entity->setPubkey($source->pubkey);
        $entity->setSig($source->sig);
        $entity->setEventStatus(EventStatusEnum::PUBLISHED);
        $entity->setRatingNegative(0);
        $entity->setRatingPositive(0);
        // process tags
        foreach ($source->tags as $tag) {
            switch ($tag[0]) {
                case 'd':
                    $entity->setSlug($tag[1]);
                    break;
                case 'title':
                    $entity->setTitle($tag[1]);
                    break;
                case 'summary':
                    $entity->setSummary($tag[1]);
                    break;
                case 'image':
                    $entity->setImage($tag[1]);
                    break;
                case 'published_at':
                    $parsed = $this->parseEventTimeValue($tag[1] ?? null);
                    if ($parsed !== null) {
                        $entity->setPublishedAt($parsed);
                    }
                    break;
                case 't':
                    $entity->addTopic($tag[1]);
                    break;
                case 'client':
                    // used to signal where it was created, ignored for now
                    break;
            }
        }
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
