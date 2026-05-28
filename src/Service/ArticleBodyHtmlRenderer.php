<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\ArticleHighlightRepository;
use App\Util\CommonMark\Converter;
use League\CommonMark\Exception\CommonMarkException;

/**
 * Markdown → HTML for an {@see Article}, with the same kind-9802 highlight injection as {@see \App\Controller\ArticleController}.
 */
final class ArticleBodyHtmlRenderer
{
    public function __construct(
        private readonly Converter $converter,
        private readonly ArticleBodyAsciidocRenderer $asciidocRenderer,
        private readonly ArticleHighlightRepository $articleHighlightRepository,
        private readonly ArticleBodyHighlightInjector $articleBodyHighlightInjector,
        private readonly NostrContentLinkEnhancer $nostrContentLinkEnhancer,
    ) {
    }

    public function renderForArticle(Article $article): string
    {
        $raw = (string) ($article->getContent() ?? '');
        $kind = $article->getKind()?->value ?? KindsEnum::LONGFORM->value;
        if (\in_array($kind, KindsEnum::asciidocBodyKindValues(), true)) {
            $html = $this->asciidocRenderer->convertToHtml($raw);
            $html = $this->nostrContentLinkEnhancer->enhanceHtml($html);
        } else {
            try {
                $html = $this->converter->convertToHTML($raw);
            } catch (CommonMarkException) {
                $html = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        $highlights = $this->articleHighlightRepository->findByArticle($article);

        return $this->articleBodyHighlightInjector->inject($html, $highlights)['html'];
    }
}
