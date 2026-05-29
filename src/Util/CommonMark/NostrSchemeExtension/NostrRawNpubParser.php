<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Looks for raw nostr mentions formatted as npub1XXXX (with or without a leading @).
 */
readonly class NostrRawNpubParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('npub1[0-9a-zA-Z]+');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $fullMatch = $inlineContext->getFullMatch();

        // Label resolved at render time from kind-0 cache ({@see NostrMentionRenderer}).
        $inlineContext->getContainer()->appendChild(new NostrMentionLink(null, $fullMatch));

        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}
