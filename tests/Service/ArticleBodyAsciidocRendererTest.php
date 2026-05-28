<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArticleBodyAsciidocRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

final class ArticleBodyAsciidocRendererTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/unfold-asciidoc-test-'.uniqid('', true);
        (new Filesystem())->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    public function testFallbackPreservesBlankLineParagraphBreaks(): void
    {
        $renderer = new ArticleBodyAsciidocRenderer($this->tempDir, new NullLogger());
        $html = $renderer->convertToHtml("First paragraph.\n\nSecond paragraph.");

        self::assertSame('<p>First paragraph.</p><p>Second paragraph.</p>', $html);
    }

    public function testFallbackPreservesSingleLineBreaksInsideParagraph(): void
    {
        $renderer = new ArticleBodyAsciidocRenderer($this->tempDir, new NullLogger());
        $html = $renderer->convertToHtml("Line one.\nLine two.");

        self::assertStringContainsString('<p>Line one.<br', $html);
        self::assertStringContainsString('Line two.</p>', $html);
    }

    public function testNodeRendererSplitsParagraphsWhenAvailable(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not installed');
        }

        $projectDir = \dirname(__DIR__, 2);
        $script = $projectDir.'/bin/render-asciidoc.mjs';
        if (!is_readable($script)) {
            self::markTestSkipped('render-asciidoc.mjs is missing');
        }

        $renderer = new ArticleBodyAsciidocRenderer($projectDir, new NullLogger());
        $html = $renderer->convertToHtml("First paragraph.\n\nSecond paragraph.");

        self::assertStringContainsString('<div class="paragraph">', $html);
        self::assertStringContainsString('<p>First paragraph.</p>', $html);
        self::assertStringContainsString('<p>Second paragraph.</p>', $html);
    }
}
