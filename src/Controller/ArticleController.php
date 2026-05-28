<?php

namespace App\Controller;

use App\Entity\Article;
use App\Http\PhpExecutionTime;
use App\Repository\ArticleHighlightRepository;
use App\Repository\ArticleRepository;
use App\Service\ArticleBodyHtmlRenderer;
use App\Service\MagazineContentService;
use App\Enum\KindsEnum;
use App\Nostr\Nip10Kind1ArticleReplyTags;
use App\Nostr\Nip22CommentTags;
use App\Form\EditorType;
use App\Service\ArticleCommentThreadLoader;
use App\Service\NostrClient;
use App\Service\NostrKeyHelper;
use App\Service\CacheService;
use App\Nostr\Nip19Codec;
use App\Util\CommonMark\Converter;
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
            $cached = $loader->tryLoadFromCacheOnly($coordinate, $articleEventId);
            if ($cached === null) {
                // Cache miss — return an empty shell; the full relay fetch is already in flight.
                // The article template already shows "Loading comments…" as the initial DOM state,
                // so there is no need to repeat it here.
                return new Response('<div class="comments" data-comments-partial="1"></div>', Response::HTTP_OK, $headers);
            }
            try {
                $data = $this->enrichCommentDataWithReplyContext($cached, $coordinate, $articleEventId, $articleTitle);

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
            $data = $loader->load($coordinate, $articleEventId);
            $data = $this->enrichCommentDataWithReplyContext(
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

    /**
     * Adds `comment_reply_context` for the reply composer (same data as the HTML fragment, used for full-page SSR when cache hits).
     *
     * @param array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>
     * } $data
     *
     * @return array{
     *     list: array<int, object>,
     *     quotes: array<int, object>,
     *     commentLinks: array<string, array<int, mixed>>,
     *     quoteLinks: array<string, array<int, mixed>>,
     *     processedContent: array<string, string>,
     *     comment_reply_context: array{
     *         can_publish: bool,
     *         coordinate: string,
     *         article_event_id: ?string,
     *         parent_kind: int,
     *         rows: array<int, array<string, mixed>>,
     *         fragment_url: string
     *     }
     * }
     */
    private function enrichCommentDataWithReplyContext(
        array $data,
        string $coordinate,
        ?string $articleEventId,
        string $articleTitle
    ): array {
        $coordparts = explode(':', $coordinate, 3);
        $articleKind = ctype_digit($coordparts[0]) ? (int) $coordparts[0] : 30023;
        $articleAuthorPubkey = strtolower(trim((string) ($coordparts[1] ?? '')));

        $articleReplyTags = null;
        if ($articleAuthorPubkey !== '' && 64 === \strlen($articleAuthorPubkey) && ctype_xdigit($articleAuthorPubkey)) {
            $articleReplyTags = Nip22CommentTags::forReplyToArticle($coordinate, $articleAuthorPubkey);
        }

        $parentIdForNaddr = str_repeat('0', 64);
        if ($articleEventId !== null && 64 === \strlen($articleEventId) && ctype_xdigit($articleEventId)) {
            $articleParentId = $articleEventId;
        } else {
            $articleParentId = $parentIdForNaddr;
        }

        $threadReplyRows = [];
        $userMayReply = $this->isGranted('ROLE_USER');
        if ($userMayReply && $articleReplyTags !== null) {
            $threadReplyRows[] = [
                'mode' => 'article',
                'blurbLabel' => $articleTitle !== '' ? $articleTitle : 'Article',
                'parentKind' => $articleKind,
                'parentId' => $articleParentId,
                'authorPubkey' => $articleAuthorPubkey,
                'expectedTags' => $articleReplyTags,
            ];
        }

        if ($userMayReply) {
            /** @var array<int, object> $list */
            $list = $data['list'];
            foreach ($list as $row) {
                $k = (int) ($row->kind ?? 0);
                if ($k !== KindsEnum::COMMENTS->value && $k !== KindsEnum::TEXT_NOTE->value) {
                    continue;
                }
                $cid = strtolower(trim((string) ($row->id ?? '')));
                $cpk = strtolower(trim((string) ($row->pubkey ?? '')));
                if ($cid === '' || 64 !== \strlen($cid) || !ctype_xdigit($cid)) {
                    continue;
                }
                if ($cpk === '' || 64 !== \strlen($cpk) || !ctype_xdigit($cpk)) {
                    continue;
                }
                $rawTags = json_decode(json_encode($row->tags ?? []), true);
                if (!\is_array($rawTags)) {
                    $rawTags = [];
                }
                $forSnippet = (string) ($row->unfold_body ?? $row->content ?? '');
                $snippet = trim($forSnippet);
                if (strlen($snippet) > 120) {
                    $snippet = substr($snippet, 0, 117).'…';
                }
                if ($snippet === '') {
                    $snippet = 'Comment';
                }
                try {
                    if ($k === KindsEnum::COMMENTS->value) {
                        $expectedTags = Nip22CommentTags::forReplyToComment($cid, $cpk, $k, $rawTags);
                    } else {
                        $expectedTags = Nip10Kind1ArticleReplyTags::forReplyToKind1(
                            $cid,
                            $cpk,
                            $rawTags,
                            $coordinate,
                            $articleEventId
                        );
                    }
                } catch (\Throwable) {
                    continue;
                }
                $threadReplyRows[] = [
                    'mode' => 'comment',
                    'blurbLabel' => $snippet,
                    'parentKind' => $k,
                    'parentId' => $cid,
                    'authorPubkey' => $cpk,
                    'expectedTags' => $expectedTags,
                ];
            }
        }

        $fragmentQuery = ['coordinate' => $coordinate, 'title' => $articleTitle];
        if ($articleEventId !== null) {
            $fragmentQuery['e'] = $articleEventId;
        }
        $data['comment_reply_context'] = [
            'can_publish' => $userMayReply,
            'coordinate' => $coordinate,
            'article_event_id' => $articleEventId,
            'parent_kind' => $articleKind,
            'rows' => $threadReplyRows,
            'fragment_url' => $this->generateUrl('article_comments_fragment', $fragmentQuery),
        ];

        return $data;
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
        $commentReplyContext = $this->buildArticleReplyContext($coordinate, $eid, $articleTitle);
        $cached = $commentThreadLoader->tryLoadFromCacheOnly($coordinate, $eid);
        if (null !== $cached) {
            $commentsData = $this->enrichCommentDataWithReplyContext(
                $cached,
                $coordinate,
                $eid,
                $articleTitle
            );
            $commentReplyContext = $commentsData['comment_reply_context'];
            $commentsPreloaded = true;
        }

        return $this->render('pages/article.html.twig', \array_merge(
            $this->sidebarLayoutData(),
            [
                'article' => $article,
                'author' => $author,
                'npub' => $npub,
                'content' => $html,
                'comments_data' => $commentsData,
                'comments_preloaded' => $commentsPreloaded,
                'comment_reply_context' => $commentReplyContext,
            ],
        ));
    }

    /**
     * Same left/right widgets as the home page (magazine recent list + kind-9802 highlights).
     *
     * @return array{sidebar_category_recent: list<\App\Dto\FeaturedArticleCard>, sidebar_highlights: list<\App\Entity\ArticleHighlight>}
     */
    private function sidebarLayoutData(): array
    {
        $categoryATags = $this->magazineContent->getHomeCategoryAIndexTagsFromStoreOnly();
        $curatedSlugs = $this->magazineContent->collectCuratedArticleSlugsForTenant($categoryATags);

        return [
            'sidebar_category_recent' => $this->magazineContent->buildHomeSidebarCategorizedRecent($categoryATags),
            'sidebar_highlights' => $this->articleHighlightRepository->findRecentForHome(100, $curatedSlugs),
        ];
    }

    /**
     * Base article-level reply context so the top "Reply" button can render before async comments load.
     *
     * @return array{
     *     can_publish: bool,
     *     coordinate: string,
     *     article_event_id: ?string,
     *     parent_kind: int,
     *     rows: array<int, array<string, mixed>>,
     *     fragment_url: string
     * }
     */
    private function buildArticleReplyContext(string $coordinate, ?string $articleEventId, string $articleTitle): array
    {
        $base = [
            'list' => [],
            'quotes' => [],
            'commentLinks' => [],
            'quoteLinks' => [],
            'processedContent' => [],
        ];
        $enriched = $this->enrichCommentDataWithReplyContext($base, $coordinate, $articleEventId, $articleTitle);

        return $enriched['comment_reply_context'];
    }

    /**
     * Fetch complete event to show as preview
     * POST data contains an object with request params
     */
    #[Route('/preview/', name: 'article-preview-event', methods: ['POST'])]
    public function articlePreviewEvent(
        Request $request,
        NostrClient $nostrClient,
        CacheService $cacheService,
        NostrKeyHelper $nostrKeyHelper,
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

        $html = '';

        try {
            if ($descriptor->type === 'nprofile') {
                if (!isset($descriptor->decoded) || !\is_string($descriptor->decoded)) {
                    $html = '<span class="text-subtle">Profile preview unavailable.</span>';
                } else {
                    $hint = json_decode($descriptor->decoded);
                    if (!\is_object($hint) || !isset($hint->pubkey)) {
                        $html = '<span class="text-subtle">Profile preview unavailable.</span>';
                    } else {
                        $npub = $nostrKeyHelper->convertPublicKeyToBech32($hint->pubkey);
                        $metadata = $cacheService->getMetadata($npub);
                        $metadata->npub = $npub;
                        $metadata->pubkey = $hint->pubkey;
                        $metadata->type = 'nprofile';
                        $html = $this->renderView('components/Molecules/NostrPreviewContent.html.twig', [
                            'preview' => $metadata,
                        ]);
                    }
                }
            } elseif (!isset($descriptor->decoded)) {
                $html = '<span class="text-subtle">Preview unavailable (missing data).</span>';
            } else {
                try {
                    $previewData = $nostrClient->getEventFromDescriptor($descriptor);
                } catch (\Throwable $e) {
                    $previewData = null;
                    $html = '<span class="text-subtle">Error fetching preview: '.htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
                }
                if ($html === '' && $previewData === null) {
                    $html = '<span class="text-subtle">No event found on the default relay for this preview.</span>';
                } elseif ($html === '') {
                    $previewData->type = $descriptor->type;
                    if (isset($descriptor->identifier) && \is_string($descriptor->identifier)) {
                        $previewData->identifier = $descriptor->identifier;
                    }
                    $html = $this->renderView('components/Molecules/NostrPreviewContent.html.twig', [
                        'preview' => $previewData,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $html = '<span class="text-subtle">Preview error: '.htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
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
