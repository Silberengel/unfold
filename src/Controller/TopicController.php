<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Service\MagazineContentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TopicController extends AbstractController
{
    public function __construct(
        private readonly MagazineContentService $magazineContent,
    ) {
    }

    #[Route(
        path: '/topic/{topic}',
        name: 'topic',
        methods: ['GET'],
        requirements: ['topic' => '[^/]+'],
    )]
    public function byTopic(
        string $topic,
        Request $request,
        ArticleRepository $articleRepository,
    ): Response {
        $categoryATags = $this->magazineContent->getHomeCategoryAIndexTagsFromStoreOnly();
        $curatedSlugs = $this->magazineContent->collectCuratedArticleSlugsForTenant($categoryATags);

        $perPage = 25;
        $page = max(1, $request->query->getInt('page', 1));
        $total = $articleRepository->countPublishedByTopic($topic, $curatedSlugs);
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }
        $offset = ($page - 1) * $perPage;
        $list = $articleRepository->findPublishedByTopic($topic, $perPage, $offset, $curatedSlugs);
        $topicParam = $articleRepository->normalizeTopicParam(rawurldecode($topic));

        return $this->render('pages/topic.html.twig', [
            'topic_param' => $topicParam,
            'topic_label' => $topicParam,
            'list' => $list,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
