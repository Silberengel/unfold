<?php

declare(strict_types=1);

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Nostr\Nip19Codec;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Matches bare or @-prefixed nprofile1 (NIP-19) for user-badge rendering.
 */
final class NostrBareNprofileParser implements InlineParserInterface
{
    public function __construct(
        private readonly Nip19Codec $nip19,
    ) {
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('(?:@)?nprofile1[0-9a-z]+');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $fullMatch = $inlineContext->getFullMatch();
        $bech = ltrim($fullMatch, '@');

        try {
            $decoded = $this->nip19->decode($bech);
        } catch (\Throwable) {
            return false;
        }

        if ($decoded->type !== 'nprofile' || !isset($decoded->data->pubkey)) {
            return false;
        }

        $inlineContext->getContainer()->appendChild(new NostrMentionLink(null, (string) $decoded->data->pubkey));
        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}
