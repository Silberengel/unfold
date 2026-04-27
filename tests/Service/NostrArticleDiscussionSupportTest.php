<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\KindsEnum;
use App\Service\NostrArticleDiscussionSupport;
use PHPUnit\Framework\TestCase;

final class NostrArticleDiscussionSupportTest extends TestCase
{
    private NostrArticleDiscussionSupport $s;

    protected function setUp(): void
    {
        $this->s = new NostrArticleDiscussionSupport();
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
}
