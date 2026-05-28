<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Util\NostrEventTags;

/**
 * Builds a single AsciiDoc book document from a kind-30040 publication tree.
 */
final class PublicationAsciidocAssembler
{
    private const MAX_NEST_DEPTH = 8;

    public function __construct(
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly PublicationRelayResolver $relayResolver,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    /**
     * @return array{content: string, title: string, author: string, image: string}
     */
    public function assemble(Event $rootIndex): array
    {
        $title = $this->titleFromIndex($rootIndex);
        $author = $this->authorFromIndex($rootIndex);
        $image = $this->imageFromIndex($rootIndex);
        $version = $this->tagValue($rootIndex, 'version') ?? $this->tagValue($rootIndex, 'V') ?? 'first edition';

        $doc = '= '.$this->escapeInline($title)."\n";
        if ($author !== '') {
            $doc .= $this->escapeInline($author)."\n";
        }
        $doc .= ":doctype: book\n";
        $doc .= ":toc:\n";
        $doc .= ":toclevels: 2\n";
        $doc .= ":stem:\n";
        $doc .= ":page-break-mode: auto\n";
        $doc .= ":sectnums!:\n";
        $doc .= ":imagesdir:\n";
        $doc .= ":image-width: 1000px\n";
        $doc .= ":max-width: 1000px\n";
        if ($author !== '') {
            $doc .= ':author: '.$this->escapeInline($author)."\n";
        }
        $doc .= ':version: '.$this->escapeInline($version)."\n";
        $doc .= ':revnumber: '.$this->escapeInline($version)."\n";

        if ($image !== '') {
            $doc .= ':front-cover-image: '.$image."\n";
            $doc .= ':epub-cover-image: '.$image."\n";
            $doc .= ':ebook-cover-image: '.$image."\n";
        }

        $doc .= "\n\n";
        $this->appendIndexBody($rootIndex, 2, $doc);

        return [
            'content' => $doc,
            'title' => $title,
            'author' => $author,
            'image' => $image,
        ];
    }

    private function appendIndexBody(Event $index, int $headingLevel, string &$doc): void
    {
        if ($headingLevel > self::MAX_NEST_DEPTH + 1) {
            return;
        }

        foreach ($index->getTags() as $tag) {
            if (!NostrEventTags::tagNameMatches($tag, 'a')) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($tag);
            if ($seq === null || !isset($seq[1])) {
                continue;
            }
            $coord = trim((string) $seq[1]);
            $parts = explode(':', $coord, 3);
            if (\count($parts) < 3) {
                continue;
            }
            $kind = (int) $parts[0];

            if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
                $pkHex = strtolower($parts[1]);
                $nestedD = trim($parts[2]);
                $child = $this->publicationIndexStore->getByPubkeyHexAndD($pkHex, $nestedD);
                if ($child === null) {
                    $child = $this->relayResolver->resolvePublicationIndex(
                        $this->nostrKeyHelper->convertPublicKeyToBech32($pkHex),
                        $nestedD,
                    );
                }
                if ($child === null) {
                    continue;
                }

                $sectionTitle = $this->titleFromIndex($child);
                if ($sectionTitle !== '') {
                    $doc .= $this->heading($headingLevel, $sectionTitle);
                }
                $indexContent = trim($child->getContent());
                if ($indexContent !== '') {
                    $doc .= $indexContent."\n\n";
                }
                $this->appendIndexBody($child, $headingLevel + 1, $doc);
            } elseif (\in_array($kind, KindsEnum::publicationSectionKindValues(), true)) {
                $article = $this->relayResolver->resolveArticleByCoordinate($coord);
                if (!$article instanceof Article) {
                    continue;
                }

                $sectionTitle = trim((string) ($article->getTitle() ?? ''));
                if ($sectionTitle === '') {
                    $sectionTitle = trim((string) ($parts[2] ?? ''));
                }
                if ($sectionTitle !== '') {
                    $doc .= $this->heading($headingLevel, $sectionTitle);
                }

                $body = trim((string) ($article->getContent() ?? ''));
                if ($body !== '') {
                    $doc .= $body."\n\n";
                }
            }
        }
    }

    private function heading(int $level, string $title): string
    {
        $level = max(2, min(6, $level));

        return str_repeat('=', $level).' '.$this->escapeInline($title)."\n\n";
    }

    private function titleFromIndex(Event $index): string
    {
        $title = $this->tagValue($index, 'title');
        if ($title !== null && $title !== '') {
            return $title;
        }
        $slug = $index->getSlug();

        return $slug !== null && $slug !== '' ? $slug : 'Publication';
    }

    private function authorFromIndex(Event $index): string
    {
        $author = $this->tagValue($index, 'author');
        if ($author !== null && $author !== '') {
            return $author;
        }

        return $this->nostrKeyHelper->convertPublicKeyToBech32($index->getPubkey());
    }

    private function imageFromIndex(Event $index): string
    {
        return $this->tagValue($index, 'image') ?? '';
    }

    private function tagValue(Event $index, string $name): ?string
    {
        foreach ($index->getTags() as $tag) {
            if (!NostrEventTags::tagNameMatches($tag, $name)) {
                continue;
            }
            $seq = NostrEventTags::rowToStringList($tag);
            if ($seq === null) {
                continue;
            }

            return trim((string) ($seq[1] ?? ''));
        }

        return null;
    }

    private function escapeInline(string $value): string
    {
        return str_replace("\n", ' ', $value);
    }
}
