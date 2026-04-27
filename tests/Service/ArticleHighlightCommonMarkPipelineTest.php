<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ArticleHighlight;
use App\Service\ArticleBodyHighlightInjector;
use App\Service\HighlightAuthorMetadataProvider;
use App\Util\CommonMark\Converter;
use League\CommonMark\Exception\CommonMarkException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the same two steps as {@see \App\Controller\ArticleController::renderArticle}:
 * {@see Converter::convertToHTML} then {@see ArticleBodyHighlightInjector::inject}. Unit tests
 * that only hand-build HTML can pass while production (real CommonMark output) still produces
 * no &lt;mark&gt; if the pipeline or matching rules diverge; these tests assert the literal
 * &lt;mark class="user-highlight__marker"&gt; in the final string.
 */
final class ArticleHighlightCommonMarkPipelineTest extends KernelTestCase
{
    private const AUTHOR_HEX = 'd475ce4b3977507130f42c7f8634fef936800f3ae74d5ecf8089280cdc1923e9';

    private function getConverter(): Converter
    {
        $container = static::getContainer();
        if (!$container->has(Converter::class)) {
            self::fail('Converter service must be registered in the test kernel.');
        }
        /** @var Converter $c */
        $c = $container->get(Converter::class);

        return $c;
    }

    /**
     * @throws CommonMarkException
     */
    public function testCommonMarkParagraphYieldsMarkTagsInFinalHtml(): void
    {
        self::bootKernel();
        $markdown = "Here is a simple paragraph for the test.\n";
        $html = $this->getConverter()->convertToHTML($markdown);
        $eid = '0000000000000000000000000000000000000000000000000000000000000cc1';
        $highlights = [
            $this->makeHighlight($eid, 'Here is a simple paragraph for the test.', [], 1),
        ];
        $out = $this->createInjector()->inject($html, $highlights)['html'];

        $this->assertStringContainsString('<mark', $out, 'Injected body must include a <mark> element.');
        $this->assertStringContainsString('user-highlight__marker', $out);
        $this->assertMarkWithFragmentId($out, $eid);
    }

    /**
     * @throws CommonMarkException
     */
    public function testCommonMarkWithFootnoteRendersToHtmlThatStillInjectsMarkTags(): void
    {
        self::bootKernel();
        $markdown = 'keeping track of things in the informational realm[^a] always implies keeping track of time'."\n\n"
            ."[^a]: A footnote for test.\n";
        $html = $this->getConverter()->convertToHTML($markdown);
        $this->assertStringContainsString('fnref', $html, 'Sanity: League footnote extension should emit a fnref <sup>.');

        $content = 'keeping track of things in the infor'."\xC2\xAD".'ma'."\xC2\xAD".'tional realm always implies keeping track of time';
        $eid = 'f56a6221e8575b051cd6df34e9b61654e08a241b4c5ced3b48c0b769b24ada7d';
        $highlights = [
            $this->makeHighlight(
                $eid,
                $content,
                [['a', '30023:6e468422dfb74a5738702a8823b9b28168abab8655faacb6853cd0ee15deee93:bitcoin-is-time']],
                1762697677
            ),
        ];
        $out = $this->createInjector()->inject($html, $highlights)['html'];

        $this->assertStringContainsString('<mark', $out);
        $this->assertStringContainsString('user-highlight__marker', $out);
        $this->assertMarkWithFragmentId($out, $eid);
    }

    /**
     * @throws CommonMarkException
     */
    public function testCommonMarkWithSmartPunctRendersToHtmlThatMatchesAsciiHighlightContentWithMarks(): void
    {
        self::bootKernel();
        $markdown = "Here's the point...\n";
        $html = $this->getConverter()->convertToHTML($markdown);
        $eid = '0000000000000000000000000000000000000000000000000000000000000dd1';
        $highlights = [
            $this->makeHighlight($eid, "Here's the point...", [], 1),
        ];
        $out = $this->createInjector()->inject($html, $highlights)['html'];

        $this->assertNotEquals($html, $out, 'Body should change when a mark is injected.');
        $this->assertStringContainsString('<mark', $out);
        $this->assertMarkWithFragmentId($out, $eid);
    }

    private function createInjector(): ArticleBodyHighlightInjector
    {
        $meta = $this->createMock(HighlightAuthorMetadataProvider::class);
        $meta->method('getMetadata')->willReturn(
            (object) ['display_name' => 'Test', 'name' => 'Test', 'picture' => ''],
        );

        return new ArticleBodyHighlightInjector($meta);
    }

    private function makeHighlight(
        string $eventId64,
        string $content,
        array $tags,
        int $createdAt,
    ): ArticleHighlight {
        $h = new ArticleHighlight();
        $h->setEventId($eventId64);
        $h->setContent($content);
        $h->setTags($tags);
        $h->setEventCreatedAt($createdAt);
        $h->setAuthorPubkey(self::AUTHOR_HEX);

        return $h;
    }

    private function assertMarkWithFragmentId(string $html, string $eid): void
    {
        $eid = strtolower($eid);
        if (1 === preg_match(
            '/<mark\\b[^>]*\\bclass="user-highlight__marker"[^>]*\\bid="highlight-'.preg_quote($eid, '/').'"/',
            $html
        )) {
            return;
        }
        if (1 === preg_match(
            '/<mark\\b[^>]*\\bid="highlight-'.preg_quote($eid, '/').'"[^>]*\\bclass="user-highlight__marker"/',
            $html
        )) {
            return;
        }
        $this->fail('Expected <mark> with class user-highlight__marker and id highlight-'.$eid.' in: '.$this->excerpt($html));
    }

    private function excerpt(string $html, int $max = 2000): string
    {
        if (\strlen($html) <= $max) {
            return $html;
        }

        return \substr($html, 0, $max).'…';
    }
}
