<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Nip30EmojiCatalogBuilder;
use PHPUnit\Framework\TestCase;

final class Nip30EmojiCatalogBuilderTest extends TestCase
{
    private Nip30EmojiCatalogBuilder $b;

    protected function setUp(): void
    {
        $this->b = new Nip30EmojiCatalogBuilder();
    }

    public function testCatalogFromTagsParsesValidEmojiRow(): void
    {
        $tags = [
            ['emoji', 'soapbox', 'https://gleasonator.com/emoji/Gleasonator/soapbox.png'],
        ];
        $out = $this->b->catalogFromTagsOnly($tags);
        $this->assertCount(1, $out);
        $this->assertSame('soapbox', $out[0]['shortcode']);
        $this->assertSame('https://gleasonator.com/emoji/Gleasonator/soapbox.png', $out[0]['url']);
        $this->assertArrayNotHasKey('set', $out[0]);
    }

    public function testOptionalFourthSetCoordinate(): void
    {
        $pk = str_repeat('b', 64);
        $tags = [
            ['emoji', 'blob', 'https://example.com/x.png', '30030:'.$pk.':blobcats'],
        ];
        $out = $this->b->catalogFromTagsOnly($tags);
        $this->assertSame('30030:'.$pk.':blobcats', $out[0]['set'] ?? null);
    }

    public function testInvalidShortcodeSkipped(): void
    {
        $tags = [
            ['emoji', 'bad shortcode', 'https://example.com/a.png'],
        ];
        $this->assertSame([], $this->b->catalogFromTagsOnly($tags));
    }

    public function testMergedCatalogLaterSourceOverridesShortcode(): void
    {
        $pk = str_repeat('c', 64);
        $k0 = (object) [
            'kind' => 0,
            'tags' => [
                ['emoji', 'x', 'https://example.com/old.png'],
            ],
        ];
        $list = (object) [
            'kind' => 10030,
            'tags' => [
                ['emoji', 'x', 'https://example.com/new.png'],
            ],
        ];
        $merged = $this->b->buildMergedCatalog($k0, $list, []);
        $this->assertCount(1, $merged);
        $this->assertSame('https://example.com/new.png', $merged[0]['url']);
    }

    public function testCatalogFromWireIfNip30KindIgnoresUnsupportedKind(): void
    {
        $wire = (object) ['kind' => 30023, 'tags' => [['emoji', 'nope', 'https://example.com/n.png']]];
        $this->assertSame([], $this->b->catalogFromWireIfNip30Kind($wire));
    }

    public function testCatalogFromWireIfNip30KindAcceptsKind7(): void
    {
        $wire = (object) [
            'kind' => 7,
            'tags' => [
                ['emoji', 'dezh', 'https://raw.githubusercontent.com/dezh-tech/brand-assets/main/dezh/logo/black-normal.svg'],
            ],
        ];
        $out = $this->b->catalogFromWireIfNip30Kind($wire);
        $this->assertSame('dezh', $out[0]['shortcode'] ?? '');
    }
}
