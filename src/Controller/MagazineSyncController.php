<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MagazineContentService;
use App\Service\MagazineRefresher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/** Stale-first: the main request only reads {@see \App\Service\MagazineIndexStore}; this refetches Nostr, updates that store, and returns HTML fragments for Stimulus to patch the document. */
#[AsController]
final class MagazineSyncController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly MagazineRefresher $refresher,
        private readonly MagazineContentService $magazineContent,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/ux/magazine-sync', name: 'ux_magazine_sync', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        @set_time_limit(8);
        @ini_set('max_execution_time', '8');

        try {
            $page = (string) $request->query->get('page', 'article');
            if (!\in_array($page, ['home', 'category', 'article', 'articles'], true)) {
                $page = 'article';
            }
            $slug = (string) $request->query->get('slug', '');

            $prefer = $slug !== '' ? [$slug] : [];

            try {
                $this->refresher->refreshFromRelays(8, $prefer);
            } catch (\Throwable $e) {
                $this->logger->warning('MagazineSyncController: refresh failed', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);

                return new JsonResponse(
                    ['ok' => false, 'error' => 'refresh_failed', 'message' => $e->getMessage()],
                    Response::HTTP_OK
                );
            }

            $community = (bool) $this->params->get('community_articles');
            $tags = $this->magazineContent->getHomeCategoryAIndexTagsFromStoreOnly();
            $globals = [
                'magazine_community_articles' => $community,
            ];

            $header = $this->twig->render('ux/magazine/header_ul.html.twig', array_merge($globals, [
                'cats' => $tags,
            ]));

            $body = null;
            if ($page === 'home') {
                $body = $this->twig->render('ux/magazine/home_body.html.twig', array_merge($globals, [
                    'indices' => $tags,
                ]));
            } elseif ($page === 'category' && $slug !== '') {
                $data = $this->magazineContent->getCategoryPageData($slug);
                $body = $this->twig->render('ux/magazine/category_body.html.twig', array_merge($globals, [
                    'list' => $data['list'],
                    'category' => $data['category'],
                ]));
            } elseif ($page === 'articles') {
                $body = null;
            }

            return new JsonResponse([
                'ok' => true,
                'header' => $header,
                'body' => $body,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MagazineSyncController: unexpected failure', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => 'server_error',
                    'message' => 'Magazine UI sync could not be rendered.',
                ],
                Response::HTTP_OK
            );
        }
    }
}
