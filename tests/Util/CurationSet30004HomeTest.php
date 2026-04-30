<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\CurationSet30004Home;
use PHPUnit\Framework\TestCase;

final class CurationSet30004HomeTest extends TestCase
{
    public function testParsesTitleAndOrdered30023RefsOnly(): void
    {
        $eid = 'd78ba0d5dce22bfff9db0a9e996c9ef27e2c91051de0c4e1da340e0326b4941e';
        $tags = [
            ['d', 'jvdy9i4'],
            ['title', 'Yaks'],
            ['image', 'https://example.com/y.jpg'],
            ['a', '30023:26dc95542e18b8b7aec2f14610f55c335abebec76f3db9e58c254661d0593a0c:slug-one'],
            ['e', $eid],
            ['a', '30024:26dc95542e18b8b7aec2f14610f55c335abebec76f3db9e58c254661d0593a0c:draft-only'],
            ['a', '30023:26dc95542e18b8b7aec2f14610f55c335abebec76f3db9e58c254661d0593a0c:slug-two'],
        ];
        $out = CurationSet30004Home::parseTitleAndOrderedRefs($tags);
        self::assertSame('Yaks', $out['title']);
        self::assertSame([
            ['type' => 'article', 'pk' => '26dc95542e18b8b7aec2f14610f55c335abebec76f3db9e58c254661d0593a0c', 'slug' => 'slug-one'],
            ['type' => 'article', 'pk' => '26dc95542e18b8b7aec2f14610f55c335abebec76f3db9e58c254661d0593a0c', 'slug' => 'slug-two'],
        ], $out['items']);
    }

    public function testSkipsNon30023ATags(): void
    {
        $tags = [
            ['a', '30040:26dc95542e18b8b7aec2f14610f55c335abebec76f3db9e58c254661d0593a0c:mag'],
            ['a', '30023:26dc95542e18b8b7aec2f14610f55c335abebec76f3db9e58c254661d0593a0c:ok'],
        ];
        $out = CurationSet30004Home::parseTitleAndOrderedRefs($tags);
        self::assertSame('', $out['title']);
        self::assertCount(1, $out['items']);
        self::assertSame('article', $out['items'][0]['type']);
        self::assertSame('ok', $out['items'][0]['slug']);
    }
}
