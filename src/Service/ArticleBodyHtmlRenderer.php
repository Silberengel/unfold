<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
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
        private readonly ArticleHighlightRepository $articleHighlightRepository,
        private readonly ArticleBodyHighlightInjector $articleBodyHighlightInjector,
    ) {
    }

    public function renderForArticle(Article $article): string
    {
        $raw = (string) ($article->getContent() ?? '');
        try {
            $html = $this->converter->convertToHTML($raw);
        } catch (CommonMarkException) {
            $html = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $highlights = $this->articleHighlightRepository->findByArticle($article);

        return $this->articleBodyHighlightInjector->inject($html, $highlights)['html'];
    }
}
