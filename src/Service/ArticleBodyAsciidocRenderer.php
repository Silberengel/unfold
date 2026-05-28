<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Asciidoctor.js (Node) for kind 30041 / 30818 article bodies.
 */
final class ArticleBodyAsciidocRenderer
{
    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function convertToHtml(string $asciidoc): string
    {
        $script = $this->projectDir.'/bin/render-asciidoc.mjs';
        if (!is_readable($script)) {
            $this->logger->warning('asciidoc.render: script missing', ['path' => $script]);

            return htmlspecialchars($asciidoc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $process = new Process(['node', $script], $this->projectDir, null, $asciidoc, 120);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->logger->warning('asciidoc.render: node failed', [
                'error' => $process->getErrorOutput(),
            ]);

            return htmlspecialchars($asciidoc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $process->getOutput();
    }
}
