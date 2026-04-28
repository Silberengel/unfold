<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrWireEvent;

/**
 * Validates NIP-22 kind-1111 and legacy kind-1 article-thread replies from logged-in users and publishes to article relays.
 */
final readonly class CommentReplyService
{
    private const STALE_EVENT_MAX_AGE_SEC = 600;

    public function __construct(
        private NostrClient $nostrClient,
        private LoggerInterface $logger,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    /**
     * @param array<string, mixed> $payload Decoded JSON body
     *
     * @return array{ok: true, id: string, relays: array<string, mixed>, ok_relays: int, total_relays: int}|array{ok: false, error: string, code: int}
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

        $expectedKind = $this->expectedReplyEventKindForParent($parentKind);
        if ($wire->getKind() !== $expectedKind) {
            return ['ok' => false, 'error' => 'Event kind does not match parent context (expected '.$expectedKind.')', 'code' => 400];
        }

        $now = time();
        if ($now - $wire->getCreatedAt() > self::STALE_EVENT_MAX_AGE_SEC || $wire->getCreatedAt() > $now + 60) {
            return ['ok' => false, 'error' => 'Event created_at out of range', 'code' => 400];
        }

        $userHex = $this->nostrKeyHelper->convertToHex($user->getNpub() ?? '');
        if ($userHex === '' || !hash_equals($userHex, $wire->getPublicKey())) {
            return ['ok' => false, 'error' => 'Pubkey does not match logged-in user', 'code' => 403];
        }

        if (!$this->tagsReferenceCoordinate($wire->getTags(), $expectedCoordinate, $wire->getKind())) {
            return ['ok' => false, 'error' => 'Tags must reference this article (a/A for NIP-22, or e for NIP-10 kind 1)', 'code' => 400];
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

        if (\in_array((int) $parentKind, [KindsEnum::COMMENTS->value, KindsEnum::TEXT_NOTE->value], true)) {
            if (!$clientParentOk) {
                return ['ok' => false, 'error' => 'parent_author_pubkey (64 hex) is required when replying to a note', 'code' => 400];
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
        $okRelays = 0;
        foreach ($result as $relayRes) {
            if ($relayRes instanceof \Throwable) {
                continue;
            }
            $okRelays++;
        }
        if ($okRelays < 1) {
            $this->logger->warning('comment_reply.publish_failed_all_relays', [
                'id' => $wire->getId(),
                'relay_count' => \count($result),
            ]);

            return ['ok' => false, 'error' => 'Publish failed on all relays (network/relay error). Please retry.', 'code' => 502];
        }
        $this->logger->info('comment_reply.published', [
            'id' => $wire->getId(),
            'relays' => \array_keys($result),
            'ok_relays' => $okRelays,
        ]);

        return [
            'ok' => true,
            'id' => $wire->getId(),
            'relays' => $result,
            'ok_relays' => $okRelays,
            'total_relays' => \count($result),
        ];
    }

    private function expectedReplyEventKindForParent(int $parentKind): int
    {
        if ($parentKind === KindsEnum::TEXT_NOTE->value) {
            return KindsEnum::TEXT_NOTE->value;
        }

        return KindsEnum::COMMENTS->value;
    }

    /**
     * NIP-22 (kind 1111) uses a/A; NIP-10 kind 1 uses e/p only (no address tag) — accept at least one valid e.
     *
     * @param array<int, mixed> $tags
     */
    private function tagsReferenceCoordinate(array $tags, string $coordinate, int $eventKind): bool
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
        if ($eventKind === KindsEnum::TEXT_NOTE->value) {
            return $this->hasValidEThreadRef($tags);
        }

        return false;
    }

    /**
     * NIP-10: kind-1 thread replies use e tags (not a).
     *
     * @param array<int, mixed> $tags
     */
    private function hasValidEThreadRef(array $tags): bool
    {
        foreach ($tags as $row) {
            if (!\is_array($row) || ($row[0] ?? null) === null) {
                continue;
            }
            $n = strtolower((string) $row[0]);
            if ($n !== 'e') {
                continue;
            }
            $id = isset($row[1]) && \is_string($row[1]) ? strtolower(trim($row[1])) : '';
            if (64 === \strlen($id) && ctype_xdigit($id)) {
                return true;
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
                if (($n === 'e' || $n === 'E') && \is_string($row[1] ?? null) && hash_equals($parentIdHex, strtolower((string) $row[1]))) {
                    return true;
                }
            }

            return false;
        }
        if ($parentKind === KindsEnum::TEXT_NOTE->value) {
            $want = strtolower($parentIdHex);
            foreach ($tags as $row) {
                if (!\is_array($row) || ($row[0] ?? null) === null) {
                    continue;
                }
                $n = (string) $row[0];
                if (($n === 'e' || $n === 'E') && \is_string($row[1] ?? null) && hash_equals($want, strtolower((string) $row[1]))) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
