<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FeaturedAuthorRepository;
use App\Service\CacheService;
use App\Service\NostrClient;
use App\Service\ProfileIdentityLinksBuilder;
use App\Service\ProfilePaymentLinksBuilder;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the site-managed NIP-05 list of magazine category authors.
 */
final class FeaturedAuthorsController extends AbstractController
{
    #[Route('/featured-authors', name: 'featured_authors', methods: ['GET'])]
    public function index(
        FeaturedAuthorRepository $featuredAuthorRepository,
        CacheService $cacheService,
        NostrClient $nostrClient,
        ProfileIdentityLinksBuilder $profileIdentityLinks,
        ProfilePaymentLinksBuilder $profilePaymentLinks,
        ParameterBagInterface $params,
    ): Response {
        $domain = trim((string) $params->get('nip05_domain'));
        $keys = new Key();
        $authors = [];
        foreach ($featuredAuthorRepository->findAllListedOrderByLocalPart() as $fa) {
            $npub = $keys->convertPublicKeyToBech32($fa->getPubkeyHex());
            $bundle = $cacheService->getMetadataBundle($npub);
            $author = $bundle['content'];
            $kind0Tags = $bundle['kind0_tags'];
            $kind10133 = [];
            try {
                $kind10133 = $nostrClient->getKind10133PaymentTargetEventsForNpub($npub, 20);
            } catch (\Throwable) {
            }
            $extraPayto = $profilePaymentLinks->collectPaytoUrisFromNipA3Kind10133Events($kind10133);
            $authors[] = [
                'author' => $author,
                'npub' => $npub,
                'profile_websites' => $profileIdentityLinks->buildWebsites($author, $kind0Tags),
                'profile_payment_links' => $profilePaymentLinks->buildPaymentRows($author, $kind0Tags, $extraPayto),
            ];
        }

        return $this->render('pages/featured_authors.html.twig', [
            'authors' => $authors,
            'nip05_domain' => $domain,
        ]);
    }
}
