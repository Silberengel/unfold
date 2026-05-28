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
        private readonly ArticleBodyHtmlRenderer $articleBodyHtmlRenderer,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    public function resolveRootIndex(string $npub, string $dTag): ?Event
    {
        return $this->relayResolver->resolvePublicationIndex($npub, $dTag);
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
