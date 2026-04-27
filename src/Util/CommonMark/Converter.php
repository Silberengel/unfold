<?php

namespace App\Util\CommonMark;

use App\Nostr\Nip19Codec;
use App\Service\CacheService;
use App\Util\CommonMark\ImagesExtension\RawImageLinkExtension;
use App\Util\CommonMark\NostrSchemeExtension\NostrSchemeExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Embed\Bridge\OscaroteroEmbedAdapter;
use League\CommonMark\Extension\Embed\Embed;
use League\CommonMark\Extension\Embed\EmbedExtension;
use League\CommonMark\Extension\Embed\EmbedRenderer;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\SmartPunct\SmartPunctExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Renderer\HtmlDecorator;

readonly class Converter
{
    public function __construct(
        private CacheService $cacheService,
        private Nip19Codec $nip19Codec,
    ) {
    }

    /**
     * @throws CommonMarkException
     */
    public function convertToHTML(string $markdown): string
    {
        // Check if the article has more than three headings
        // Match all headings (from level 1 to 6)
        preg_match_all('/^#+\s.*$/m', $markdown, $matches);
        $headingsCount = count($matches[0]);

        // Configure the Environment with all the CommonMark parsers/renderers
        $config = [
            'table_of_contents' => [
                'min_heading_level' => 1,
                'max_heading_level' => 2,
            ],
            'heading_permalink' => [
                'symbol' => '§',
            ],
            'autolink' => [
                'allowed_protocols' => ['https', 'http'],
                'default_protocol' => 'https',
            ],
            'embed' => [
                'adapter' => new OscaroteroEmbedAdapter(), // See the "Adapter" documentation below
                'allowed_domains' => ['youtube.com', 'twitter.com', 'github.com'],
                'fallback' => 'link'
            ],
        ];
        $environment = new Environment($config);
        // Add the extensions
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new FootnoteExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());
        // create a custom extension, that handles nostr mentions
        $environment->addExtension(new NostrSchemeExtension($this->cacheService, $this->nip19Codec));
        $environment->addExtension(new SmartPunctExtension());
        $environment->addExtension(new EmbedExtension());
        $environment->addRenderer(Embed::class, new HtmlDecorator(new EmbedRenderer(), 'div', ['class' => 'embedded-content']));
        $environment->addExtension(new RawImageLinkExtension());
        $environment->addExtension(new AutolinkExtension());
        if ($headingsCount > 3) {
            $environment->addExtension(new HeadingPermalinkExtension());
            $environment->addExtension(new TableOfContentsExtension());
        }

        // Instantiate the converter engine and start converting some Markdown!
        $converter = new MarkdownConverter($environment);
        $content = html_entity_decode($markdown);

        $html = (string) $converter->convert($content);

        return $this->rewriteAutolinkedImageLinks($html);
    }

    /**
     * UrlAutolinkParser turns bare URLs into &lt;a href="U"&gt;U&lt;/a&gt;. If U is an image URL, show an img.
     * Covers cached HTML from before RawImageLinkParser priority fix and edge cases where autolink still wins.
     */
    private function rewriteAutolinkedImageLinks(string $html): string
    {
        return (string) preg_replace_callback(
            '#<a\b[^>]*\bhref="([^"]+)"[^>]*>\s*\1\s*</a>#i',
            static function (array $m): string {
                $url = $m[1];
                if (preg_match('~\.(?:jpe?g|png|gif|webp|avif)(?:\?[^#]*)?(?:#.*)?$~i', $url) !== 1) {
                    return $m[0];
                }

                $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<img src="' . $safe . '" alt="" loading="lazy" decoding="async" />';
            },
            $html
        );
    }

}

