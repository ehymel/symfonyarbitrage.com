<?php

namespace App\Controller\Security;

use App\Repository\UserRepository;
use App\Repository\WebauthnCredentialRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login/check-email', name: 'app_login_check_email', methods: ['POST'])]
    public function checkEmail(Request $request, UserRepository $userRepository, WebauthnCredentialRepository $credentialRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';

        if (!$email) {
            return new JsonResponse(['hasPasskey' => false]);
        }

        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            // If the user doesn't exist, gracefully fallback to the password UI
            // This prevents user enumeration attacks (hackers guessing valid email addresses)
            return new JsonResponse(['hasPasskey' => false]);
        }

        // Check if this specific user has any credentials saved in the database
        $credentials = $credentialRepository->findBy([
            'userHandle' => $user->getUserIdentifier()
        ]);

        return new JsonResponse([
            'hasPasskey' => count($credentials) > 0
        ]);
    }

    #[Route(path: '/login', name: 'security_login')]
    public function login(TokenStorageInterface $tokenStorage, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return $this->redirectToRoute('login_redirect');
        }

        // otherwise, force logout of previous user
        $tokenStorage->setToken(null);

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last email entered by the user
        $lastEmail = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_email' => $lastEmail,
            'error' => $error,
        ]);
    }

    #[Route(path: '/login/redirect', name: 'login_redirect')]
    public function loginRedirect(): RedirectResponse
    {
        // Send user to admin page or order page based on credentials
        if ($this->isGranted('ROLE_SUPERUSER')) {
            return $this->redirectToRoute('admin_tenant_list');
        } elseif ($this->isGranted('ROLE_USER')) {
            return $this->redirectToRoute('internal_documents_dashboard');
        }

        return $this->redirectToRoute('homepage');
    }

    #[Route(path: '/logout', name: 'security_logout')]
    public function logout()
    {
        // never called, so nothing needed here.
    }
}
