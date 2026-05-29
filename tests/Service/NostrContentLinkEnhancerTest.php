<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Nostr\Nip19Codec;
use App\Service\HighlightAuthorMetadataProvider;
use App\Service\NostrContentLinkEnhancer;
use App\Service\NostrLinkParser;
use App\Service\NostrPreviewPlaceholderRenderer;
use App\Service\NostrKeyHelper;
use App\Service\UserBadgeHtmlRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NostrContentLinkEnhancerTest extends TestCase
{
    public function testEnhancePlainTextRendersNpubBadge(): void
    {
        $npub = 'npub1l5sga6xg72phsz5422ykujprejwud075ggrr3z2hwyrfgr7eylqstegx9z';
        $metadata = $this->createMock(HighlightAuthorMetadataProvider::class);
        $metadata->method('getMetadata')->willReturn((object) ['display_name' => 'Alice']);

        $url = $this->createMock(UrlGeneratorInterface::class);
        $url->method('generate')->willReturn('/p/'.$npub);

        $assets = $this->createMock(Packages::class);
        $assets->method('getUrl')->willReturn('/icons/favicon-96x96.png');

        $enhancer = new NostrContentLinkEnhancer(
            new NostrLinkParser(new NullLogger(), new Nip19Codec()),
            new UserBadgeHtmlRenderer($metadata, new NostrKeyHelper(), new Nip19Codec(), $url, $assets),
            new NostrPreviewPlaceholderRenderer(new Nip19Codec()),
        );

        $text = 'Follow nostr:'.$npub.' for updates.';
        $out = $enhancer->enhancePlainText($text);

        self::assertStringContainsString('user-badge', $out);
        self::assertStringContainsString('Alice', $out);
        self::assertStringNotContainsString('nostr:'.$npub, $out);
    }
}
