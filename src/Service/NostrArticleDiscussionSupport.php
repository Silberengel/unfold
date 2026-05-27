<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;
use App\Nostr\Nip19Codec;
use swentel\nostr\Filter\Filter;

/**
 * REQ {@link Filter}s and tag-matching rules for long-form article discussion (NIP-22 kind 1111, legacy kind 1, quotes)
 * and NIP-A3 superchats (kind 9740 payment notifications attested by kind 9741 from the article author).
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

    /**
     * Additional REQ filters that fetch NIP-A3 superchats and Monero tips for an article:
     *   - kind 9740 (Lightning payment notifications) tagged `#a` with article coordinate
     *   - kind 9736 (Monero zap receipts) tagged `#a` with article coordinate
     *   - kind 1814 (Garnet self-attesting Monero tips) tagged `#a` with article coordinate
     *   - kind 9741 (payment attestations) published by the article author
     *
     * @return array<int, Filter>
     */
    public function createSuperchatFilters(string $coordinate, string $authorPubkeyHex): array
    {
        if ($authorPubkeyHex === '' || 64 !== \strlen($authorPubkeyHex) || !ctype_xdigit($authorPubkeyHex)) {
            return [];
        }

        $filters = [];

        // Lightning payment notifications (9740) — require 9741 attestation.
        $fNotif = new Filter();
        $fNotif->setKinds([KindsEnum::PAYMENT_NOTIFICATION->value]);
        $fNotif->setTag('#a', [$coordinate]);
        $fNotif->setLimit(50);
        $filters[] = $fNotif;

        // Monero zap receipts (9736) — analogous to kind 9735; require 9741 attestation.
        $fMoneroZap = new Filter();
        $fMoneroZap->setKinds([KindsEnum::MONERO_ZAP_RECEIPT->value]);
        $fMoneroZap->setTag('#a', [$coordinate]);
        $fMoneroZap->setLimit(50);
        $filters[] = $fMoneroZap;

        // Garnet Monero tips (1814) — self-attesting; proof is embedded in the JSON content.
        $fMoneroTip = new Filter();
        $fMoneroTip->setKinds([KindsEnum::MONERO_TIP->value]);
        $fMoneroTip->setTag('#a', [$coordinate]);
        $fMoneroTip->setLimit(50);
        $filters[] = $fMoneroTip;

        // Attestations from the article author covering any of the above.
        $fAttest = new Filter();
        $fAttest->setKinds([KindsEnum::PAYMENT_ATTESTATION->value]);
        $fAttest->setAuthors([$authorPubkeyHex]);
        $fAttest->setLimit(200);
        $filters[] = $fAttest;

        return $filters;
    }

    /**
     * Build the superchat item list from three event buckets:
     *
     * - `$attestRequiredEvents`: kind 9740 (Lightning payto) and kind 9736 (Monero zap receipt).
     *   Only included when the article author has published a matching kind 9741 attestation.
     * - `$selfAttestingEvents`: kind 1814 (Garnet Monero tips).
     *   These embed a cryptographic Monero payment proof in the JSON `content`, so no 9741 is needed.
     * - `$attestations`: kind 9741 events published by the article author.
     *
     * Items are sorted by payment amount descending (highest superchat first), then newest first.
     *
     * @param  list<object> $attestRequiredEvents  kind 9740 / 9736 events
     * @param  list<object> $selfAttestingEvents   kind 1814 events (Garnet Monero tips)
     * @param  list<object> $attestations          kind 9741 events (must be from the article author)
     * @param  string       $authorPubkeyHex       hex pubkey of the article author
     * @return list<array{id:string,pubkey:string,content:string,amount_msats:int,amount_sats:int,tier:string,payment_type:string,created_at:int}>
     */
    public function buildSuperchatItems(
        array $attestRequiredEvents,
        array $selfAttestingEvents,
        array $attestations,
        string $authorPubkeyHex,
    ): array {
        $authorHex = strtolower($authorPubkeyHex);

        // Build a set of event-IDs the author has attested (covers 9740, 9736, 9735).
        $attestedIds = [];
        foreach ($attestations as $att) {
            if (strtolower((string) ($att->pubkey ?? '')) !== $authorHex) {
                continue;
            }
            foreach ($att->tags ?? [] as $tag) {
                if (!\is_array($tag) || \count($tag) < 2) {
                    continue;
                }
                if ((string) ($tag[0] ?? '') === 'e') {
                    $eid = strtolower(trim((string) ($tag[1] ?? '')));
                    if (64 === \strlen($eid) && ctype_xdigit($eid)) {
                        $attestedIds[$eid] = true;
                    }
                }
            }
        }

        $items = [];

        // Attestation-required events (9740, 9736).
        foreach ($attestRequiredEvents as $notif) {
            $id = strtolower(trim((string) ($notif->id ?? '')));
            if ($id === '' || !isset($attestedIds[$id])) {
                continue;
            }
            $kind = (int) ($notif->kind ?? 0);
            $paymentType = $kind === KindsEnum::MONERO_ZAP_RECEIPT->value ? 'monero_zap' : 'lightning';
            $items[] = $this->buildSuperchatItemFromNotification($notif, $id, $paymentType);
        }

        // Self-attesting Monero tips (kind 1814 from Garnet).
        foreach ($selfAttestingEvents as $notif) {
            $id = strtolower(trim((string) ($notif->id ?? '')));
            if ($id === '') {
                continue;
            }
            $items[] = $this->buildSuperchatItemFromMoneroTip($notif, $id);
        }

        // Sort highest amount first, then newest first as tiebreaker.
        usort($items, static function (array $a, array $b): int {
            if ($b['amount_msats'] !== $a['amount_msats']) {
                return $b['amount_msats'] <=> $a['amount_msats'];
            }

            return $b['created_at'] <=> $a['created_at'];
        });

        return $items;
    }

    /**
     * @return array{id:string,pubkey:string,content:string,amount_msats:int,amount_sats:int,tier:string,payment_type:string,created_at:int}
     */
    private function buildSuperchatItemFromNotification(object $notif, string $id, string $paymentType): array
    {
        $amountMsats = 0;
        foreach ($notif->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            if ((string) ($tag[0] ?? '') === 'amount' && ctype_digit(trim((string) ($tag[1] ?? '')))) {
                $amountMsats = (int) $tag[1];
                break;
            }
        }

        return $this->assembleItem($id, $notif, (string) ($notif->content ?? ''), $amountMsats, $paymentType);
    }

    /**
     * Kind 1814 Garnet Monero tip: content is a JSON string with at minimum `{"message": "...", "txid": "..."}`.
     * Extract the `message` field as the display content; fall back to the raw content string.
     * Amount may be present in an `amount` tag (millisats) or absent.
     *
     * @return array{id:string,pubkey:string,content:string,amount_msats:int,amount_sats:int,tier:string,payment_type:string,created_at:int}
     */
    private function buildSuperchatItemFromMoneroTip(object $notif, string $id): array
    {
        $rawContent = (string) ($notif->content ?? '');
        $message = $rawContent;
        try {
            $parsed = json_decode($rawContent, true, 10, \JSON_THROW_ON_ERROR);
            if (\is_array($parsed) && isset($parsed['message']) && \is_string($parsed['message'])) {
                $message = $parsed['message'];
            }
        } catch (\JsonException) {
            // keep raw content as message
        }

        $amountMsats = 0;
        foreach ($notif->tags ?? [] as $tag) {
            if (!\is_array($tag) || \count($tag) < 2) {
                continue;
            }
            if ((string) ($tag[0] ?? '') === 'amount' && ctype_digit(trim((string) ($tag[1] ?? '')))) {
                $amountMsats = (int) $tag[1];
                break;
            }
        }

        return $this->assembleItem($id, $notif, $message, $amountMsats, 'monero_tip');
    }

    /**
     * @return array{id:string,pubkey:string,content:string,amount_msats:int,amount_sats:int,tier:string,payment_type:string,created_at:int}
     */
    private function assembleItem(string $id, object $notif, string $content, int $amountMsats, string $paymentType): array
    {
        $amountSats = (int) round($amountMsats / 1000);
        $tier = match (true) {
            $amountSats >= 100_000 => 'gold',
            $amountSats >= 10_000  => 'silver',
            $amountSats >= 1_000   => 'bronze',
            default                => '',
        };

        return [
            'id'           => $id,
            'pubkey'       => (string) ($notif->pubkey ?? ''),
            'content'      => $content,
            'amount_msats' => $amountMsats,
            'amount_sats'  => $amountSats,
            'tier'         => $tier,
            'payment_type' => $paymentType,
            'created_at'   => (int) ($notif->created_at ?? 0),
        ];
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
