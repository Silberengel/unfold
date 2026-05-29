<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OpenGraphPreviewChecker;
use PHPUnit\Framework\TestCase;

final class OpenGraphPreviewCheckerTest extends TestCase
{
    private OpenGraphPreviewChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new OpenGraphPreviewChecker();
    }

    public function testRejectsUrlEchoTitleOnly(): void
    {
        $url = 'https://next-alexandria.gitcitadel.eu/';

        self::assertFalse($this->checker->hasMeaningfulMetadata($url, null, null, $url));
    }

    public function testAcceptsImageOrDescriptionOrRealTitle(): void
    {
        self::assertTrue($this->checker->hasMeaningfulMetadata(null, null, 'https://cdn/img.jpg', 'https://example.com'));
        self::assertTrue($this->checker->hasMeaningfulMetadata(null, 'A description', null, 'https://example.com'));
        self::assertTrue($this->checker->hasMeaningfulMetadata('Alexandria', null, null, 'https://example.com'));
    }

    public function testRejectsHostnameOnlyTitle(): void
    {
        self::assertFalse($this->checker->hasMeaningfulMetadata('example.com', null, null, 'https://example.com/page'));
    }
}
