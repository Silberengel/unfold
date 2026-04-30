<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleHighlightRepository;
use App\Service\MagazineContentService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    public function __construct(
        private readonly MagazineContentService $magazineContent,
        private readonly ArticleHighlightRepository $articleHighlightRepository,
    ) {
    }

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        $categoryATags = $this->magazineContent->getHomeCategoryAIndexTagsFromStoreOnly();
        $curation = $this->magazineContent->buildHomeCurationWallData();

        return $this->render('home.html.twig', [
            'home_curation_heading' => $curation['heading'],
            'home_curation_tiles' => $curation['tiles'],
            'home_featured_tiles' => $this->magazineContent->buildHomeMixedFeaturedWallTiles($categoryATags),
            'home_highlights' => $this->articleHighlightRepository->findRecentForHome(40),
        ]);
    }

    #[Route('/cat/{slug}', name: 'magazine-category')]
    public function magCategory(Request $request, string $slug): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $data = $this->magazineContent->getCategoryPageData($slug, $page, 25);

        return $this->render('pages/category.html.twig', [
            'list' => $data['list'],
            'category' => $data['category'],
            'pagination' => $data['pagination'],
            'sync_slug' => $slug,
        ]);
    }

    /**
     * OG Preview endpoint for URLs
     */
    #[Route('/og-preview/', name: 'og_preview', methods: ['POST'])]
    public function ogPreview(RequestStack $requestStack): Response
    {
        $request = $requestStack->getCurrentRequest();
        $data = json_decode($request->getContent(), true);
        $url = $data['url'] ?? null;
        if (!$url) {
            return new Response('<div class="alert alert-warning">No URL provided.</div>', 400);
        }
        try {
            $embed = new \Embed\Embed();
            $info = $embed->get($url);
            if (!$info) {
                throw new Exception('No OG data found');
            }

            return $this->render('components/Molecules/OgPreview.html.twig', [
                'og' => [
                    'title' => $info->title,
                    'description' => $info->description,
                    'image' => $info->image,
                    'url' => $url,
                ],
            ]);
        } catch (Exception $e) {
            return new Response('<div class="alert alert-warning">Unable to load OG preview for '.htmlspecialchars($url).'</div>', 200);
        }
    }
}
