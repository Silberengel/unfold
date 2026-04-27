<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ArticleHighlight;
use App\Service\ArticleBodyHighlightInjector;
use App\Service\HighlightAuthorMetadataProvider;
use App\Service\NostrKeyHelper;
use PHPUnit\Framework\TestCase;

/**
 * In-article marks use the same matching rules as production ({@see \App\Controller\ArticleController::renderArticle}:
 * CommonMark HTML → {@see \App\Service\ArticleBodyHighlightInjector::inject}).
 *
 * Cards on the home page use NIP-84 strings as stored; the body is rendered HTML, so tests must
 * include real DOM differences (SmartPunct curly quotes / ellipsis vs ASCII in 9802 `content`).
 */
final class ArticleBodyHighlightInjectorTest extends TestCase
{
    /** Valid 64-hex secp256k1 pubkey (see NostrAuthenticatorTest signing key). */
    private const AUTHOR_HEX = 'd475ce4b3977507130f42c7f8634fef936800f3ae74d5ecf8089280cdc1923e9';

    public function testEachDistinctPassageRendersWithHighlightFragmentId(): void
    {
        $html = '<p>First passage alpha.</p><p>Second passage beta.</p><p>Third passage gamma.</p>';
        $e1 = '00000000000000000000000000000000000000000000000000000000000000a1';
        $e2 = '00000000000000000000000000000000000000000000000000000000000000a2';
        $e3 = '00000000000000000000000000000000000000000000000000000000000000a3';
        $highlights = [
            $this->makeHighlight($e1, 'First passage alpha.', [], 100),
            $this->makeHighlight($e2, 'Second passage beta.', [], 200),
            $this->makeHighlight($e3, 'Third passage gamma.', [], 300),
        ];
        $injector = $this->createInjector();
        $out = $injector->inject($html, $highlights);

        $this->assertCount(3, $out['injectedEventIds'], 'Each highlight with a unique matching passage should inject.');
        $this->assertHighlightFragmentsPresent($out['html'], [$e1, $e2, $e3]);
    }

    public function testSamePassageFromTwoEventsYieldsFragmentIdForEachEventId(): void
    {
        $html = '<p>Shared quote text for two readers.</p>';
        $older = '00000000000000000000000000000000000000000000000000000000000000b1';
        $newer = '00000000000000000000000000000000000000000000000000000000000000b2';
        $highlights = [
            $this->makeHighlight($older, 'Shared quote text for two readers.', [], 10),
            $this->makeHighlight($newer, 'Shared quote text for two readers.', [], 20),
        ];
        $out = $this->createInjector()->inject($html, $highlights);

        $this->assertCount(2, $out['injectedEventIds']);
        $this->assertHighlightFragmentsPresent($out['html'], [$older, $newer]);
    }

    public function testContextTagUsedWhenContentIsSubspanOfContext(): void
    {
        $html = '<p>Before the important bit the rest of the sentence.</p>';
        $eid = '00000000000000000000000000000000000000000000000000000000000000c1';
        $context = 'Before the important bit the rest of the sentence.';
        $content = 'important bit';
        $highlights = [
            $this->makeHighlight(
                $eid,
                $content,
                [['context', $context]],
                100
            ),
        ];
        $out = $this->createInjector()->inject($html, $highlights);

        $this->assertContains($eid, $out['injectedEventIds'], 'NIP-84: highlight `content` as subspan of `context` should still match the body.');
        $this->assertHighlightFragmentsPresent($out['html'], [$eid]);
    }

    public function testCurlyApostropheAndEllipsisInBodyMatchAsciiNeedleFromEvent(): void
    {
        // Landing “highlight” cards use NIP-84 text as stored; the article body is rendered by
        // CommonMark + SmartPunct, which uses U+2019 (’) and U+2026 (…) in the DOM while clients
        // often send straight ASCII in 9802 `content`. stringForSearch must fold typography.
        $apostrophe = "\xE2\x80\x99";
        $ellipsis = "\xE2\x80\xA6";
        // "Here" + ’ + "s" (SmartPunct), not "it" + ’ + "s"
        $html = '<p>Here'.$apostrophe.'s the point'.$ellipsis.'</p>';
        $eid = '00000000000000000000000000000000000000000000000000000000000000d1';
        $highlights = [
            $this->makeHighlight($eid, "Here's the point...", [], 1),
        ];
        $out = $this->createInjector()->inject($html, $highlights);

        $this->assertContains($eid, $out['injectedEventIds']);
        $this->assertHighlightFragmentsPresent($out['html'], [$eid]);
    }

    public function testBorisBitcoinIsTimeHighlightWithSoftHyphensInjectsDespiteFootnoteCalloutInBody(): void
    {
        // Real kind-9802: `content` uses U+00AD in "infor­ma­tion­al" (read.withboris / Gigi’s article).
        // The article body is plain "informational"; footnotes break substring search if we keep <sup> text.
        $content = 'keeping track of things in the infor'."\xC2\xAD".'ma'."\xC2\xAD".'tional realm always implies keeping track of time';
        $html = '<p>keeping track of things in the informational realm'
            .'<sup id="fnref:a"><a class="footnote-ref" href="#fn:a" role="doc-noteref">1</a></sup>'
            .' always implies keeping track of time</p>';
        $eid = 'f56a6221e8575b051cd6df34e9b61654e08a241b4c5ced3b48c0b769b24ada7d';
        $highlights = [
            $this->makeHighlight(
                $eid,
                $content,
                [['a', '30023:6e468422dfb74a5738702a8823b9b28168abab8655faacb6853cd0ee15deee93:bitcoin-is-time']],
                1762697677
            ),
        ];
        $out = $this->createInjector()->inject($html, $highlights);

        $this->assertContains($eid, $out['injectedEventIds']);
        $this->assertHighlightFragmentsPresent($out['html'], [$eid]);
    }

    public function testSoftHyphenInBodyMatchesPlainNeedleFromEvent(): void
    {
        $softHyphen = "\xC2\xAD";
        $html = '<p>Time and order have a very intimate relation'.$softHyphen.'ship.</p>';
        $eid = '00000000000000000000000000000000000000000000000000000000000000e1';
        $highlights = [
            $this->makeHighlight($eid, 'Time and order have a very intimate relationship.', [], 1),
        ];
        $out = $this->createInjector()->inject($html, $highlights);

        $this->assertContains($eid, $out['injectedEventIds']);
        $this->assertHighlightFragmentsPresent($out['html'], [$eid]);
    }

    private function createInjector(): ArticleBodyHighlightInjector
    {
        $meta = $this->createMock(HighlightAuthorMetadataProvider::class);
        $meta->method('getMetadata')->willReturn(
            (object) [
                'display_name' => 'Test',
                'name' => 'Test',
                'picture' => '',
            ]
        );

        return new ArticleBodyHighlightInjector($meta, new NostrKeyHelper());
    }

    /**
     * @param list<string> $eventIdsLowerOrMixed 64-char hex event ids
     */
    private function assertHighlightFragmentsPresent(string $html, array $eventIds): void
    {
        $this->assertStringContainsString(
            '<mark',
            $html,
            'In-article highlights must include at least one <mark> (see ArticleBodyHighlightInjector).'
        );
        $this->assertStringContainsString('user-highlight__marker', $html);
        foreach ($eventIds as $eid) {
            $eid = strtolower($eid);
            $this->assertMatchesRegularExpression(
                '/\bid="highlight-'.preg_quote($eid, '/').'"/',
                $html,
                'Each event id must have a #highlight-'.$eid.' anchor (on the <mark> or a zero-width fragment <span>).'
            );
        }
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
}
