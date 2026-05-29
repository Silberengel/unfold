<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\PhpExecutionTime;
use App\Nostr\Nip19Codec;
use App\Repository\ArticleHighlightRepository;
use App\Service\ArticleCommentReplyContextBuilder;
use App\Service\ArticleCommentThreadLoader;
use App\Service\NostrKeyHelper;
use App\Service\PublicationFeature;
use App\Service\PublicationIndexMetadataBuilder;
use App\Service\PublicationIndexStore;
use App\Service\PublicationExportService;
use App\Service\PublicationExportException;
use App\Service\PublicationReaderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicationController extends AbstractController
{
    public function __construct(
        private readonly PublicationFeature $publicationFeature,
        private readonly PublicationReaderService $reader,
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly PublicationExportService $export,
        private readonly NostrKeyHelper $nostrKeyHelper,
        private readonly ArticleHighlightRepository $articleHighlightRepository,
        private readonly ArticleCommentThreadLoader $commentThreadLoader,
        private readonly ArticleCommentReplyContextBuilder $commentReplyContextBuilder,
        private readonly PublicationIndexMetadataBuilder $publicationIndexMetadataBuilder,
    ) {
    }

    #[Route('/publications', name: 'publications', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if (!$this->publicationFeature->isEnabled()) {
            throw $this->createNotFoundException();
        }

        // Even count so the two-column publications grid never leaves a lone card on the last row.
        $perPage = 24;
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->publicationIndexStore->countPublicationIndices();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }
        $offset = ($page - 1) * $perPage;
        $indices = $this->publicationIndexStore->findNewestPaginated($perPage, $offset);

        return $this->render('pages/publications.html.twig', [
            'indices' => $indices,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    #[Route('/publication/naddr/{naddr}', name: 'publication-naddr', requirements: ['naddr' => 'naddr1.+'])]
    public function naddrRedirect(string $naddr, Nip19Codec $nip19): Response
    {
        if (!$this->publicationFeature->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $decoded = $nip19->decode($naddr);
        if ($decoded->type !== 'naddr') {
            throw $this->createNotFoundException();
        }

        $data = $decoded->data;
        $npub = $this->nostrKeyHelper->convertPublicKeyToBech32((string) $data->pubkey);
        $slug = (string) ($data->identifier ?? '');

        return $this->redirectToRoute('publication', ['npub' => $npub, 'slug' => $slug], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(
        '/publication/p/{npub}/d/{slug}',
        name: 'publication',
        requirements: ['npub' => '^npub1.*', 'slug' => '[^/]+'],
        options: ['utf8' => true],
    )]
    public function reader(Request $request, string $npub, string $slug): Response
    {
        if (!$this->publicationFeature->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $t = PhpExecutionTime::LIGHT_WEB_SEC;
        set_time_limit($t);

        $index = $this->reader->resolveRootIndex($npub, $slug);
        if ($index === null) {
            throw new NotFoundHttpException('Publication not found');
        }

        $viewMode = $request->query->getString('view');
        if ($viewMode === 'full') {
            @set_time_limit(120);
            @ini_set('max_execution_time', '120');
            $this->reader->ensurePublicationTreeWarmForExport($index);
        } else {
            $this->reader->ensurePublicationTreeWarm($index, $npub, $slug);
        }

        $sectionCoord = $request->query->getString('section');
        $sectionHtml = null;
        $fullHtml = null;
        if ($viewMode === 'full') {
            $fullHtml = $this->reader->renderFullPublicationHtml($index);
        } elseif ($sectionCoord !== '') {
            $sectionHtml = $this->reader->renderSectionHtml($sectionCoord);
        }

        $viewModeResolved = $viewMode === 'full' ? 'full' : 'section';
        $publicationTitle = $this->reader->titleFromIndex($index);
        $publicationCoordinate = '30040:'.strtolower((string) $index->getPubkey()).':'.$slug;
        $publicationEventId = $index->getEventId() ?? '';
        if ($publicationEventId === '') {
            $publicationEventId = $index->getId();
        }
        $publicationEventId = self::isValidHexEventId($publicationEventId) ? strtolower($publicationEventId) : null;

        $sidebarHighlights = [];
        $commentsData = null;
        $commentsPreloaded = false;
        $commentReplyContext = $this->commentReplyContextBuilder->buildArticleReplyContext(
            $publicationCoordinate,
            $publicationEventId,
            $publicationTitle,
        );

        if ($viewModeResolved === 'section') {
            $sectionCoordinates = $this->reader->collectSectionCoordinates($index);
            $sidebarHighlights = $this->articleHighlightRepository->findForPublicationSections($sectionCoordinates);

            $cachedComments = $this->commentThreadLoader->tryLoadFromCacheOnly($publicationCoordinate, $publicationEventId);
            if ($cachedComments !== null) {
                $commentsData = $this->commentReplyContextBuilder->enrich(
                    $cachedComments,
                    $publicationCoordinate,
                    $publicationEventId,
                    $publicationTitle,
                );
                $commentReplyContext = $commentsData['comment_reply_context'];
                $commentsPreloaded = true;
            }
        }

        return $this->render('pages/publication.html.twig', [
            'index' => $index,
            'npub' => $npub,
            'slug' => $slug,
            'title' => $publicationTitle,
            'summary' => $this->reader->summaryFromIndex($index),
            'publication_meta' => $this->publicationIndexMetadataBuilder->build($index),
            'image' => $this->reader->imageFromIndex($index),
            'toc' => $this->reader->buildToc($index),
            'view_mode' => $viewModeResolved,
            'section_coordinate' => $sectionCoord,
            'section_html' => $sectionHtml,
            'full_html' => $fullHtml,
            'export_available' => $this->export->isAvailable(),
            'export_formats' => $this->export->supportedFormats(),
            'sidebar_highlights' => $sidebarHighlights,
            'publication_coordinate' => $publicationCoordinate,
            'publication_event_id' => $publicationEventId,
            'comments_data' => $commentsData,
            'comments_preloaded' => $commentsPreloaded,
            'comment_reply_context' => $commentReplyContext,
        ]);
    }

    private static function isValidHexEventId(string $id): bool
    {
        return \strlen($id) === 64 && ctype_xdigit($id);
    }

    #[Route(
        '/publication/p/{npub}/d/{slug}/download',
        name: 'publication-download',
        requirements: ['npub' => '^npub1.*', 'slug' => '[^/]+'],
        options: ['utf8' => true],
        methods: ['GET'],
        priority: 10,
    )]
    public function download(Request $request, string $npub, string $slug): Response
    {
        if (!$this->publicationFeature->isEnabled()) {
            throw $this->createNotFoundException();
        }
        $format = $request->query->getString('format', 'epub3');

        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        try {
            if (\in_array($format, ['asciidoc', 'adoc'], true)) {
                $file = $this->export->exportAsciidoc($npub, $slug);
            } else {
                if (!$this->export->isAvailable()) {
                    throw new ServiceUnavailableHttpException(null, 'Publication export is not configured.');
                }
                $file = $this->export->export($npub, $slug, $format);
            }
        } catch (PublicationExportException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailableHttpException(null, $e->getMessage(), $e);
        }

        $response = new Response($file['body']);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file['filename'],
        );
        $response->headers->set('Content-Type', $file['mimeType']);
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Length', (string) \strlen($file['body']));

        return $response;
    }
}
