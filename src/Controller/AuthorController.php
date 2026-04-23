<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Service\CacheService;
use App\Service\NostrClient;
use App\Service\ProfileIdentityLinksBuilder;
use App\Service\ProfilePaymentLinksBuilder;
use Exception;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthorController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/p/{npub}', name: 'author-profile', requirements: ['npub' => '^npub1.*'])]
    public function index(
        $npub,
        NostrClient $nostrClient,
        CacheService $cacheService,
        ArticleRepository $articleRepository,
        ProfilePaymentLinksBuilder $profilePaymentLinks,
        ProfileIdentityLinksBuilder $profileIdentityLinks,
    ): Response {
        $keys = new Key();
        $pubkey = $keys->convertToHex($npub);

        $bundle = $cacheService->getMetadataBundle($npub);
        $author = $bundle['content'];
        $kind0Tags = $bundle['kind0_tags'];
        // Retrieve long-form content for the author
        try {
            $list = $nostrClient->getLongFormContentForPubkey($npub);
        } catch (Exception $e) {
            $list = [];
        }

        // Also look for articles in the database by pubkey
        $dbArticles = $articleRepository->findByPubkey($pubkey, 25);
        $list = array_merge($list, $dbArticles);

        $articles = [];
        // Deduplicate by slugs
        foreach ($list as $item) {
            if (!key_exists((string) $item->getSlug(), $articles)) {
                $articles[(string) $item->getSlug()] = $item;
            }
        }

        // Sort articles by date
        usort($articles, function ($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        $kind10133 = [];
        try {
            $kind10133 = $nostrClient->getKind10133PaymentTargetEventsForNpub($npub, 20);
        } catch (Exception) {
        }
        $extraPayto = $profilePaymentLinks->collectPaytoUrisFromNipA3Kind10133Events($kind10133);

        $jumbleBase = (string) $this->getParameter('jumble_profile_users_base');
        $jumbleBase = rtrim($jumbleBase, '/');
        $jumbleProfileHref = $jumbleBase !== '' ? $jumbleBase.'/'.$npub : null;

        return $this->render('pages/author.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'articles' => $articles,
            'is_author_profile' => true,
            'profile_websites' => $profileIdentityLinks->buildWebsites($author, $kind0Tags),
            'profile_nip05' => $profileIdentityLinks->buildNip05($author, $kind0Tags),
            'profile_payment_links' => $profilePaymentLinks->buildPaymentRows($author, $kind0Tags, $extraPayto),
            'jumble_profile_href' => $jumbleProfileHref,
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/p/{pubkey}', name: 'author-redirect')]
    public function authorRedirect($pubkey): Response
    {
        $keys = new Key();
        $npub = $keys->convertPublicKeyToBech32($pubkey);

        return $this->redirectToRoute('author-profile', ['npub' => $npub]);
    }
}
