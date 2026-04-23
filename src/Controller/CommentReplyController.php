<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\CommentReplyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CommentReplyController extends AbstractController
{
    /**
     * Accepts a NIP-07–signed kind-1111 event (JSON) and publishes it to configured article relays.
     *
     * @see \App\Service\CommentReplyService
     */
    #[Route('/comment/publish', name: 'comment_reply_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publish(Request $request, CommentReplyService $commentReply): JsonResponse
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return $this->json(['ok' => false, 'error' => 'Empty body'], Response::HTTP_BAD_REQUEST);
        }
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $token = $data['csrf'] ?? $request->headers->get('X-CSRF-TOKEN') ?? '';
        if (!\is_string($token) || !$this->isCsrfTokenValid('comment_reply', $token)) {
            return $this->json(['ok' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['ok' => false, 'error' => 'Not logged in'], Response::HTTP_UNAUTHORIZED);
        }

        $out = $commentReply->publishFromRequestPayload($user, $data);
        if ($out['ok'] === true) {
            return $this->json(['ok' => true, 'id' => $out['id']]);
        }

        /** @var array{ok: false, error: string, code: int} $out */
        return $this->json(['ok' => false, 'error' => $out['error']], $out['code']);
    }
}
