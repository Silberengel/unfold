<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\NostrShareMenuContext;
use App\Entity\Article;
use App\Entity\Event;
use App\Nostr\Nip19Addressable;
use App\Nostr\Nip19Codec;
use App\Repository\ArticleRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the header Nostr share menu (npub; naddr for addressables, else nevent; Jumble /feed/notes/…).
 */
final class NostrShareMenuBuilder
{
    public const string ATTR_NPUB = 'nostr_share_npub';

    public const string ATTR_NEVENT_BECH32 = 'nostr_share_nevent_bech32';

    public const string ATTR_NADDR_BECH32 = 'nostr_share_naddr_bech32';

    /**
     * NIP-19 + Jumble href for a wire event (replies, quotes, previews, /e/ page).
     */
    public function shareContextFromWireEvent(object $event, array $relayHints = []): ?NostrShareMenuContext
    {
        $pubkeyHex = strtolower((string) ($event->pubkey ?? ''));
        if (64 !== \strlen($pubkeyHex) || !ctype_xdigit($pubkeyHex)) {
            return null;
        }
        $npub = $this->nostrKeyHelper->convertPublicKeyToBech32($pubkeyHex);
        $kind = (int) ($event->kind ?? 0);
        $d = self::dTagFromWireEvent($event);
        $eventIdHex = strtolower((string) ($event->id ?? ''));
        if (Nip19Addressable::isParameterizedReplaceableKind($kind) && $d !== null) {
            $naddr = Nip19Addressable::naddrBech32($kind, $pubkeyHex, $d, $relayHints);
            $neventForRev = (64 === \strlen($eventIdHex) && ctype_xdigit($eventIdHex))
                ? $this->nip19->encodeNevent($eventIdHex, $relayHints, $pubkeyHex, $kind)
                : null;

            return new NostrShareMenuContext(
                $npub,
                $neventForRev,
                $naddr,
                $this->feedJumble($naddr),
            );
        }
        if (64 === \strlen($eventIdHex) && ctype_xdigit($eventIdHex)) {
            $rebuilt = $this->nip19->encodeNevent($eventIdHex, $relayHints, $pubkeyHex, $kind);

            return new NostrShareMenuContext(
                $npub,
                $rebuilt,
                null,
                $this->feedJumble($rebuilt),
            );
        }

        return new NostrShareMenuContext(
            $npub,
            null,
            null,
            $this->profileJumbleUrl($npub),
        );
    }

    public function shareContextForArticle(Article $article): NostrShareMenuContext
    {
        return $this->fromArticle($article);
    }

    public function applyWireEventToRequest(Request $request, object $event, array $relayHints = []): void
    {
        $ctx = $this->shareContextFromWireEvent($event, $relayHints);
        if (null === $ctx || null === $ctx->npub) {
            return;
        }
        $request->attributes->set(self::ATTR_NPUB, $ctx->npub);
        if ($ctx->naddrBech32 !== null && $ctx->naddrBech32 !== '') {
            $request->attributes->set(self::ATTR_NADDR_BECH32, $ctx->naddrBech32);
        }
        if ($ctx->neventBech32 !== null && $ctx->neventBech32 !== '') {
            $request->attributes->set(self::ATTR_NEVENT_BECH32, $ctx->neventBech32);
        }
    }

    /**
     * @param list<mixed>|\ArrayObject<int, mixed> $event->tags
     */
    private static function dTagFromWireEvent(object $event): ?string
    {
        if (!isset($event->tags)) {
            return null;
        }
        $rows = $event->tags;
        if ($rows instanceof \ArrayObject) {
            $rows = $rows->getArrayCopy();
        }
        if (!\is_array($rows)) {
            return null;
        }
        $norm = array_values(
            array_map(
                static function ($r) {
                    if (!\is_array($r) && !\is_object($r)) {
                        return $r;
                    }
                    if (\is_object($r)) {
                        $r = (array) $r;
                    }

                    return $r;
                },
                $rows
            )
        );

        return Nip19Addressable::dTagFromTagRows($norm);
    }

    public function __construct(
        private readonly MagazineIndexStore $magazineIndexStore,
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly ArticleRepository $articleRepository,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly Nip19Codec $nip19,
        #[Autowire('%npub%')]
        private readonly string $siteNpub,
        #[Autowire('%d_tag%')]
        private readonly string $rootDTag,
        #[Autowire('%jumble_profile_users_base%')]
        private readonly string $jumbleProfileUsersBase,
        #[Autowire('%jumble_feed_notes_base%')]
        private readonly string $jumbleFeedNotesBase,
        #[Autowire('%alexandria_publication_base%')]
        private readonly string $alexandriaPublicationBase,
    ) {
    }

    /**
     * Context for the header Nostr menu. Always returns a context on real HTTP requests (never null).
     * Templates that do not include the header never call this; no need to suppress on XHR / fragments.
     */
    public function buildForRequest(Request $request): NostrShareMenuContext
    {
        $route = (string) $request->attributes->get('_route', '');
        if ('' === $route) {
            return $this->siteWithRootMenu();
        }

        return match ($route) {
            'home' => $this->siteWithRootMenu(),
            'article' => $this->forArticleNpubD(
                (string) $request->attributes->get('npub', ''),
                (string) $request->attributes->get('slug', ''),
            ),
            'author-profile' => $this->forAuthorProfile($request->attributes->get('npub', '')),
            'nevent' => $this->forNevent($request, (string) $request->attributes->get('nevent', '')),
            'magazine-category' => $this->forCategory($request->attributes->get('slug', '')),
            'publication' => $this->forPublication(
                (string) $request->attributes->get('npub', ''),
                (string) $request->attributes->get('slug', ''),
            ),
            'articles', 'featured_authors', 'search', 'article-preview', 'article-preview-event', 'editor-create', 'editor-edit' => $this->siteWithRootMenu(),
            default => $this->siteWithRootMenu(),
        };
    }

    private function forArticleNpubD(string $npub, string $slug): NostrShareMenuContext
    {
        if ($npub === '' || $slug === '' || !str_starts_with($npub, 'npub1')) {
            return $this->siteWithRootMenu();
        }
        $article = $this->articleRepository->findLatestBySlugForTenant(
            $slug,
            $this->nostrKeyHelper->convertToHex($npub),
        );
        if ($article === null) {
            return $this->siteWithRootMenu();
        }
        if ($this->nostrKeyHelper->convertToHex($npub) !== strtolower((string) $article->getPubkey())) {
            return $this->siteWithRootMenu();
        }

        return $this->fromArticle($article);
    }

    private function fromArticle(Article $article): NostrShareMenuContext
    {
        $npub = $this->nostrKeyHelper->convertPublicKeyToBech32((string) $article->getPubkey());
        $kind = $article->getKind()->value;
        $d = (string) ($article->getSlug() ?? '');
        if ($d === '') {
            return new NostrShareMenuContext(
                $npub,
                null,
                null,
                $this->profileJumbleUrl($npub),
            );
        }
        $pk = strtolower((string) $article->getPubkey());
        $naddr = Nip19Addressable::naddrBech32($kind, $pk, $d, []);
        $eid = strtolower((string) ($article->getEventId() ?? ''));
        $nevent = (64 === \strlen($eid) && ctype_xdigit($eid))
            ? $this->nip19->encodeNevent($eid, [], $pk, $kind)
            : null;

        return new NostrShareMenuContext(
            $npub,
            $nevent,
            $naddr,
            $this->feedJumble($naddr),
        );
    }

    private function forAuthorProfile(mixed $npubParam): NostrShareMenuContext
    {
        $npub = (string) $npubParam;
        if ($npub === '' || !str_starts_with($npub, 'npub1')) {
            return $this->siteWithRootMenu();
        }

        return new NostrShareMenuContext(
            $npub,
            null,
            null,
            $this->profileJumbleUrl($npub),
        );
    }

    private function forNevent(Request $request, string $neventFromRoute): NostrShareMenuContext
    {
        if ($request->attributes->has(self::ATTR_NPUB)
            && ($request->attributes->has(self::ATTR_NADDR_BECH32) || $request->attributes->has(self::ATTR_NEVENT_BECH32))) {
            $np = (string) $request->attributes->get(self::ATTR_NPUB);
            $naddrRaw = $request->attributes->get(self::ATTR_NADDR_BECH32);
            $naddr = \is_string($naddrRaw) && $naddrRaw !== '' ? $naddrRaw : null;
            $neventRaw = $request->attributes->get(self::ATTR_NEVENT_BECH32);
            $nb = \is_string($neventRaw) && $neventRaw !== '' ? $neventRaw : null;
            if (null !== $naddr || null !== $nb) {
                $jumble = $this->feedJumble($naddr ?? $nb);

                return new NostrShareMenuContext(
                    $np,
                    $nb,
                    $naddr,
                    $jumble,
                );
            }
        }

        $nevent = $neventFromRoute;
        if ($nevent === '' || !str_starts_with($nevent, 'nevent1')) {
            return $this->siteWithRootMenu();
        }
        try {
            $decoded = $this->nip19->decode($nevent);
        } catch (\Throwable) {
            return $this->siteWithRootMenu();
        }
        if ($decoded->type !== 'nevent' || !isset($decoded->data->id)) {
            return $this->siteWithRootMenu();
        }
        $eventId = strtolower((string) $decoded->data->id);
        if (64 !== \strlen($eventId) || !ctype_xdigit($eventId)) {
            return $this->siteWithRootMenu();
        }
        $authorHex = $decoded->data->author ?? null;
        if (\is_string($authorHex) && 64 === \strlen($authorHex) && ctype_xdigit($authorHex)) {
            $authorHex = strtolower($authorHex);
        } else {
            $authorHex = null;
        }
        $kind = isset($decoded->data->kind) ? (int) $decoded->data->kind : 1;
        $relays = $decoded->data->relays ?? [];
        $relays = \is_array($relays) ? $relays : [];
        if ($authorHex !== null) {
            $rebuilt = $this->nip19->encodeNevent($eventId, $relays, $authorHex, $kind);

            return new NostrShareMenuContext(
                $this->nostrKeyHelper->convertPublicKeyToBech32($authorHex),
                $rebuilt,
                null,
                $this->feedJumble($rebuilt),
            );
        }

        return new NostrShareMenuContext(
            null,
            $nevent,
            null,
            $this->feedJumble($nevent),
        );
    }

    private function forCategory(string $slug): NostrShareMenuContext
    {
        if ($slug === '') {
            return $this->siteWithRootMenu();
        }
        $cat = $this->magazineIndexStore->getCategory($slug);
        if ($cat === null) {
            return $this->siteWithRootMenu();
        }

        return $this->fromNostrEvent($cat) ?? $this->siteWithRootMenu();
    }

    private function forPublication(string $npub, string $slug): NostrShareMenuContext
    {
        if ($npub === '' || $slug === '' || !str_starts_with($npub, 'npub1')) {
            return $this->siteWithRootMenu();
        }
        $index = $this->publicationIndexStore->getByNpubAndD($npub, $slug);
        if ($index === null) {
            return $this->siteWithRootMenu();
        }

        return $this->fromNostrEventForPublication($index) ?? $this->siteWithRootMenu();
    }

    private function fromNostrEvent(Event $e): ?NostrShareMenuContext
    {
        $id = strtolower($e->getId());
        if (64 !== \strlen($id) || !ctype_xdigit($id)) {
            return null;
        }
        $pk = strtolower($e->getPubkey());
        if (64 !== \strlen($pk) || !ctype_xdigit($pk)) {
            return null;
        }
        $kind = (int) $e->getKind();
        $d = Nip19Addressable::dTagFromEventEntity($e);
        $npub = $this->nostrKeyHelper->convertPublicKeyToBech32($pk);
        if (Nip19Addressable::isParameterizedReplaceableKind($kind) && $d !== null) {
            $naddr = Nip19Addressable::naddrBech32($kind, $pk, $d, []);
            $neventForRev = $this->nip19->encodeNevent($id, [], $pk, $kind);

            return new NostrShareMenuContext(
                $npub,
                $neventForRev,
                $naddr,
                $this->feedJumble($naddr),
            );
        }
        $nevent = $this->nip19->encodeNevent($id, [], $pk, $kind);

        return new NostrShareMenuContext(
            $npub,
            $nevent,
            null,
            $this->feedJumble($nevent),
        );
    }

    private function fromNostrEventForPublication(Event $e): ?NostrShareMenuContext
    {
        $ctx = $this->fromNostrEvent($e);
        if ($ctx === null || $ctx->naddrBech32 === null || $ctx->naddrBech32 === '') {
            return $ctx;
        }
        $alexandria = $this->alexandriaPublicationUrl($ctx->naddrBech32);
        if ($alexandria === null) {
            return $ctx;
        }

        return new NostrShareMenuContext(
            $ctx->npub,
            $ctx->neventBech32,
            $ctx->naddrBech32,
            $ctx->jumbleHref,
            $alexandria,
            'View on Alexandria',
        );
    }

    private function siteWithRootMenu(): NostrShareMenuContext
    {
        $root = $this->magazineIndexStore->getRoot($this->siteNpub, $this->rootDTag);
        if (null === $fromRoot = $root ? $this->fromNostrEvent($root) : null) {
            return new NostrShareMenuContext(
                $this->siteNpub,
                null,
                null,
                $this->profileJumbleUrl($this->siteNpub),
            );
        }

        return $fromRoot;
    }

    private function profileJumbleUrl(string $npub): string
    {
        $b = rtrim($this->jumbleProfileUsersBase, '/');

        return $b === '' ? '#' : $b.'/'.$npub;
    }

    private function feedJumble(string $naddrOrNeventOrNoteBech32): string
    {
        $b = rtrim($this->jumbleFeedNotesBase, '/');

        return $b === '' ? $naddrOrNeventOrNoteBech32 : $b.'/'.$naddrOrNeventOrNoteBech32;
    }

    private function alexandriaPublicationUrl(string $naddrBech32): ?string
    {
        $base = rtrim($this->alexandriaPublicationBase, '/');
        if ($base === '' || !str_starts_with($naddrBech32, 'naddr1')) {
            return null;
        }

        return $base.'/publication/naddr/'.$naddrBech32;
    }
}
