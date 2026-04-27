<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Nostr\Nip19Codec;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

class NostrSchemeParser  implements InlineParserInterface
{
    public function __construct(
        private readonly Nip19Codec $nip19,
    ) {
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('nostr:[0-9a-zA-Z]+');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        // Get the match and extract relevant parts
        $fullMatch = $inlineContext->getFullMatch();
        // The match is a Bech32 encoded string
        // decode it to get the parts
        $bechEncoded = substr($fullMatch, 6);  // Extract the part after "nostr:", i.e., "XXXX"

        try {
            $decoded = $this->nip19->decode($bechEncoded);

            switch ($decoded->type) {
                case 'npub':
                    $inlineContext->getContainer()->appendChild(new NostrMentionLink(null, $bechEncoded));
                    break;
                case 'nprofile':
                    $decodedProfile = $decoded->data;
                    $inlineContext->getContainer()->appendChild(new NostrMentionLink(null, $decodedProfile->pubkey));
                    break;
                case 'nevent':
                    $decodedEvent = $decoded->data;
                    $relays = $decodedEvent->relays ?? [];
                    $author = $decodedEvent->author;
                    $kind = $decodedEvent->kind;
                    $inlineContext->getContainer()->appendChild(new NostrSchemeData('nevent', $bechEncoded, \is_array($relays) ? $relays : [], (string) $author, (int) ($kind ?? 0)));
                    break;
                case 'naddr':
                    $decodedEvent = $decoded->data;
                    $relays = $decodedEvent->relays ?? [];
                    $pubkey = $decodedEvent->pubkey;
                    $kind = (int) ($decodedEvent->kind ?? 0);
                    $inlineContext->getContainer()->appendChild(new NostrSchemeData('naddr', $bechEncoded, \is_array($relays) ? $relays : [], (string) $pubkey, $kind));
                    break;
                case 'nrelay':
                    // deprecated
                default:
                    return false;
            }

        } catch (\Exception $e) {
            // dump($e->getMessage());
            return false;
        }

        // Advance the cursor to consume the matched part (important!)
        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}
