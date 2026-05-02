<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MagazineHierarchyEditorService;
use App\Service\MagazineHierarchyPublishService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MagazineEditorController extends AbstractController
{
    private function assertMagazineOwner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $configured = (string) $this->getParameter('npub');
        $npub = (string) ($user->getNpub() ?? '');
        if ($npub === '' || !hash_equals($configured, $npub)) {
            throw $this->createAccessDeniedException('Only the configured magazine owner npub may use this editor.');
        }

        return $user;
    }

    #[Route('/magazine/edit', name: 'magazine_edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(MagazineHierarchyEditorService $editor, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $this->assertMagazineOwner();
        $payload = $editor->buildEditorPayload();

        return $this->render('pages/magazine_edit.html.twig', [
            'editor_payload' => $payload,
            'magazine_edit_csrf' => $csrfTokenManager->getToken('magazine_edit')->getValue(),
        ]);
    }

    #[Route('/magazine/edit/publish', name: 'magazine_edit_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publish(
        Request $request,
        MagazineHierarchyPublishService $publishService,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): JsonResponse {
        $user = $this->assertMagazineOwner();

        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON body'], 400);
        }
        if (!\is_array($data)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON body'], 400);
        }

        $token = isset($data['csrf']) && \is_string($data['csrf']) ? $data['csrf'] : '';
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('magazine_edit', $token))) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid CSRF token'], 400);
        }

        $events = $data['events'] ?? null;
        if (!\is_array($events)) {
            return new JsonResponse(['ok' => false, 'error' => 'Missing events array'], 400);
        }

        $result = $publishService->publishOwnerMagazineBatch($user, $events);
        if ($result['ok'] === true) {
            return new JsonResponse($result);
        }

        return new JsonResponse(
            ['ok' => false, 'error' => $result['error']],
            min(599, max(400, $result['code'])),
        );
    }
}
