<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\PhpExecutionTime;
use App\Repository\ArticleRepository;
use App\Repository\FeaturedAuthorRepository;
use App\Service\CacheService;
use App\Service\Nip05VerificationService;
use App\Service\NostrClient;
use App\Service\NostrKeyHelper;
use App\Service\ProfileIdentityLinksBuilder;
use App\Service\ProfilePaymentLinksBuilder;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthorController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/p/{npub}', name: 'author-profile', requirements: ['npub' => '^npub1.*'])]
    public function index(
        Request $request,
        $npub,
        NostrClient $nostrClient,
        CacheService $cacheService,
        ArticleRepository $articleRepository,
        FeaturedAuthorRepository $featuredAuthorRepository,
        Nip05VerificationService $nip05Verification,
        ProfilePaymentLinksBuilder $profilePaymentLinks,
        ProfileIdentityLinksBuilder $profileIdentityLinks,
        NostrKeyHelper $nostrKeyHelper,
    ): Response {
        // Profile pages chain several sequential Nostr REQ runs; cap wall time so Apache workers are not held for minutes.
        @set_time_limit(PhpExecutionTime::NOSTR_BOUND_WEB_SEC);
        @ini_set('max_execution_time', (string) PhpExecutionTime::NOSTR_BOUND_WEB_SEC);

        $pubkey = $nostrKeyHelper->convertToHex($npub);

        $bundle = $cacheService->getMetadataBundle($npub);
        $author = $bundle['content'];
        $kind0Tags = $bundle['kind0_tags'];
        $nip30Emojis = $bundle['nip30_custom_emojis'] ?? [];
        $perPage = 25;
        $page = max(1, $request->query->getInt('page', 1));
        $total = $articleRepository->countByPubkey($pubkey);
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }
        $offset = ($page - 1) * $perPage;
        $articles = $articleRepository->findByPubkeyPaginated($pubkey, $perPage, $offset);

        $kind10133 = [];
        try {
            $kind10133 = $nostrClient->getKind10133PaymentTargetEventsForNpub($npub, 20);
        } catch (Exception) {
        }
        $extraPayto = $profilePaymentLinks->collectPaytoUrisFromNipA3Kind10133Events($kind10133);

        $profileNip05 = $profileIdentityLinks->buildNip05($author, $kind0Tags);
        $fa = $featuredAuthorRepository->findOneByPubkeyHex($pubkey);
        if ($fa !== null && $fa->isListed()) {
            $nipDomain = trim((string) $this->getParameter('nip05_domain'));
            $siteNip = $fa->getLocalPart().($nipDomain !== '' ? '@'.$nipDomain : '');
            $profileNip05 = $profileIdentityLinks->mergeSiteNip05IntoList($profileNip05, $siteNip);
        }
        $profileNip05 = $nip05Verification->enrichRowsWithCache($pubkey, $profileNip05);

        return $this->render('pages/author.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'articles' => $articles,
            'is_author_profile' => true,
            'profile_websites' => $profileIdentityLinks->buildWebsites($author, $kind0Tags),
            'profile_nip05' => $profileNip05,
            'profile_nip30_emojis' => $nip30Emojis,
            'profile_payment_links' => $profilePaymentLinks->buildPaymentRows($author, $kind0Tags, $extraPayto),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/p/{pubkey}', name: 'author-redirect')]
    public function authorRedirect($pubkey, NostrKeyHelper $nostrKeyHelper): Response
    {
        $npub = $nostrKeyHelper->convertPublicKeyToBech32($pubkey);

        return $this->redirectToRoute('author-profile', ['npub' => $npub]);
    }
}
