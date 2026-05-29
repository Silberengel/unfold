<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Util\NostrEventTags;

/**
 * Builds publication reader TOC and resolves sections (DB + relay).
 */
final class PublicationReaderService
{
    private const MAX_NEST_DEPTH = 8;

    public function __construct(
        private readonly PublicationRelayResolver $relayResolver,
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly PublicationTreeWarmer $publicationTreeWarmer,
        private readonly ArticleBodyHtmlRenderer $articleBodyHtmlRenderer,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function resolveRootIndex(string $npub, string $dTag): ?Event
    {
        return $this->relayResolver->resolvePublicationIndex($npub, $dTag);
    }

    /**
     * Fetches missing nested indices, section articles, and author profiles before TOC render.
     */
    public function ensurePublicationTreeWarm(Event $rootIndex, string $npub, string $slug): void
    {
        $this->publicationTreeWarmer->warmForReader($rootIndex, $npub, $slug);
    }

    public function ensurePublicationTreeWarmForExport(Event $rootIndex): void
    {
        $this->publicationTreeWarmer->warmForExport($rootIndex);
    }

    /**
     * All section coordinates in publication order (kinds 30041 / 30818 / …, not nested indices).
     *
     * @return list<string>
     */
    public function collectSectionCoordinates(Event $root, int $depth = 0): array
    {
        if ($depth >= self::MAX_NEST_DEPTH) {
            return [];
        }

        $coordinates = [];
        foreach ($root->getTags() as $tag) {
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
                if ($child !== null) {
                    array_push($coordinates, ...$this->collectSectionCoordinates($child, $depth + 1));
                }
            } elseif (\in_array($kind, KindsEnum::publicationSectionKindValues(), true)) {
                $coordinates[] = $coord;
            }
        }

        return $coordinates;
    }

    /**
     * @return list<array{label: string, coordinate: ?string, nested_d: ?string, kind: ?int, children: list<mixed>}>
     */
    public function buildToc(Event $root, int $depth = 0): array
    {
        if ($depth >= self::MAX_NEST_DEPTH) {
            return [];
        }

        $items = [];
        foreach ($root->getTags() as $tag) {
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
            $label = $this->labelForCoordinate($coord, $kind);
            if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
                $pkHex = strtolower($parts[1]);
                $nestedD = trim($parts[2]);
                $child = $this->publicationIndexStore->getByPubkeyHexAndD($pkHex, $nestedD);
                if ($child === null) {
                    $childNpub = $this->nostrKeyHelper->convertPublicKeyToBech32($pkHex);
                    $child = $this->relayResolver->resolvePublicationIndex($childNpub, $nestedD);
                }
                $children = $child !== null ? $this->buildToc($child, $depth + 1) : [];
                $items[] = [
                    'label' => $label,
                    'coordinate' => null,
                    'nested_d' => trim($parts[2]),
                    'nested_npub' => $this->nostrKeyHelper->convertPublicKeyToBech32($parts[1]),
                    'kind' => $kind,
                    'children' => $children,
                ];
            } elseif (\in_array($kind, KindsEnum::publicationSectionKindValues(), true)) {
                $items[] = [
                    'label' => $label,
                    'coordinate' => $coord,
                    'nested_d' => null,
                    'nested_npub' => null,
                    'kind' => $kind,
                    'children' => [],
                ];
            }
        }

        return $items;
    }

    public function renderSectionHtml(string $coordinate): ?string
    {
        $article = $this->relayResolver->resolveArticleByCoordinate($coordinate);
        if ($article === null) {
            return null;
        }

        return $this->articleBodyHtmlRenderer->renderForArticle($article);
    }

    /**
     * All sections in publication order (same traversal as {@see PublicationAsciidocAssembler}).
     */
    public function renderFullPublicationHtml(Event $rootIndex): string
    {
        $html = '';
        $this->appendIndexHtml($rootIndex, 2, $html);

        return $html;
    }

    public static function sectionDomId(string $coordinate): string
    {
        return 'section-'.str_replace(':', '-', strtolower(trim($coordinate)));
    }

    private function appendIndexHtml(Event $index, int $headingLevel, string &$html): void
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
                $html .= '<section class="publication-section publication-section--index">';
                if ($sectionTitle !== '') {
                    $html .= $this->sectionHeading($headingLevel, $sectionTitle);
                }
                $indexContent = trim($child->getContent());
                if ($indexContent !== '') {
                    $html .= '<div class="publication-section__intro">'.$this->articleBodyHtmlRenderer->renderForArticle(
                        $this->articleFromIndexContent($child, $indexContent),
                    ).'</div>';
                }
                $this->appendIndexHtml($child, $headingLevel + 1, $html);
                $html .= '</section>';
            } elseif (\in_array($kind, KindsEnum::publicationSectionKindValues(), true)) {
                $article = $this->relayResolver->resolveArticleByCoordinate($coord);
                if (!$article instanceof Article) {
                    continue;
                }

                $sectionTitle = trim((string) ($article->getTitle() ?? ''));
                if ($sectionTitle === '') {
                    $sectionTitle = trim((string) ($parts[2] ?? ''));
                }
                $sectionId = self::sectionDomId($coord);
                $html .= '<section class="publication-section" id="'.\htmlspecialchars($sectionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
                if ($sectionTitle !== '') {
                    $html .= $this->sectionHeading($headingLevel, $sectionTitle);
                }
                $body = $this->articleBodyHtmlRenderer->renderForArticle($article);
                if ($body !== '') {
                    $html .= '<div class="publication-section__body">'.$body.'</div>';
                }
                $html .= '</section>';
            }
        }
    }

    private function sectionHeading(int $level, string $title): string
    {
        $level = max(2, min(6, $level));
        $safe = \htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<h'.$level.' class="publication-section__title">'.$safe.'</h'.$level.'>';
    }

    /**
     * Render index `content` through the article HTML pipeline (typically AsciiDoc in NKBIP-01).
     */
    private function articleFromIndexContent(Event $index, string $content): Article
    {
        $article = new Article();
        $article->setContent($content);
        $article->setPubkey($index->getPubkey());
        $article->setSlug((string) ($index->getSlug() ?? ''));
        $article->setKind(KindsEnum::WIKI_ARTICLE);

        return $article;
    }

    public function titleFromIndex(Event $index): string
    {
        foreach ($index->getTags() as $tag) {
            if (NostrEventTags::tagNameMatches($tag, 'title')) {
                $seq = NostrEventTags::rowToStringList($tag);

                return trim((string) ($seq[1] ?? ''));
            }
        }

        $slug = $index->getSlug();

        return $slug !== null && $slug !== '' ? $slug : 'Publication';
    }

    public function summaryFromIndex(Event $index): string
    {
        foreach ($index->getTags() as $tag) {
            if (NostrEventTags::tagNameMatches($tag, 'summary')) {
                $seq = NostrEventTags::rowToStringList($tag);

                return trim((string) ($seq[1] ?? ''));
            }
        }

        return '';
    }

    public function imageFromIndex(Event $index): string
    {
        foreach ($index->getTags() as $tag) {
            if (NostrEventTags::tagNameMatches($tag, 'image')) {
                $seq = NostrEventTags::rowToStringList($tag);

                return trim((string) ($seq[1] ?? ''));
            }
        }

        return '';
    }

    private function labelForCoordinate(string $coordinate, int $kind): string
    {
        $parts = explode(':', $coordinate, 3);
        $pk = strtolower($parts[1] ?? '');
        $d = trim($parts[2] ?? '');
        if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
            $stored = $this->publicationIndexStore->getByPubkeyHexAndD($pk, $d);
            if ($stored === null) {
                $stored = $this->relayResolver->resolvePublicationIndex(
                    $this->nostrKeyHelper->convertPublicKeyToBech32($pk),
                    $d,
                );
            }
            if ($stored !== null) {
                return $this->titleFromIndex($stored);
            }
        } else {
            $article = $this->relayResolver->resolveArticleByCoordinate($coordinate);
            if ($article instanceof Article && $article->getTitle()) {
                return (string) $article->getTitle();
            }
        }

        return $d !== '' ? $d : $coordinate;
    }
}
