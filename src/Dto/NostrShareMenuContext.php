<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Nostr "⋯" share menu: copy npub; copy naddr and/or nevent (Jumble /feed/notes/… uses the naddr when present, else nevent).
 * For NIP-33 replaceable events, both can be set: naddr is the coordinate, nevent is the specific revision.
 */
final class NostrShareMenuContext
{
    public function __construct(
        /** NIP-19 npub. Null only in rare fallbacks. */
        public ?string $npub,
        public ?string $neventBech32,
        public ?string $naddrBech32,
        public string $jumbleHref,
    ) {
    }
}
