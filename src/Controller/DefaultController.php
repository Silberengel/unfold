<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleHighlightRepository;
use App\Service\MagazineContentService;
use App\Service\OpenGraphPreviewChecker;
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
        private readonly OpenGraphPreviewChecker $openGraphPreviewChecker,
    ) {
    }

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        $categoryATags = $this->magazineContent->getHomeCategoryAIndexTagsFromStoreOnly();
        $curatedSlugs = $this->magazineContent->collectCuratedArticleSlugsForTenant($categoryATags);
        $magazineStrip = $this->magazineContent->buildHomeMagazineRootHeadlineStripData();

        return $this->render('home.html.twig', [
            'home_magazine_strip_tiles' => $magazineStrip['tiles'],
            'home_featured_tiles' => $this->magazineContent->buildHomeMixedFeaturedWallTiles($categoryATags),
            'home_sidebar_category_recent' => $this->magazineContent->buildHomeSidebarCategorizedRecent($categoryATags),
            'home_highlights' => $this->articleHighlightRepository->findRecentForHome(100, $curatedSlugs),
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
            $title = $info->title ?? null;
            $description = $info->description ?? null;
            $image = $info->image ?? null;

            if (! $this->openGraphPreviewChecker->hasMeaningfulMetadata($title, $description, $image, $url)) {
                return new Response('', Response::HTTP_NO_CONTENT);
            }

            return $this->render('components/Molecules/OgPreview.html.twig', [
                'og' => [
                    'title' => $title,
                    'description' => $description,
                    'image' => $image,
                    'url' => $url,
                ],
            ]);
        } catch (Exception) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }
    }
}
