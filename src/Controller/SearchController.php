<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function index(Request $request, ArticleRepository $articleRepository): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $perPage = 25;
        $page = max(1, $request->query->getInt('page', 1));
        $total = 0;
        $results = [];
        $lastPage = 1;

        if ($query !== '') {
            $total = $articleRepository->countSearchArticles($query);
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($page > $lastPage) {
                $page = $lastPage;
            }
            $offset = ($page - 1) * $perPage;
            $results = $articleRepository->searchArticles($query, $perPage, $offset);
        }

        return $this->render('pages/search.html.twig', [
            'query' => $query,
            'results' => $results,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
