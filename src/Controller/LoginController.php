<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
     public function index(#[CurrentUser] ?User $user): Response
    {
        if (null !== $user) {
            return new JsonResponse([
                'message' => 'Authentication Successful',
                'npub' => $user->getNpub(),
            ], 200);
        }

        return new JsonResponse([
            'message' => 'Unauthenticated',
        ], 401);
    }
}
