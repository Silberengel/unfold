<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness: no DB or Nostr work. Used by Docker / load balancers; do not use for deep dependency checks.
 */
final class HealthController
{
    public const string BODY = "ok\n";

    #[Route('/health', name: 'health', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request): Response
    {
        $headers = [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ];

        if ($request->isMethod('HEAD')) {
            return new Response('', Response::HTTP_OK, $headers);
        }

        return new Response(self::BODY, Response::HTTP_OK, $headers);
    }
}
