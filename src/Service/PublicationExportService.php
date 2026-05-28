<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;

/**
 * EPUB/PDF/HTML export via the asciidoctor sidecar (server-side; e-reader friendly downloads).
 */
final class PublicationExportService
{
    public function __construct(
        private readonly PublicationReaderService $reader,
        private readonly PublicationAsciidocAssembler $assembler,
        private readonly AsciiDoctorServerClient $asciiDoctor,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->asciiDoctor->isConfigured();
    }

    /**
     * @return list<string>
     */
    public function supportedFormats(): array
    {
        return $this->asciiDoctor->supportedFormats();
    }

    /**
     * @return array{body: string, mimeType: string, filename: string}
     */
    public function export(string $npub, string $slug, string $format): array
    {
        $index = $this->reader->resolveRootIndex($npub, $slug);
        if ($index === null) {
            throw new PublicationExportException('Publication not found.');
        }

        $this->reader->ensurePublicationTreeWarmForExport($index);

        $assembled = $this->assembler->assemble($index);
        if (trim($assembled['content']) === '') {
            throw new PublicationExportException('Publication has no exportable content.');
        }

        $converted = $this->asciiDoctor->convert(
            $format,
            $assembled['content'],
            $assembled['title'],
            $assembled['author'],
            $assembled['image'] !== '' ? $assembled['image'] : null,
        );

        return [
            'body' => $converted['body'],
            'mimeType' => $converted['mimeType'],
            'filename' => $this->safeFilename($assembled['title'], $converted['extension']),
        ];
    }

    private function safeFilename(string $title, string $extension): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $title) ?? 'publication';
        $safe = trim($safe, '._-');
        if ($safe === '') {
            $safe = 'publication';
        }

        return substr($safe, 0, 80).'.'.$extension;
    }
}
