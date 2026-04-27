<?php

declare(strict_types=1);

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Nostr\Nip19Codec;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Matches bare or @-prefixed naddr1 / nevent1 (NIP-19), so they render like nostr:… links.
 */
final class NostrBareBech32Parser implements InlineParserInterface
{
    public function __construct(
        private readonly Nip19Codec $nip19,
    ) {
    }
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('(?:@)?(?:naddr1|nevent1)[0-9a-z]+');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $fullMatch = $inlineContext->getFullMatch();
        $bech = ltrim($fullMatch, '@');

        if (!str_starts_with($bech, 'naddr1') && !str_starts_with($bech, 'nevent1')) {
            return false;
        }

        try {
            $decoded = $this->nip19->decode($bech);
        } catch (\Throwable) {
            return false;
        }

        if ($decoded->type === 'naddr') {
            $data = $decoded->data;
            $relays = $data->relays ?? [];
            $author = $data->pubkey ?? '';
            $kind = (int) ($data->kind ?? 0);
            $inlineContext->getContainer()->appendChild(new NostrSchemeData(
                'naddr',
                $bech,
                \is_array($relays) ? $relays : [],
                $author,
                $kind
            ));
        } elseif ($decoded->type === 'nevent') {
            $data = $decoded->data;
            $relays = $data->relays ?? [];
            $author = (string) ($data->author ?? $data->pubkey ?? '');
            $inlineContext->getContainer()->appendChild(new NostrSchemeData(
                'nevent',
                $bech,
                \is_array($relays) ? $relays : [],
                $author,
                (int) ($data->kind ?? 0)
            ));
        } else {
            return false;
        }

        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}
