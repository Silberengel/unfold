<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FeaturedAuthorRepository;
use App\Service\CacheService;
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
        ProfileIdentityLinksBuilder $profileIdentityLinks,
        ProfilePaymentLinksBuilder $profilePaymentLinks,
        ParameterBagInterface $params,
    ): Response {
        $domain = trim((string) $params->get('nip05_domain'));
        $jumbleBase = rtrim((string) $params->get('jumble_profile_users_base'), '/');
        $keys = new Key();
        $authors = [];
        foreach ($featuredAuthorRepository->findAllListedOrderByLocalPart() as $fa) {
            $npub = $keys->convertPublicKeyToBech32($fa->getPubkeyHex());
            $bundle = $cacheService->getMetadataBundle($npub);
            $author = $bundle['content'];
            $kind0Tags = $bundle['kind0_tags'];
            $jumbleProfileHref = $jumbleBase !== '' ? $jumbleBase.'/'.$npub : null;
            $authors[] = [
                'author' => $author,
                'npub' => $npub,
                'profile_websites' => $profileIdentityLinks->buildWebsites($author, $kind0Tags),
                'profile_payment_links' => $profilePaymentLinks->buildPaymentRows($author, $kind0Tags, []),
                'jumble_profile_href' => $jumbleProfileHref,
            ];
        }

        return $this->render('pages/featured_authors.html.twig', [
            'authors' => $authors,
            'nip05_domain' => $domain,
        ]);
    }
}
