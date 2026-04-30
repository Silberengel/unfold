<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;
use App\Nostr\Nip19Codec;
use swentel\nostr\Filter\Filter;

/**
 * REQ {@link Filter}s and tag-matching rules for long-form article discussion (NIP-22 kind 1111, legacy kind 1, quotes).
 * Used by {@see NostrClient::getArticleDiscussion()}.
 */
final class NostrArticleDiscussionSupport
{
    public function __construct(
        private readonly Nip19Codec $nip19Codec,
    ) {
    }
    /**
     * @return array<int, Filter>
     */
    public function createArticleDiscussionFilters(string $coordinate, ?string $rootEventHexId): array
    {
        $limThread = 100;
        $limQuote = 80;

        $filters = [];

        $k1111 = KindsEnum::COMMENTS->value;
        $f = new Filter();
        $f->setKinds([$k1111]);
        $f->setTag('#A', [$coordinate]);
        $f->setLimit($limThread);
        $filters[] = $f;
        $f = new Filter();
        $f->setKinds([$k1111]);
        $f->setTag('#a', [$coordinate]);
        $f->setLimit($limThread);
        $filters[] = $f;

        $k1 = KindsEnum::TEXT_NOTE->value;
        $f = new Filter();
        $f->setKinds([$k1]);
        $f->setTag('#A', [$coordinate]);
        $f->setLimit($limThread);
        $filters[] = $f;
        $f = new Filter();
        $f->setKinds([$k1]);
        $f->setTag('#a', [$coordinate]);
        $f->setLimit($limThread);
        $filters[] = $f;

        if ($rootEventHexId !== null && $rootEventHexId !== '') {
            $f = new Filter();
            $f->setKinds([$k1]);
            $f->setTag('#e', [$rootEventHexId]);
            $f->setLimit($limThread);
            $filters[] = $f;
        }

        $qKinds = [
            KindsEnum::TEXT_NOTE->value,
            KindsEnum::REPOST->value,
            KindsEnum::GENERIC_REPOST->value,
            KindsEnum::COMMENTS->value,
        ];
        $qVals = [$coordinate];
        if ($rootEventHexId !== null && $rootEventHexId !== '') {
            $qVals[] = $rootEventHexId;
        }
        $f = new Filter();
        $f->setKinds($qKinds);
        $f->setTag('#q', $qVals);
        $f->setLimit($limQuote);
        $filters[] = $f;

        $f = new Filter();
        $f->setKinds([KindsEnum::GENERIC_REPOST->value]);
        $f->setTag('#a', [$coordinate]);
        $f->setLimit(50);
        $filters[] = $f;

        return $filters;
    }

    public function eventIsNip22ArticleThreadReply(object $event, string $coordinate): bool
    {
        if ((int) ($event->kind ?? 0) !== KindsEnum::COMMENTS->value) {
            return false;
        }
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            $name = (string) ($tag[0] ?? '');
            if (($name === 'a' || $name === 'A') && (string) ($tag[1] ?? '') === $coordinate) {
                return true;
            }
        }

        return false;
    }

    public function eventIsLegacyThreadReply(object $event, string $coordinate, ?string $rootEventHexId): bool
    {
        if ((int) ($event->kind ?? 0) !== KindsEnum::TEXT_NOTE->value) {
            return false;
        }
        // Kind 1 “quotes” often lack `q`; clients embed nostr:naddr… in content instead. Those are not thread replies.
        if ($this->isKind1NaddrBodyQuote($event, $coordinate)) {
            return false;
        }
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            $name = (string) ($tag[0] ?? '');
            $val = (string) ($tag[1] ?? '');
            if (($name === 'a' || $name === 'A') && $val === $coordinate) {
                return true;
            }
            if ($rootEventHexId !== null && $rootEventHexId !== '' && $name === 'e' && $val === $rootEventHexId) {
                return true;
            }
        }

        return false;
    }

    public function eventIsArticleQuote(object $event, string $coordinate, ?string $rootEventHexId): bool
    {
        $kind = (int) ($event->kind ?? 0);
        if ($kind === KindsEnum::HIGHLIGHTS->value) {
            return false;
        }
        if ($kind === KindsEnum::COMMENTS->value) {
            foreach ($event->tags ?? [] as $tag) {
                if (!\is_array($tag) || \count($tag) < 2) {
                    continue;
                }
                if (($tag[0] ?? '') === 'q') {
                    $val = (string) ($tag[1] ?? '');
                    if ($val === $coordinate || ($rootEventHexId !== null && $val === $rootEventHexId)) {
                        return true;
                    }
                }
            }

            return false;
        }
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            $name = (string) ($tag[0] ?? '');
            $val = (string) ($tag[1] ?? '');
            if ($name === 'q') {
                if ($val === $coordinate || ($rootEventHexId !== null && $val === $rootEventHexId)) {
                    return true;
                }
            }
        }
        if ($kind === KindsEnum::GENERIC_REPOST->value) {
            foreach ($event->tags ?? [] as $tag) {
                if (!\is_array($tag) || \count($tag) < 2) {
                    continue;
                }
                if (($tag[0] ?? '') === 'a' && (string) ($tag[1] ?? '') === $coordinate) {
                    return true;
                }
            }
        }
        if ($kind === KindsEnum::TEXT_NOTE->value && $this->isKind1NaddrBodyQuote($event, $coordinate)) {
            return true;
        }

        return false;
    }

    /**
     * Kind-1 note that cites this article via nostr:naddr… in .content (no `q` tag) — not a threaded reply.
     * Requires no {@code e} tags; if {@code e} tags are present, NIP-10 treats it as a thread reply.
     */
    private function isKind1NaddrBodyQuote(object $event, string $coordinate): bool
    {
        if ($this->kind1HasThreadETag($event)) {
            return false;
        }
        $content = (string) ($event->content ?? '');

        return $this->contentNaddrReferencesCoordinate($content, $coordinate);
    }

    private function kind1HasThreadETag(object $event): bool
    {
        foreach ($event->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            if (strtolower((string) ($tag[0] ?? '')) !== 'e') {
                continue;
            }
            $id = strtolower(trim((string) ($tag[1] ?? '')));
            if (64 === \strlen($id) && ctype_xdigit($id)) {
                return true;
            }
        }

        return false;
    }

    private function contentNaddrReferencesCoordinate(string $content, string $coordinate): bool
    {
        if ($content === '' || !preg_match_all('/(?:nostr:)?(naddr1[a-z0-9]+)/i', $content, $matches)) {
            return false;
        }
        $want = strtolower($coordinate);
        foreach ($matches[1] as $bech) {
            try {
                $decoded = $this->nip19Codec->decode((string) $bech);
            } catch (\Throwable) {
                continue;
            }
            if ($decoded->type !== 'naddr' || !isset($decoded->data)) {
                continue;
            }
            $d = $decoded->data;
            $kind = (int) ($d->kind ?? 0);
            $pk = strtolower((string) ($d->pubkey ?? ''));
            $identifier = (string) ($d->identifier ?? '');
            if ($pk === '' || $identifier === '' || (64 !== \strlen($pk) || !ctype_xdigit($pk))) {
                continue;
            }
            $built = $kind.':'.$pk.':'.$identifier;
            if ($built === $want) {
                return true;
            }
        }

        return false;
    }
}
