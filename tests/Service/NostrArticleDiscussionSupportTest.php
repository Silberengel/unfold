<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\KindsEnum;
use App\Nostr\Nip19Codec;
use App\Service\NostrArticleDiscussionSupport;
use PHPUnit\Framework\TestCase;

final class NostrArticleDiscussionSupportTest extends TestCase
{
    private NostrArticleDiscussionSupport $s;

    private Nip19Codec $nip19;

    protected function setUp(): void
    {
        $this->nip19 = new Nip19Codec();
        $this->s = new NostrArticleDiscussionSupport($this->nip19);
    }

    public function testCreateArticleDiscussionFilterCountWithoutRoot(): void
    {
        $c = '30023:'.str_repeat('b', 64).':my-slug';
        $filters = $this->s->createArticleDiscussionFilters($c, null);
        $this->assertCount(6, $filters);
    }

    public function testCreateArticleDiscussionFilterCountWithRoot(): void
    {
        $c = '30023:'.str_repeat('b', 64).':my-slug';
        $root = str_repeat('c', 64);
        $filters = $this->s->createArticleDiscussionFilters($c, $root);
        $this->assertCount(7, $filters);
    }

    public function testNip22ThreadReplyByAtag(): void
    {
        $coord = '30023:'.str_repeat('d', 64).':x';
        $e = (object) [
            'kind' => KindsEnum::COMMENTS->value,
            'tags' => [['A', $coord]],
        ];
        $this->assertTrue($this->s->eventIsNip22ArticleThreadReply($e, $coord));
    }

    public function testLegacyThreadByAtag(): void
    {
        $coord = '30023:'.str_repeat('d', 64).':x';
        $e = (object) [
            'kind' => KindsEnum::TEXT_NOTE->value,
            'tags' => [['a', $coord]],
        ];
        $this->assertTrue($this->s->eventIsLegacyThreadReply($e, $coord, null));
    }

    public function testLegacyThreadByEtagWhenRootGiven(): void
    {
        $coord = '30023:'.str_repeat('d', 64).':x';
        $root = str_repeat('e', 64);
        $e = (object) [
            'kind' => KindsEnum::TEXT_NOTE->value,
            'tags' => [['e', $root]],
        ];
        $this->assertTrue($this->s->eventIsLegacyThreadReply($e, $coord, $root));
    }

    public function testArticleQuoteByQtag(): void
    {
        $coord = '30023:'.str_repeat('d', 64).':x';
        $e = (object) [
            'kind' => KindsEnum::TEXT_NOTE->value,
            'tags' => [['q', $coord]],
        ];
        $this->assertTrue($this->s->eventIsArticleQuote($e, $coord, null));
    }

    public function testHighlightsAreNotQuotes(): void
    {
        $coord = '30023:'.str_repeat('d', 64).':x';
        $e = (object) [
            'kind' => KindsEnum::HIGHLIGHTS->value,
            'tags' => [['q', $coord]],
        ];
        $this->assertFalse($this->s->eventIsArticleQuote($e, $coord, null));
    }

    public function testKind1WithNaddrInContentIsQuoteNotThread(): void
    {
        $pk = str_repeat('a', 64);
        $coord = '30023:'.$pk.':my-article';
        $naddr = $this->nip19->encodeNaddr(30023, $pk, 'my-article');
        $e = (object) [
            'kind' => KindsEnum::TEXT_NOTE->value,
            'content' => "Check this\n\nnostr:".$naddr."\n",
            'tags' => [
                ['a', $coord, '', 'mention'],
            ],
        ];
        $this->assertTrue($this->s->eventIsArticleQuote($e, $coord, null));
        $this->assertFalse($this->s->eventIsLegacyThreadReply($e, $coord, null));
    }

    public function testKind1WithNaddrInContentButWithEtagStaysInThread(): void
    {
        $pk = str_repeat('a', 64);
        $coord = '30023:'.$pk.':my-article';
        $root = str_repeat('b', 64);
        $naddr = $this->nip19->encodeNaddr(30023, $pk, 'my-article');
        $e = (object) [
            'kind' => KindsEnum::TEXT_NOTE->value,
            'content' => "Reply\n\nnostr:".$naddr,
            'tags' => [
                ['a', $coord],
                ['e', $root, '', 'reply', $pk],
            ],
        ];
        $this->assertFalse($this->s->eventIsArticleQuote($e, $coord, null));
        $this->assertTrue($this->s->eventIsLegacyThreadReply($e, $coord, $root));
    }
}
