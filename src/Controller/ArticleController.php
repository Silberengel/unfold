<?php

namespace App\Controller;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Form\EditorType;
use App\Service\ArticleCommentThreadLoader;
use App\Service\NostrClient;
use App\Service\CacheService;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\Exception\CommonMarkException;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Workflow\WorkflowInterface;

class ArticleController  extends AbstractController
{
    /**
     * Lazy-loaded comment thread (HTML fragment for Stimulus). Must not live under /article/{naddr}.
     */
    #[Route('/fragment/comments', name: 'article_comments_fragment', methods: ['GET'])]
    public function commentsFragment(Request $request, ArticleCommentThreadLoader $loader, LoggerInterface $logger): Response
    {
        // Article body may raise the global limit; keep this sub-request bounded so relay I/O cannot hit max_execution_time (500).
        set_time_limit(45);

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

        $logger->info('http.fragment.comments_start', [
            'coordinate' => $coordinate,
            'article_event_hex' => $articleEventId,
        ]);

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, max-age=60',
        ];

        try {
            $data = $loader->load($coordinate, $articleEventId);
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
    public function naddr(NostrClient $nostrClient, $naddr)
    {
        $decoded = new Bech32($naddr);

        if ($decoded->type !== 'naddr') {
            throw new \Exception('Invalid naddr');
        }

        /** @var NAddr $data */
        $data = $decoded->data;
        $slug = $data->identifier;
        $relays = $data->relays;
        $author = $data->pubkey;
        $kind = (int) $data->kind;

        $allowedKinds = [KindsEnum::LONGFORM->value, KindsEnum::LONGFORM_DRAFT->value];
        if (!\in_array($kind, $allowedKinds, true)) {
            throw new \Exception('Not a long form article');
        }

        $nostrClient->getLongFormFromNaddr($slug, $relays, $author, $kind);
        if ($slug) {
            return $this->redirectToRoute('article-slug', ['slug' => $slug]);
        }

        throw new \Exception('No article.');
    }

    /**
     * @throws InvalidArgumentException|CommonMarkException
     */
    #[Route('/article/d/{slug}', name: 'article-slug')]
    public function article(
        $slug,
        EntityManagerInterface $entityManager,
        CacheService $cacheService,
        CacheItemPoolInterface $articlesCache,
        Converter $converter
    ): Response
    {

        set_time_limit(300); // 5 minutes
        ini_set('max_execution_time', '300');

        $article = null;
        // check if an item with same eventId already exists in the db
        $repository = $entityManager->getRepository(Article::class);
        $articles = $repository->findBy(['slug' => $slug]);
        $revisions = count($articles);

        if ($revisions === 0) {
            throw $this->createNotFoundException('The article could not be found');
        }

        if ($revisions > 1) {
            // sort articles by created at date
            usort($articles, function ($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
            // get the last article
            $article = end($articles);
        } else {
            $article = $articles[0];
        }

        $cacheKey = 'article_' . $article->getId();
        $cacheItem = $articlesCache->getItem($cacheKey);
        if (!$cacheItem->isHit()) {
            $cacheItem->set($converter->convertToHtml($article->getContent()));
            $articlesCache->save($cacheItem);
        }

        $key = new Key();
        $npub = $key->convertPublicKeyToBech32($article->getPubkey());
        $author = $cacheService->getMetadata($npub);


        return $this->render('pages/article.html.twig', [
            'article' => $article,
            'author' => $author,
            'npub' => $npub,
            'content' => $cacheItem->get(),
        ]);
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
        CacheItemPoolInterface $articlesCache
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
                        $key = new Key();
                        $npub = $key->convertPublicKeyToBech32($hint->pubkey);
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
                } elseif ($html === '' && \is_object($previewData)) {
                    $previewData->type = $descriptor->type;
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
                               WorkflowInterface $articlePublishingWorkflow, Article $article = null): Response
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
            $key = new Key();
            $currentPubkey = $key->convertToHex($user->getUserIdentifier());

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
                            CacheItemPoolInterface $articlesCache): Response
    {
        $user = $this->getUser();
        $key = new Key();
        $currentPubkey = $key->convertToHex($user->getUserIdentifier());

        $cacheKey = 'article_' . $currentPubkey . '_' . $d;
        $cacheItem = $articlesCache->getItem($cacheKey);
        $article = $cacheItem->get();

        $content = $converter->convertToHtml($article->getContent());

        return $this->render('pages/article.html.twig', [
            'article' => $article,
            'content' => $content,
            'author' => $user->getMetadata(),
        ]);
    }

    /**
     * Display latest 20 community articles
     */
    #[Route('/articles', name: 'articles')]
    public function latestArticles(EntityManagerInterface $entityManager): Response
    {
        set_time_limit(300); // 5 minutes
        ini_set('max_execution_time', '300');

        $articles = $entityManager->getRepository(Article::class)
            ->findBy([], ['createdAt' => 'DESC'], 20);

        $category = (object) [
            'title' => 'Community Articles',
            'summary' => 'Latest articles from the community',
        ];

        return $this->render('pages/category.html.twig', [
            'category' => $category,
            'list' => $articles,
            'sync_slug' => '',
        ]);
    }

}
