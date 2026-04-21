<?php

declare(strict_types=1);

namespace App\Util\CommonMark\ImagesExtension;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Bare image URLs (https://…/.png etc.) → inline &lt;img&gt;.
 *
 * Must register with priority &gt; UrlAutolinkParser (0) so we run first; only
 * inline nodes may be appended (never wrap in Paragraph — that breaks the AST
 * and lets Autolink turn the URL into a link instead).
 */
final class RawImageLinkParser implements InlineParserInterface
{
    /** Same boundary rules as League\CommonMark\Extension\Autolink\UrlAutolinkParser */
    private const ALLOWED_PREVIOUS = [null, ' ', "\t", "\n", "\x0b", "\x0c", "\r", '*', '_', '~', '('];

    public function getMatchDefinition(): InlineParserMatch
    {
        // Case-insensitive extension; optional query/hash (CDN, Tenor, etc.)
        return InlineParserMatch::regex(
            '(?i)https?:\/\/[^\s<>\[\]()"\']+\.(?:jpe?g|png|gif|webp|avif)(?:\?[^\s<>\[\]()"\']*)?(?:#[^\s<>\[\]()"\']*)?(?=\s|$|[\])},;:!?\'"])'
        );
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        if (! \in_array($cursor->peek(-1), self::ALLOWED_PREVIOUS, true)) {
            return false;
        }

        $match = $inlineContext->getFullMatch();
        $path = parse_url($match, PHP_URL_PATH);
        $label = (\is_string($path) && $path !== '') ? basename($path) : '';

        $inlineContext->getContainer()->appendChild(new Image($match, $label));
        $cursor->advanceBy(\strlen($match));

        return true;
    }
}
