<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Form\RoleType;
use App\Repository\UserEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class RoleController extends AbstractController
{
    #[Route('/admin/role', name: 'admin_roles')]
    public function index(): Response
    {
        $form = $this->createForm(RoleType::class);

        return $this->render('admin/roles.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Add a role to current user as submitted in a form
     */
    #[Route('/admin/role/add', name: 'admin_roles_add')]
    public function addRole(Request $request, UserEntityRepository $userRepository, EntityManagerInterface $em, TokenStorageInterface $tokenStorage): Response
    {
        // get role from request and add to current user's roles and save to db
        $npub = $this->getUser()->getUserIdentifier();

        $form = $this->createForm(RoleType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/roles.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        $role = $form->get('role')->getData();
        $user = $userRepository->findOneByNpub($npub);
        $user->addRole($role);
        $em->persist($user);
        $em->flush();

        // regenerate token with new roles
        // Refresh the user token after update
        $token = $tokenStorage->getToken();
        if ($token) {
            $token->setUser($user);
            $tokenStorage->setToken($token);
        }

        // add a flash message
        $this->addFlash('success', 'Role added to user');

        return $this->render('admin/roles.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
