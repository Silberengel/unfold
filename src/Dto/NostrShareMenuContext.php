<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Nostr "⋯" share menu: copy npub; copy nevent or naddr (Jumble uses the same bech in /feed/notes/…).
 * Addressable (NIP-33) long-form / index events: prefer naddr; one-off stateless events: nevent.
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
