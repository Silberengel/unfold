<?php

declare(strict_types=1);

namespace App\Controller;

use App\Nostr\Nip19Codec;
use App\Service\NostrClient;
use App\Service\NostrLinkParser;
use App\Service\NostrShareMenuBuilder;
use App\Service\CacheService;
use App\Service\NostrKeyHelper;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class EventController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/e/{nevent}', name: 'nevent', requirements: ['nevent' => '^nevent1.*'])]
    public function index(
        $nevent,
        Request $request,
        Nip19Codec $nip19,
        NostrClient $nostrClient,
        CacheService $cacheService,
        NostrLinkParser $nostrLinkParser,
        NostrShareMenuBuilder $nostrShareMenuBuilder,
        NostrKeyHelper $nostrKeyHelper,
        LoggerInterface $logger,
    ): Response {
        $logger->info('Accessing event page', ['nevent' => $nevent]);

        try {
            // Decode nevent - nevent1... is a NIP-19 encoded event identifier
            $decoded = $nip19->decode($nevent);
            $logger->info('Decoded event', ['decoded' => json_encode($decoded)]);

            $data = $decoded->data;
            $logger->info('Event data', ['data' => json_encode($data)]);

            $relays = [];
            // Sort which event type this is using $data->type
            switch ($decoded->type) {
                case 'note':
                    $eventHex = (string) ($data->data ?? '');
                    $relays = $data->relays ?? [];
                    if (!\is_array($relays)) {
                        $relays = [];
                    }
                    $event = $nostrClient->getEventById($eventHex, $relays);
                    break;

                case 'nprofile':
                    // Redirect to author profile if it's a profile identifier
                    $logger->info('Redirecting to author profile', ['pubkey' => $data->pubkey]);
                    return $this->redirectToRoute('author-redirect', ['pubkey' => $data->pubkey]);

                case 'nevent':
                    // Handle nevent identifier (event with additional metadata)
                    $relays = $data->relays ?? [];
                    if (!\is_array($relays)) {
                        $relays = [];
                    }
                    $event = $nostrClient->getEventById($data->id, $relays);
                    break;

                case 'naddr':
                    // Handle naddr (parameterized replaceable event)
                    $relays = $data->relays ?? [];
                    if (!\is_array($relays)) {
                        $relays = [];
                    }
                    $decodedData = [
                        'kind' => $data->kind,
                        'pubkey' => $data->pubkey,
                        'identifier' => $data->identifier,
                        'relays' => $relays,
                    ];
                    $event = $nostrClient->getEventByNaddr($decodedData);
                    break;

                default:
                    $logger->error('Unsupported event type', ['type' => $decoded->type]);
                    throw new NotFoundHttpException('Unsupported event type: ' . $decoded->type);
            }

            if (!$event) {
                $logger->warning('Event not found', ['data' => $data]);
                throw new NotFoundHttpException('Event not found');
            }

            $nostrShareMenuBuilder->applyWireEventToRequest($request, $event, $relays);

            // Parse event content for Nostr links
            $nostrLinks = [];
            if (isset($event->content)) {
                $nostrLinks = $nostrLinkParser->parseLinks($event->content);
                $logger->info('Parsed Nostr links from content', ['count' => count($nostrLinks)]);
            }

            // If author is included in the event, get metadata
            $authorMetadata = null;
            if (isset($event->pubkey)) {
                $npub = $nostrKeyHelper->convertPublicKeyToBech32($event->pubkey);
                $authorMetadata = $cacheService->getMetadata($npub);
            }

            // Render template with the event data and extracted Nostr links
            return $this->render('event/index.html.twig', [
                'event' => $event,
                'author' => $authorMetadata,
                'nostrLinks' => $nostrLinks
            ]);

        } catch (Exception $e) {
            $logger->error('Error processing event', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
