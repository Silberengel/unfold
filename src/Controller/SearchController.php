<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\PublicationFeature;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function index(
        Request $request,
        ArticleRepository $articleRepository,
        EventRepository $eventRepository,
        PublicationFeature $publicationFeature,
    ): Response {
        $query = trim((string) $request->query->get('q', ''));
        $perPage = 25;
        $page = max(1, $request->query->getInt('page', 1));
        $total = 0;
        $articleResults = [];
        $publicationResults = [];
        $lastPage = 1;

        if ($query !== '') {
            $articleTotal = $articleRepository->countSearchArticles($query);
            $pubTotal = $publicationFeature->isEnabled()
                ? $eventRepository->countSearchPublicationIndices($query)
                : 0;
            $total = $articleTotal + $pubTotal;
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($page > $lastPage) {
                $page = $lastPage;
            }
            $offset = ($page - 1) * $perPage;
            if ($publicationFeature->isEnabled() && $pubTotal > 0) {
                if ($offset < $pubTotal) {
                    $pubLimit = min($perPage, $pubTotal - $offset);
                    $publicationResults = $eventRepository->searchPublicationIndices($query, $pubLimit, $offset);
                    $articleLimit = $perPage - $pubLimit;
                    if ($articleLimit > 0) {
                        $articleResults = $articleRepository->searchArticles($query, $articleLimit, 0);
                    }
                } else {
                    $articleResults = $articleRepository->searchArticles(
                        $query,
                        $perPage,
                        $offset - $pubTotal,
                    );
                }
            } else {
                $articleResults = $articleRepository->searchArticles($query, $perPage, $offset);
            }
        }

        return $this->render('pages/search.html.twig', [
            'query' => $query,
            'results' => $articleResults,
            'publication_results' => $publicationResults,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
