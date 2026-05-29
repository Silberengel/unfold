<?php

declare(strict_types=1);

namespace App\Tests\Util\CommonMark\NostrSchemeExtension;

use App\Service\HighlightAuthorMetadataProvider;
use App\Util\CommonMark\NostrSchemeExtension\NostrMentionLink;
use App\Util\CommonMark\NostrSchemeExtension\NostrMentionRenderer;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use PHPUnit\Framework\TestCase;

final class NostrMentionRendererTest extends TestCase
{
    private const NPUB = 'npub1l5sga6xg72phsz5422ykujprejwud075ggrr3z2hwyrfgr7eylqstegx9z';

    public function testRendersDisplayNameFromCache(): void
    {
        $cache = $this->createMock(HighlightAuthorMetadataProvider::class);
        $cache->expects(self::once())
            ->method('getMetadata')
            ->with(self::NPUB)
            ->willReturn((object) ['display_name' => 'Laeserin', 'name' => 'laeserin']);

        $renderer = new NostrMentionRenderer($cache);
        $child = $this->createMock(ChildNodeRendererInterface::class);
        $html = (string) $renderer->render(new NostrMentionLink(null, self::NPUB), $child);

        $this->assertStringContainsString('>@Laeserin<', $html);
        $this->assertStringContainsString('/p/'.self::NPUB, $html);
    }
}
