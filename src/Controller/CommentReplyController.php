<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArticleCommentThreadLoader;
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
     * Accepts a NIP-07–signed kind-1111 (NIP-22) or kind-1 (NIP-10) article-thread event (JSON) and publishes it to configured relays.
     *
     * @see \App\Service\CommentReplyService
     */
    #[Route('/comment/publish', name: 'comment_reply_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publish(Request $request, CommentReplyService $commentReply, ArticleCommentThreadLoader $commentThreadLoader): JsonResponse
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
            $coord = $data['expected_coordinate'] ?? null;
            $merged = false;
            if (\is_string($coord) && $coord !== '') {
                $eid = isset($data['article_event_id']) && \is_string($data['article_event_id']) && $data['article_event_id'] !== '' ? $data['article_event_id'] : null;
                $articleEventHex = 64 === \strlen((string) $eid) && ctype_xdigit((string) $eid) ? $eid : null;
                $rawEvent = $data['event'] ?? null;
                if (\is_array($rawEvent)) {
                    $merged = $commentThreadLoader->mergePublishedThreadEvent($coord, $articleEventHex, $rawEvent);
                }
                if (!$merged) {
                    $commentThreadLoader->invalidateThread($coord, $articleEventHex);
                }
            }

            return $this->json([
                'ok' => true,
                'id' => $out['id'],
                'ok_relays' => $out['ok_relays'],
                'total_relays' => $out['total_relays'],
                'merged' => $merged,
            ]);
        }

        /** @var array{ok: false, error: string, code: int} $out */
        return $this->json(['ok' => false, 'error' => $out['error']], $out['code']);
    }
}
