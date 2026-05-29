<?php

namespace App\Controller;

use App\Entity\Article;
use App\Http\PhpExecutionTime;
use App\Repository\ArticleHighlightRepository;
use App\Repository\ArticleRepository;
use App\Service\ArticleBodyHtmlRenderer;
use App\Service\MagazineContentService;
use App\Enum\KindsEnum;
use App\Form\EditorType;
use App\Service\ArticleCommentReplyContextBuilder;
use App\Service\ArticleCommentThreadLoader;
use App\Service\NostrClient;
use App\Service\NostrKeyHelper;
use App\Service\CacheService;
use App\Nostr\Nip19Codec;
use App\Util\CommonMark\Converter;
use App\Service\NostrPreviewCardRenderer;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\Exception\CommonMarkException;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Workflow\WorkflowInterface;

class ArticleController extends AbstractController
{
    public function __construct(
        private readonly MagazineContentService $magazineContent,
        private readonly ArticleHighlightRepository $articleHighlightRepository,
        private readonly ArticleCommentReplyContextBuilder $commentReplyContextBuilder,
    ) {
    }

    /**
     * Lazy-loaded comment thread (HTML fragment for Stimulus). Must not live under /article/{naddr}.
     */
    #[Route('/fragment/comments', name: 'article_comments_fragment', methods: ['GET'])]
    public function commentsFragment(Request $request, ArticleCommentThreadLoader $loader, LoggerInterface $logger): Response
    {
        // {@see NostrClient::getArticleDiscussion} uses parallel CLI workers; cap below multi-minute defaults
        // so Apache event MPM scoreboard slots are not held unnecessarily (see {@see PhpExecutionTime}).
        $t = PhpExecutionTime::NOSTR_BOUND_WEB_SEC;
        @set_time_limit($t);
        @ini_set('max_execution_time', (string) $t);

        $t0 = microtime(true);
        $coordinate = $request->query->getString('coordinate');
        if ($coordinate === '' || !self::isValidNostrCoordinate($coordinate)) {
            return new Response('Invalid coordinate', Response::HTTP_BAD_REQUEST);
        }

        $articleEventId = $request->query->getString('e');
        if ($articleEventId !== '' && !self::isValidHexEventId($articleEventId)) {
            return new Response('Invalid event id', Response::HTTP_BAD_REQUEST);
        }
        if ($articleEventId === '') {
            $articleEventId = null;
        }

        $articleTitle = $request->query->getString('title');
        if (strlen($articleTitle) > 200) {
            $articleTitle = substr($articleTitle, 0, 200);
        }

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ];

        // Phase-1 fast path: return whatever is in the filesystem cache without touching relays.
        // The JS fires this in parallel with the full relay request so readers see cached comments
        // immediately (< 100 ms) while the relay fetch continues in the background.
        if ($request->query->getBoolean('cached')) {
            try {
                $cached = $loader->tryLoadFromCacheOnly($coordinate, $articleEventId);
            } catch (\Throwable $e) {
                $logger->warning('http.fragment.comments_cache_failed', [
                    'coordinate' => $coordinate,
                    'message' => $e->getMessage(),
                ]);

                return new Response('', Response::HTTP_NO_CONTENT, $headers);
            }
            if ($cached === null) {
                // Cache miss — keep the client-side loading message; full relay fetch is in flight.
                return new Response('', Response::HTTP_NO_CONTENT, $headers);
            }
            try {
                $data = $this->commentReplyContextBuilder->enrich($cached, $coordinate, $articleEventId, $articleTitle);

                return $this->render('components/Organisms/Comments.html.twig', $data, new Response('', Response::HTTP_OK, $headers));
            } catch (\Throwable) {
                return new Response('<div class="comments"></div>', Response::HTTP_OK, $headers);
            }
        }

        $logger->info('http.fragment.comments_start', [
            'coordinate' => $coordinate,
            'article_event_hex' => $articleEventId,
        ]);

        try {
            $data = $loader->load($coordinate, $articleEventId, incrementalCache: true);
            $data = $this->commentReplyContextBuilder->enrich(
                $data,
                $coordinate,
                $articleEventId,
                $articleTitle
            );
            $logger->info('http.fragment.comments_after_load', [
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);

            $tRender = microtime(true);
            $response = $this->render('components/Organisms/Comments.html.twig', $data, new Response(
                '',
                Response::HTTP_OK,
                $headers
            ));
            $logger->info('http.fragment.comments_response', [
                'total_elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                'render_elapsed_ms' => (int) round((microtime(true) - $tRender) * 1000),
            ]);

            return $response;
        } catch (\Throwable $e) {
            $logger->error('http.fragment.comments_exception', [
                'message' => $e->getMessage(),
                'exception_class' => \get_class($e),
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);

            return new Response('<div class="comments"></div>', Response::HTTP_OK, $headers);
        }
    }

    private static function isValidNostrCoordinate(string $coordinate): bool
    {
        $parts = explode(':', $coordinate, 3);
        if (\count($parts) !== 3) {
            return false;
        }
        [$kind, $pubkey, $d] = $parts;
        if ($d === '' || !ctype_digit((string) $kind)) {
            return false;
        }

        return strlen($pubkey) === 64 && ctype_xdigit($pubkey);
    }

    private static function isValidHexEventId(string $id): bool
    {
        return strlen($id) === 64 && ctype_xdigit($id);
    }

    /**
     * @throws \Exception
     */
    #[Route('/article/{naddr}', name: 'article-naddr')]
    public function naddr(NostrClient $nostrClient, Nip19Codec $nip19, NostrKeyHelper $nostrKeyHelper, $naddr)
    {
        $decoded = $nip19->decode($naddr);

        if ($decoded->type !== 'naddr') {
            throw new \Exception('Invalid naddr');
        }

        $data = $decoded->data;
        $slug = $data->identifier;
        $relays = $data->relays;
        $author = $data->pubkey;
        $kind = (int) $data->kind;

        $npub = $nostrKeyHelper->convertPublicKeyToBech32((string) $author);

        if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
            return $this->redirectToRoute('publication', ['npub' => $npub, 'slug' => $slug], Response::HTTP_MOVED_PERMANENTLY);
        }

        if (!\in_array($kind, KindsEnum::articleBodyKindValues(), true)) {
            throw new \Exception('Unsupported naddr kind');
        }

        $nostrClient->getLongFormFromNaddr($slug, $relays, $author, $kind);
        if ($slug) {
            return $this->redirectToRoute('article', ['npub' => $npub, 'slug' => $slug], Response::HTTP_MOVED_PERMANENTLY);
        }

        throw new \Exception('No article.');
    }

    /**
     * @throws InvalidArgumentException|CommonMarkException
     */
    // Slug is the NIP-33 d-identifier and may contain "/"; default [^/]++ would break sitemap/URL generation.
    #[Route(
        path: '/p/{npub}/d/{slug}',
        name: 'article',
        requirements: ['npub' => '^npub1.*', 'slug' => '.+'],
        options: ['utf8' => true],
    )]
    public function article(
        string $npub,
        string $slug,
        ArticleRepository $articleRepository,
        CacheService $cacheService,
        ArticleCommentThreadLoader $commentThreadLoader,
        ArticleBodyHtmlRenderer $articleBodyHtmlRenderer,
        NostrKeyHelper $nostrKeyHelper,
    ): Response {
        $article = $articleRepository->findLatestBySlugForTenant($slug, $nostrKeyHelper->convertToHex($npub));
        if ($article === null) {
            throw $this->createNotFoundException('The article could not be found');
        }

        return $this->renderArticle(
            $article,
            $cacheService,
            $commentThreadLoader,
            $articleBodyHtmlRenderer,
            $nostrKeyHelper
        );
    }

    /**
     * Legacy: /article/d/{slug} → 301 to /p/{npub}/d/{slug} (NIP-33 with author npub in path).
     */
    #[Route(
        path: '/article/d/{slug}',
        name: 'article-legacy-redirect',
        requirements: ['slug' => '.+'],
        options: ['utf8' => true],
    )]
    public function articleLegacyRedirect(
        string $slug,
        EntityManagerInterface $entityManager,
        NostrKeyHelper $nostrKeyHelper,
    ): Response {
        $article = $this->loadLatestArticleBySlug($entityManager, $slug);
        if ($article === null) {
            throw $this->createNotFoundException('The article could not be found');
        }
        $npub = $nostrKeyHelper->convertPublicKeyToBech32((string) $article->getPubkey());

        return $this->redirectToRoute('article', ['npub' => $npub, 'slug' => $slug], Response::HTTP_MOVED_PERMANENTLY);
    }

    private function loadLatestArticleBySlug(EntityManagerInterface $entityManager, string $slug): ?Article
    {
        /** @var ArticleRepository $repository */
        $repository = $entityManager->getRepository(Article::class);

        return $repository->findLatestBySlug($slug);
    }

    private function renderArticle(
        Article $article,
        CacheService $cacheService,
        ArticleCommentThreadLoader $commentThreadLoader,
        ArticleBodyHtmlRenderer $articleBodyHtmlRenderer,
        NostrKeyHelper $nostrKeyHelper,
    ): Response {
        $t = PhpExecutionTime::NOSTR_BOUND_WEB_SEC;
        set_time_limit($t);
        ini_set('max_execution_time', (string) $t);

        $html = $articleBodyHtmlRenderer->renderForArticle($article);

        $npub = $nostrKeyHelper->convertPublicKeyToBech32($article->getPubkey());
        $author = $cacheService->getMetadata($npub);

        $kind = $article->getKind()->value;
        $pubkey = (string) $article->getPubkey();
        $articleSlug = (string) $article->getSlug();
        $coordinate = $kind.':'.$pubkey.':'.$articleSlug;
        $eid = $article->getEventId();
        $eid = ($eid !== null && $eid !== '' && self::isValidHexEventId($eid)) ? $eid : null;
        $articleTitle = (string) ($article->getTitle() ?? '');

        $commentsData = null;
        $commentsPreloaded = false;
        $commentReplyContext = $this->commentReplyContextBuilder->buildArticleReplyContext($coordinate, $eid, $articleTitle);
        $cached = $commentThreadLoader->tryLoadFromCacheOnly($coordinate, $eid);
        if (null !== $cached) {
            $commentsData = $this->commentReplyContextBuilder->enrich(
                $cached,
                $coordinate,
                $eid,
                $articleTitle
            );
            $commentReplyContext = $commentsData['comment_reply_context'];
            $commentsPreloaded = true;
        }

        return $this->render('pages/article.html.twig', \array_merge(
            $this->sidebarNavData(),
            [
                'article' => $article,
                'author' => $author,
                'npub' => $npub,
                'content' => $html,
                'comments_data' => $commentsData,
                'comments_preloaded' => $commentsPreloaded,
                'comment_reply_context' => $commentReplyContext,
                'sidebar_highlights' => $this->articleHighlightRepository->findByArticle($article),
            ],
        ));
    }

    /**
     * Left sidebar widgets shared with the home page (magazine recent list).
     *
     * @return array{sidebar_category_recent: list<\App\Dto\FeaturedArticleCard>}
     */
    private function sidebarNavData(): array
    {
        $categoryATags = $this->magazineContent->getHomeCategoryAIndexTagsFromStoreOnly();

        return [
            'sidebar_category_recent' => $this->magazineContent->buildHomeSidebarCategorizedRecent($categoryATags),
        ];
    }

    /**
     * Fetch complete event to show as preview
     * POST data contains an object with request params
     */
    #[Route('/preview/', name: 'article-preview-event', methods: ['POST'])]
    public function articlePreviewEvent(
        Request $request,
        NostrPreviewCardRenderer $nostrPreviewCardRenderer,
    ): Response {
        $data = $request->getContent();
        $descriptor = json_decode($data);

        if (!\is_object($descriptor) || !isset($descriptor->type)) {
            return new Response(
                '<span class="text-subtle">Invalid preview request.</span>',
                Response::HTTP_OK,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }

        $type = (string) $descriptor->type;
        $identifier = isset($descriptor->identifier) && \is_string($descriptor->identifier)
            ? $descriptor->identifier
            : '';

        if ($type === 'npub' || $type === 'nprofile') {
            $link = [
                'type' => $type,
                'identifier' => $identifier,
                'data' => isset($descriptor->decoded) && \is_string($descriptor->decoded)
                    ? json_decode($descriptor->decoded)
                    : null,
            ];
            $html = $nostrPreviewCardRenderer->renderFromLink($link);
        } elseif ($type === 'nevent' || $type === 'naddr') {
            $decoded = isset($descriptor->decoded) && \is_string($descriptor->decoded) ? $descriptor->decoded : '';
            if ($decoded === '' || $identifier === '') {
                $html = '<span class="text-subtle">Preview unavailable (missing data).</span>';
            } else {
                $html = $nostrPreviewCardRenderer->renderFromLink([
                    'type' => $type,
                    'identifier' => $identifier,
                    'data' => json_decode($decoded),
                ]);
            }
        } else {
            $html = '<span class="text-subtle">Preview unavailable.</span>';
        }

        return new Response(
            $html,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    /**
     * Create new article
     * @throws InvalidArgumentException
     * @throws \Exception
     */
    #[Route('/article-editor/create', name: 'editor-create')]
    #[Route('/article-editor/edit/{id}', name: 'editor-edit')]
    public function newArticle(Request $request, EntityManagerInterface $entityManager, CacheItemPoolInterface $articlesCache,
                               WorkflowInterface $articlePublishingWorkflow, NostrKeyHelper $nostrKeyHelper, Article $article = null): Response
    {
        if (!$article) {
            $article = new Article();
            $article->setKind(KindsEnum::LONGFORM);
            $article->setCreatedAt(new \DateTimeImmutable());
            $formAction = $this->generateUrl('editor-create');
        } else {
            $formAction = $this->generateUrl('editor-edit', ['id' => $article->getId()]);
        }

        $form = $this->createForm(EditorType::class, $article, ['action' => $formAction]);
        $form->handleRequest($request);

        // Step 3: Check if the form is submitted and valid
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            $currentPubkey = $nostrKeyHelper->convertToHex($user->getUserIdentifier());

            if ($article->getPubkey() === null) {
                $article->setPubkey($currentPubkey);
            }

            // Check which button was clicked
            if ($form->getClickedButton() === $form->get('actions')->get('submit')) {
                // Save button was clicked, handle the "Publish" action
                $this->addFlash('success', 'Product published!');
            } elseif ($form->getClickedButton() === $form->get('actions')->get('draft')) {
                // Save and Publish button was clicked, handle the "Draft" action
                $this->addFlash('success', 'Product saved as draft!');
            } elseif ($form->getClickedButton() === $form->get('actions')->get('preview')) {
                // Preview button was clicked, handle the "Preview" action
                // construct slug from title and save to tags
                $slugger = new AsciiSlugger();
                $slug = $slugger->slug($article->getTitle())->lower();
                $article->setSig(''); // clear the sig
                $article->setSlug($slug);
                $cacheKey = 'article_' . $currentPubkey . '_' . $article->getSlug();
                $cacheItem = $articlesCache->getItem($cacheKey);
                $cacheItem->set($article);
                $articlesCache->save($cacheItem);

                return $this->redirectToRoute('article-preview', ['d' => $article->getSlug()]);
            }
        }

        // load template with content editor
        return $this->render('pages/editor.html.twig', [
            'article' => $article,
            'form' => $this->createForm(EditorType::class, $article)->createView(),
        ]);
    }

    /**
     * Preview article
     * @throws InvalidArgumentException
     * @throws CommonMarkException
     * @throws \Exception
     */
    #[Route('/article-preview/{d}', name: 'article-preview')]
    public function preview($d, Converter $converter,
                            CacheItemPoolInterface $articlesCache, NostrKeyHelper $nostrKeyHelper): Response
    {
        $user = $this->getUser();
        $currentPubkey = $nostrKeyHelper->convertToHex($user->getUserIdentifier());

        $cacheKey = 'article_' . $currentPubkey . '_' . $d;
        $cacheItem = $articlesCache->getItem($cacheKey);
        $article = $cacheItem->get();

        $content = $converter->convertToHtml($article->getContent());
        $previewNpub = $nostrKeyHelper->convertPublicKeyToBech32($currentPubkey);

        return $this->render('pages/article.html.twig', [
            'article' => $article,
            'content' => $content,
            'author' => $user->getMetadata(),
            'npub' => $previewNpub,
            'comments_preloaded' => false,
        ]);
    }

    /**
     * Display latest community articles (paginated).
     */
    #[Route('/articles', name: 'articles')]
    public function latestArticles(Request $request, EntityManagerInterface $entityManager): Response
    {
        $t = PhpExecutionTime::LIGHT_WEB_SEC;
        set_time_limit($t);
        ini_set('max_execution_time', (string) $t);

        $perPage = 25;
        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * $perPage;
        /** @var ArticleRepository $repo */
        $repo = $entityManager->getRepository(Article::class);
        $total = $repo->countForMagazine();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
            $offset = ($page - 1) * $perPage;
        }
        $articles = $repo->findForMagazinePaginated($perPage, $offset);

        $category = (object) [
            'title' => 'Community Articles',
            'summary' => 'Latest articles from the community',
        ];

        return $this->render('pages/category.html.twig', [
            'category' => $category,
            'list' => $articles,
            'sync_slug' => '',
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

}
