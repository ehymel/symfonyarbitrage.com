<?php

namespace App\Controller\Security;

use App\Entity\User;
use App\Repository\WebauthnCredentialRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/passkey/', name: 'passkey_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PasskeyController extends AbstractController
{
    #[Route(path: 'manage', name: 'manage')]
    public function manage(WebauthnCredentialRepository $credentialRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Fetch all passkeys associated with this user's identifier
        $passkeys = $credentialRepository->findAllForUser($currentUser);

        return $this->render('security/passkey_manage.html.twig', [
            'user' => $currentUser,
            'passkeys' => $passkeys,
        ]);
    }

    #[Route(path: '/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(string $id, Request $request, WebauthnCredentialRepository $credentialRepository): Response {
        $passkey = $credentialRepository->find($id);
        $wantsJson = $request->getPreferredFormat() === 'json';

        // Security check: Ensure the passkey exists and belongs to the active user
        if ($passkey && $passkey->userHandle === $this->getUser()->getUserIdentifier()) {
            // Validate the CSRF token to prevent cross-site request forgery
            if ($this->isCsrfTokenValid('delete_passkey_'.$passkey->id, $request->request->get('_token'))) {
                $credentialRepository->remove($passkey, true);

                if ($wantsJson) {
                    return new JsonResponse(['success' => true]);
                }

                $this->addFlash('success', 'Passkey successfully removed.');
            } else {
                if ($wantsJson) {
                    return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
                }
            }
        }

        if ($wantsJson) {
            return new JsonResponse(['error' => 'Passkey not found or unauthorized'], 404);
        }

        return $this->redirectToRoute('passkey_manage');
    }
}
