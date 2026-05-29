<?php

declare(strict_types=1);

namespace App\Tests\Util\CommonMark\NostrSchemeExtension;

use App\Nostr\Nip19Codec;
use App\Service\HighlightAuthorMetadataProvider;
use App\Service\NostrKeyHelper;
use App\Service\UserBadgeHtmlRenderer;
use App\Util\CommonMark\NostrSchemeExtension\NostrMentionLink;
use App\Util\CommonMark\NostrSchemeExtension\NostrMentionRenderer;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NostrMentionRendererTest extends TestCase
{
    private const NPUB = 'npub1l5sga6xg72phsz5422ykujprejwud075ggrr3z2hwyrfgr7eylqstegx9z';

    public function testRendersUserBadge(): void
    {
        $metadata = $this->createMock(HighlightAuthorMetadataProvider::class);
        $metadata->method('getMetadata')->willReturn((object) ['display_name' => 'Laeserin']);

        $url = $this->createMock(UrlGeneratorInterface::class);
        $url->method('generate')->willReturn('/p/'.self::NPUB);

        $assets = $this->createMock(Packages::class);
        $assets->method('getUrl')->willReturn('/icons/favicon-96x96.png');

        $renderer = new NostrMentionRenderer(new UserBadgeHtmlRenderer(
            $metadata,
            new NostrKeyHelper(),
            new Nip19Codec(),
            $url,
            $assets,
        ));
        $child = $this->createMock(ChildNodeRendererInterface::class);
        $html = (string) $renderer->render(new NostrMentionLink(null, self::NPUB), $child);

        $this->assertStringContainsString('user-badge', $html);
        $this->assertStringContainsString('Laeserin', $html);
        $this->assertStringContainsString('nostr-user-badge-inline', $html);
    }
}
