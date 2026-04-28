<?php

declare(strict_types=1);

namespace App\Tests\Nostr;

use App\Nostr\Nip10Kind1ArticleReplyTags;
use PHPUnit\Framework\TestCase;

final class Nip10Kind1ArticleReplyTagsTest extends TestCase
{
    public function testReplyUnderArticleUsesMarkedEWithPubkeys(): void
    {
        $pk = str_repeat('a', 64);
        $aid = str_repeat('b', 64);
        $cid = str_repeat('c', 64);
        $coord = '30023:'.$pk.':slug';
        $tags = Nip10Kind1ArticleReplyTags::forReplyToKind1(
            $cid,
            $pk,
            [['a', $coord]],
            $coord,
            $aid
        );
        $this->assertSame(
            [
                ['e', $aid, '', 'root', $pk],
                ['e', $cid, '', 'reply', $pk],
                ['p', $pk],
            ],
            $tags
        );
    }

    public function testDirectReplyToRootUsesSingleMarkedRootOnly(): void
    {
        $author = str_repeat('f', 64);
        $rootId = str_repeat('e', 64);
        $coord = '30023:'.$author.':slug';
        $tags = Nip10Kind1ArticleReplyTags::forReplyToKind1(
            $rootId,
            $author,
            [
                ['a', $coord],
                ['e', $rootId, '', 'root', $author],
            ],
            $coord,
            $rootId
        );
        $this->assertCount(2, $tags);
        $this->assertSame(['e', $rootId, '', 'root', $author], $tags[0]);
        $this->assertSame(['p', $author], $tags[1]);
    }

    public function testNestedCopiesParentPTagsAfterAuthor(): void
    {
        $articlePk = str_repeat('1', 64);
        $root = str_repeat('2', 64);
        $parentNote = str_repeat('3', 64);
        $child = str_repeat('4', 64);
        $pExtra = str_repeat('6', 64);
        $coord = '30023:'.$articlePk.':x';
        $parentTags = [
            ['a', $coord],
            ['e', $root, '', 'root', str_repeat('5', 64)],
            ['e', $parentNote, '', 'reply', $articlePk],
            ['p', $articlePk],
            ['p', $pExtra],
        ];
        $tags = Nip10Kind1ArticleReplyTags::forReplyToKind1(
            $child,
            $articlePk,
            $parentTags,
            $coord,
            $root
        );
        $this->assertSame($root, $tags[0][1]);
        $this->assertSame($articlePk, $tags[0][4]);
        $this->assertSame($child, $tags[1][1]);
        $this->assertSame($articlePk, $tags[1][4]);
        $this->assertSame(['p', $articlePk], $tags[2]);
        $this->assertSame(['p', $pExtra], $tags[3]);
        $this->assertCount(4, $tags);
    }

    public function testWhenRootInferenceFailsOnlyDirectParentE(): void
    {
        $author = str_repeat('9', 64);
        $parentId = str_repeat('8', 64);
        $coord = '30023:'.$author.':x';
        $tags = Nip10Kind1ArticleReplyTags::forReplyToKind1(
            $parentId,
            $author,
            [],
            $coord,
            null
        );
        $this->assertSame(
            [
                ['e', $parentId, '', 'reply', $author],
                ['p', $author],
            ],
            $tags
        );
    }
}
