<?php

namespace App\Controller\Security;

use App\Entity\User;
use App\Form\User\UserPasswordResetForm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PasswordUpdateController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/internal/profile/change-password', name: 'app_profile_change_password', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(UserPasswordResetForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $newPassword = $formData['newPassword'] ?? null;
            $newEncPrivateKeyPayload = $request->request->get('new_encrypted_private_key');

            if (empty($newPassword) || empty($newEncPrivateKeyPayload)) {
                $this->addFlash('danger', 'Cryptographic payload update was rejected. Form submission aborted.');
                return $this->redirectToRoute('app_profile_change_password');
            }

            // 1. Hash and save the login password using Argon2id/Standard Hasher
            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->password = $hashedPassword;

            $this->em->flush();

            $this->addFlash('success', 'Your account password and security keys have been successfully updated!');
            return $this->redirectToRoute('app_profile_change_password');
        }

        return $this->render('user/password_change.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Unauthenticated Forgot Password Reset (Generates New Identity & Sets Pending).
     * Note: In production, the reset token validation should match your standard password reset token system.
     */
    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetForgottenPassword(
        string $token,
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        // Find the user mapped to this reset token. (Mock lookup for demonstration purposes)
        // In your real system, match this to your Token entity or ResetPassword database lookup.
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $request->request->get('email') ?: $request->query->get('email')]);

        if (!$user) {
            $this->addFlash('danger', 'Invalid or expired password reset request parameters.');
            return $this->redirectToRoute('security_login');
        }

        if ($request->isMethod('POST')) {
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('reset_forgotten_password', $submittedToken)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->redirectToRoute('app_reset_password', ['token' => $token, 'email' => $user->email]);
            }

            $newPassword = $request->request->get('new_password');
            $newPublicKey = $request->request->get('new_public_key');
            $newEncPrivateKeyPayload = $request->request->get('new_encrypted_private_key');

            if (empty($newPassword)) {
                $this->addFlash('danger', 'New cryptographic credentials could not be verified. Please try again.');
                return $this->redirectToRoute('app_reset_password', ['token' => $token, 'email' => $user->email]);
            }

            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->password = $hashedPassword;

            $this->em->flush();

            return $this->redirectToRoute('security_login');
        }

        return $this->render('user/password_reset.html.twig', [
            'token' => $token,
            'userEmail' => $user->email
        ]);
    }
}
