<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * HTTP client for the Wikistr asciidoctor sidecar (EPUB/PDF/HTML5 conversion).
 */
final class AsciiDoctorServerClient
{
    private const TIMEOUT_SEC = 120;

    /** @var array<string, array{endpoint: string, mimeType: string, extension: string}> */
    private const FORMATS = [
        'epub3' => ['endpoint' => 'epub', 'mimeType' => 'application/epub+zip', 'extension' => 'epub'],
        'epub' => ['endpoint' => 'epub', 'mimeType' => 'application/epub+zip', 'extension' => 'epub'],
        'pdf' => ['endpoint' => 'pdf', 'mimeType' => 'application/pdf', 'extension' => 'pdf'],
        'html5' => ['endpoint' => 'html5', 'mimeType' => 'text/html; charset=utf-8', 'extension' => 'html'],
        'html' => ['endpoint' => 'html5', 'mimeType' => 'text/html; charset=utf-8', 'extension' => 'html'],
    ];

    public function __construct(
        private readonly string $serverUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->serverUrl) !== '';
    }

    /**
     * @return list<string>
     */
    public function supportedFormats(): array
    {
        return ['epub3', 'pdf', 'html5'];
    }

    public function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if ($format === '') {
            return 'epub3';
        }
        if (!isset(self::FORMATS[$format])) {
            throw new \InvalidArgumentException(sprintf('Unsupported export format: %s', $format));
        }

        return $format;
    }

    /**
     * @return array{body: string, mimeType: string, extension: string}
     */
    public function convert(string $format, string $content, string $title, string $author, ?string $image = null): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AsciiDoctor server URL is not configured.');
        }

        $format = $this->normalizeFormat($format);
        $info = self::FORMATS[$format];
        $url = rtrim($this->serverUrl, '/').'/convert/'.$info['endpoint'];

        $payload = [
            'content' => $content,
            'title' => $title !== '' ? $title : 'Publication',
            'author' => $author,
        ];
        if ($image !== null && trim($image) !== '') {
            $payload['image'] = trim($image);
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: */*\r\n",
                'content' => $json,
                'timeout' => self::TIMEOUT_SEC,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $err = error_get_last();

            throw new \RuntimeException(sprintf(
                'AsciiDoctor request failed: %s',
                $err['message'] ?? 'unknown error',
            ));
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'AsciiDoctor server returned HTTP %d%s',
                $status,
                $body !== '' ? ': '.substr($body, 0, 400) : '',
            ));
        }

        if ($body === '') {
            throw new \RuntimeException('AsciiDoctor server returned an empty file.');
        }

        $this->logger->info('asciidoctor.convert', [
            'format' => $format,
            'title' => $title,
            'bytes' => \strlen($body),
        ]);

        return [
            'body' => $body,
            'mimeType' => $info['mimeType'],
            'extension' => $info['extension'],
        ];
    }
}
