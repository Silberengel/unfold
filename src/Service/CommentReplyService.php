<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrWireEvent;
use swentel\nostr\Key\Key;

/**
 * Validates NIP-22 kind-1111 comment events from logged-in users and publishes to article relays.
 */
final readonly class CommentReplyService
{
    private const STALE_EVENT_MAX_AGE_SEC = 600;

    public function __construct(
        private NostrClient $nostrClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload Decoded JSON body
     *
     * @return array{ok: true, id: string, relays: array<string, mixed>}|array{ok: false, error: string, code: int}
     */
    public function publishFromRequestPayload(User $user, array $payload): array
    {
        $raw = $payload['event'] ?? null;
        if (!\is_array($raw)) {
            return ['ok' => false, 'error' => 'Missing event', 'code' => 400];
        }
        $expectedCoordinate = isset($payload['expected_coordinate']) && \is_string($payload['expected_coordinate'])
            ? $payload['expected_coordinate']
            : '';
        if ($expectedCoordinate === '' || 3 !== \count(explode(':', $expectedCoordinate, 3))) {
            return ['ok' => false, 'error' => 'Invalid expected_coordinate', 'code' => 400];
        }

        $parentKind = $payload['parent_kind'] ?? null;
        $parentId = isset($payload['parent_id']) && \is_string($payload['parent_id']) ? $payload['parent_id'] : '';
        if (!\is_int($parentKind) && !\is_string($parentKind)) {
            return ['ok' => false, 'error' => 'Invalid parent_kind', 'code' => 400];
        }
        $parentKind = (int) $parentKind;
        if ($parentId === '' || 64 !== \strlen($parentId) || !ctype_xdigit($parentId)) {
            return ['ok' => false, 'error' => 'Invalid parent_id', 'code' => 400];
        }

        if (isset($payload['article_event_id']) && \is_string($payload['article_event_id']) && $payload['article_event_id'] !== '') {
            $g = $payload['article_event_id'];
            if (64 !== \strlen($g) || !ctype_xdigit($g)) {
                return ['ok' => false, 'error' => 'Invalid article_event_id', 'code' => 400];
            }
        }

        $wire = NostrWireEvent::fromVerified(\json_encode($raw, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        if ($wire === null) {
            return ['ok' => false, 'error' => 'Invalid or unverifiable event', 'code' => 400];
        }

        if ($wire->getKind() !== KindsEnum::COMMENTS->value) {
            return ['ok' => false, 'error' => 'Event must be kind 1111', 'code' => 400];
        }

        $now = time();
        if ($now - $wire->getCreatedAt() > self::STALE_EVENT_MAX_AGE_SEC || $wire->getCreatedAt() > $now + 60) {
            return ['ok' => false, 'error' => 'Event created_at out of range', 'code' => 400];
        }

        $key = new Key();
        $userHex = $key->convertToHex($user->getNpub() ?? '');
        if ($userHex === '' || !hash_equals($userHex, $wire->getPublicKey())) {
            return ['ok' => false, 'error' => 'Pubkey does not match logged-in user', 'code' => 403];
        }

        if (!$this->tagsReferenceCoordinate($wire->getTags(), $expectedCoordinate)) {
            return ['ok' => false, 'error' => 'Tags must include a/A for this article', 'code' => 400];
        }

        if (!$this->tagsReferenceParent($wire->getTags(), $expectedCoordinate, $parentKind, $parentId)) {
            return ['ok' => false, 'error' => 'Tags must reference the selected parent (a/A for article or e/E for comment)', 'code' => 400];
        }

        $rawParentAuthor = isset($payload['parent_author_pubkey']) && \is_string($payload['parent_author_pubkey'])
            ? strtolower(trim($payload['parent_author_pubkey']))
            : '';
        $clientParentOk = 64 === \strlen($rawParentAuthor) && ctype_xdigit($rawParentAuthor);
        $coordBits = explode(':', $expectedCoordinate, 3);
        $articleAuthor = \count($coordBits) >= 2 ? strtolower((string) $coordBits[1]) : '';
        $articleAuthorOk = 64 === \strlen($articleAuthor) && ctype_xdigit($articleAuthor);

        if ((int) $parentKind === KindsEnum::COMMENTS->value) {
            if (!$clientParentOk) {
                return ['ok' => false, 'error' => 'parent_author_pubkey (64 hex) is required when replying to a comment', 'code' => 400];
            }
            $parentAuthorHex = $rawParentAuthor;
        } else {
            $parentAuthorHex = $clientParentOk ? $rawParentAuthor : $articleAuthor;
            if (!$clientParentOk && !$articleAuthorOk) {
                return ['ok' => false, 'error' => 'Invalid article coordinate; cannot determine author relays', 'code' => 400];
            }
        }

        $relays = $this->nostrClient->getRelayUrlsForCommentPublish($expectedCoordinate, $parentAuthorHex);
        $result = $this->nostrClient->publishEvent($wire, $relays);
        $this->logger->info('comment_reply.published', [
            'id' => $wire->getId(),
            'relays' => \array_keys($result),
        ]);

        return ['ok' => true, 'id' => $wire->getId(), 'relays' => $result];
    }

    /**
     * @param array<int, mixed> $tags
     */
    private function tagsReferenceCoordinate(array $tags, string $coordinate): bool
    {
        foreach ($tags as $row) {
            if (!\is_array($row) || ($row[0] ?? null) === null) {
                continue;
            }
            $n = (string) $row[0];
            if ($n === 'a' || $n === 'A') {
                if (($row[1] ?? '') === $coordinate) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $tags
     */
    private function tagsReferenceParent(
        array $tags,
        string $articleCoordinate,
        int $parentKind,
        string $parentIdHex
    ): bool {
        if (\in_array($parentKind, [KindsEnum::LONGFORM->value, KindsEnum::LONGFORM_DRAFT->value], true)) {
            foreach ($tags as $row) {
                if (!\is_array($row) || ($row[0] ?? null) === null) {
                    continue;
                }
                $n = (string) $row[0];
                if (($n === 'a' || $n === 'A') && ($row[1] ?? '') === $articleCoordinate) {
                    return true;
                }
            }

            return false;
        }
        if ($parentKind === KindsEnum::COMMENTS->value) {
            foreach ($tags as $row) {
                if (!\is_array($row) || ($row[0] ?? null) === null) {
                    continue;
                }
                $n = (string) $row[0];
                if (($n === 'e' || $n === 'E') && \is_string($row[1] ?? null) && hash_equals($parentIdHex, (string) $row[1])) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
