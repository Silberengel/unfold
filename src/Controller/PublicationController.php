<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\PhpExecutionTime;
use App\Nostr\Nip19Codec;
use App\Service\NostrKeyHelper;
use App\Service\PublicationFeature;
use App\Service\PublicationIndexStore;
use App\Service\PublicationReaderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicationController extends AbstractController
{
    public function __construct(
        private readonly PublicationFeature $publicationFeature,
        private readonly PublicationReaderService $reader,
        private readonly PublicationIndexStore $publicationIndexStore,
        private readonly NostrKeyHelper $nostrKeyHelper,
    ) {
    }

    #[Route('/publications', name: 'publications', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if (!$this->publicationFeature->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $perPage = 25;
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
        requirements: ['npub' => '^npub1.*', 'slug' => '.+'],
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

        $this->reader->ensurePublicationTreeWarm($index, $npub, $slug);

        $sectionCoord = $request->query->getString('section');
        $sectionHtml = null;
        if ($sectionCoord !== '') {
            $sectionHtml = $this->reader->renderSectionHtml($sectionCoord);
        }

        return $this->render('pages/publication.html.twig', [
            'index' => $index,
            'npub' => $npub,
            'slug' => $slug,
            'title' => $this->reader->titleFromIndex($index),
            'summary' => $this->reader->summaryFromIndex($index),
            'image' => $this->reader->imageFromIndex($index),
            'toc' => $this->reader->buildToc($index),
            'section_coordinate' => $sectionCoord,
            'section_html' => $sectionHtml,
        ]);
    }
}
