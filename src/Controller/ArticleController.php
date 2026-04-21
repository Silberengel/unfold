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
    public function commentsFragment(Request $request, ArticleCommentThreadLoader $loader): Response
    {
        $coordinate = $request->query->getString('coordinate');
        if ($coordinate === '' || !self::isValidNostrCoordinate($coordinate)) {
            return new Response('Invalid coordinate', Response::HTTP_BAD_REQUEST);
        }

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, max-age=60',
        ];

        try {
            $data = $loader->load($coordinate);

            return $this->render('components/Organisms/Comments.html.twig', $data, new Response(
                '',
                Response::HTTP_OK,
                $headers
            ));
        } catch (\Throwable) {
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
        // descriptor is an object with properties type, identifier and data
        // if type === 'nevent', identifier is the event id
        // if type === 'naddr', identifier is the naddr
        // if type === 'nprofile', identifier is the npub
        $descriptor = json_decode($data);
        $previewData = [];

        // if nprofile, get from redis cache
        if ($descriptor->type === 'nprofile') {
            $hint = json_decode($descriptor->decoded);
            $key = new Key();
            $npub = $key->convertPublicKeyToBech32($hint->pubkey);
            $metadata = $cacheService->getMetadata($npub);
            $metadata->npub = $npub;
            $metadata->pubkey = $hint->pubkey;
            $metadata->type = 'nprofile';
            // Render the NostrPreviewContent component with the preview data
            $html = $this->renderView('components/Molecules/NostrPreviewContent.html.twig', [
                'preview' => $metadata
            ]);
        } else {
            // For nevent or naddr, fetch the event data
            try {
                $previewData = $nostrClient->getEventFromDescriptor($descriptor);
                $previewData->type = $descriptor->type; // Add type to the preview data
                // Render the NostrPreviewContent component with the preview data
                $html = $this->renderView('components/Molecules/NostrPreviewContent.html.twig', [
                    'preview' => $previewData
                ]);
            } catch (\Exception $e) {
                $html = '<span>Error fetching preview: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
        }


        return new Response(
            $html,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html']
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
        ]);
    }

}
