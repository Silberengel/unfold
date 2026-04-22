<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Service\NostrClient;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

class DefaultController extends AbstractController
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly NostrClient $nostrClient,
        private readonly ParameterBagInterface $params
    ) {}

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        $npub = $this->params->get('npub');
        $dTag = $this->params->get('d_tag');
        // Key must match {@see Header}. Throw from the cache callback when the index is missing so `null`
        // is not stored under this key (same pattern as {@see CategoryLink} / per-category cache).
        $cacheKey = 'magazine_root_v2_'.$dTag;
        try {
            $mag = $this->cache->get($cacheKey, function (ItemInterface $item) use ($npub, $dTag) {
                $item->expiresAfter(300);
                $mag = $this->nostrClient->getMagazineIndex($npub, $dTag);
                if ($mag === null) {
                    throw new \RuntimeException('Magazine root index not found for '.$dTag);
                }

                return $mag;
            });
        } catch (\Throwable) {
            return $this->render('home.html.twig', [
                'indices' => [],
            ]);
        }

        if ($mag === null) {
            return $this->render('home.html.twig', [
                'indices' => [],
            ]);
        }

        $tags = $mag->getTags();

        $cats = array_filter($tags, function($tag) {
            return ($tag[0] === 'a');
        });

        return $this->render('home.html.twig', [
            'indices' => array_values($cats)
        ]);
    }


    /**
     * @throws InvalidArgumentException
     */
    #[Route('/cat/{slug}', name: 'magazine-category')]
    public function magCategory($slug, ArticleRepository $articleRepository, LoggerInterface $logger): Response
    {
        $npub = $this->params->get('npub');
        $cacheKey = 'magazine-' . $slug;
        try {
            $catIndex = $this->cache->get($cacheKey, function ($item) use ($npub, $slug) {
                $item->expiresAfter(300); // 5 minutes
                $mag = $this->nostrClient->getMagazineIndex($npub, $slug);
                if ($mag === null) {
                    throw new \RuntimeException('Category index not found for '.$slug);
                }

                return $mag;
            });
        } catch (\Throwable) {
            $catIndex = null;
        }
        $list = [];
        $coordinates = [];
        $category = [];
        if ($catIndex) {
            foreach ($catIndex->getTags() as $tag) {
                if ($tag[0] === 'title') {
                    $category['title'] = $tag[1];
                }
                if ($tag[0] === 'summary') {
                    $category['summary'] = $tag[1];
                }
                if ($tag[0] === 'a') {
                    $coordinates[] = $tag[1];
                }
            }
        }

        if (!empty($coordinates)) {
            $slugs = array_map(static function ($coordinate) {
                $parts = explode(':', (string) $coordinate, 3);

                return trim((string) end($parts));
            }, $coordinates);
            $slugs = array_values(array_filter($slugs, static fn (string $s): bool => $s !== ''));
            $articles = $articleRepository->findBySlugsCriteria($slugs);
            $slugMap = [];
            foreach ($articles as $item) {
                $slug = trim((string) $item->getSlug());
                if ($slug !== '') {
                    if (!isset($slugMap[$slug])) {
                        $slugMap[$slug] = $item;
                    } else {
                        $existingItem = $slugMap[$slug];
                        if ($item->getCreatedAt() > $existingItem->getCreatedAt()) {
                            $slugMap[$slug] = $item;
                        }
                    }
                }
            }
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', (string) $coordinate, 3);
                $slugKey = trim((string) end($parts));
                if ($slugKey !== '' && isset($slugMap[$slugKey])) {
                    $list[] = $slugMap[$slugKey];
                }
            }
        }

        $category['title'] = $category['title'] ?? '';
        $category['summary'] = $category['summary'] ?? '';

        return $this->render('pages/category.html.twig', [
            'list' => $list,
            'category' => $category
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
                throw new \Exception('No OG data found');
            }
            return $this->render('components/Molecules/OgPreview.html.twig', [
                'og' => [
                    'title' => $info->title,
                    'description' => $info->description,
                    'image' => $info->image,
                    'url' => $url
                ]
            ]);
        } catch (\Exception $e) {
            return new Response('<div class="alert alert-warning">Unable to load OG preview for ' . htmlspecialchars($url) . '</div>', 200);
        }
    }
}
