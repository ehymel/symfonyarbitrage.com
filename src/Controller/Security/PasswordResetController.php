<?php

namespace App\Controller\Security;

use App\Entity\User;
use App\Security\TurnstileAuthenticationSubscriber;
use App\Security\TurnstileVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles secure unauthenticated password resets under E2EE Pattern 2 (Institutional Escrow).
 */
#[Route('/user/password', name: 'user_password_', methods: ['GET', 'POST'])]
class PasswordResetController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private readonly TurnstileVerifier $turnstileVerifier,
    ) {}

    /**
     * Validates the Cloudflare Turnstile token attached to a submitted form.
     */
    private function isTurnstileValid(Request $request): bool
    {
        $token = (string) $request->request->get(TurnstileAuthenticationSubscriber::TOKEN_PARAMETER, '');

        return $this->turnstileVerifier->verify($token, $request->getClientIp());
    }

    #[Route('/password/forgot/', name: 'forgot', methods: ['GET', 'POST'])]
    public function requestReset(Request $request, MailerInterface $mailer): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('internal_documents_dashboard');
        }

        if ($request->isMethod('POST')) {
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('forgot_password_request', $submittedToken)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->redirectToRoute('user_password_forgot');
            }

            if (!$this->isTurnstileValid($request)) {
                $this->addFlash('danger', 'Bot verification failed. Please complete the challenge and try again.');
                return $this->redirectToRoute('user_password_forgot');
            }

            $emailInput = trim((string)$request->request->get('email'));
            if (empty($emailInput)) {
                $this->addFlash('danger', 'Please enter your email address.');
                return $this->redirectToRoute('user_password_forgot');
            }

            /** @var User $user */
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $emailInput]);

            // Protect against user enumeration by displaying a success message regardless of email existence
            if ($user) {
                // Generate a cryptographically secure token
                $resetToken = bin2hex(random_bytes(32));

                // Set temporary mock reset values directly into session or database
                // (Assuming user record or auxiliary entity tracks token + expiration)
                $session = $request->getSession();
                $session->set('pwd_reset_token_' . $resetToken, [
                    'email' => $user->email,
                    'expires_at' => new \DateTimeImmutable('+1 hour')->getTimestamp()
                ]);

                $resetUrl = $this->generateUrl('user_password_reset', ['token' => $resetToken],UrlGeneratorInterface::ABSOLUTE_URL);

                // Send the reset notification email
                $email = new TemplatedEmail()
                    ->to($user->email)
                    ->subject('Reset Your Security Workspace Credentials')
                    ->htmlTemplate('emails/user_reset_password.html.twig')
                    ->context([
                        'resetUrl' => $resetUrl,
                    ]);

                try {
                    $mailer->send($email);
                } catch (TransportExceptionInterface $e) {
                    $this->addFlash('error', 'An error occurred while sending the password reset email. Please try again later.');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'An unexpected error occurred while sending the password reset email. Please try again later.');
                }
            }

            $this->addFlash('success', 'If a matching account exists, a secure password reset link has been dispatched to your inbox.');
            return $this->redirectToRoute('user_password_forgot');
        }

        return $this->render('security/forgot_password_request.html.twig');
    }

    #[Route('/reset/{token}', name: 'reset', methods: ['GET', 'POST'])]
    public function executeReset(
        string $token,
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('internal_documents_dashboard');
        }

        $session = $request->getSession();
        $tokenData = $session->get('pwd_reset_token_' . $token);

        if (!$tokenData || $tokenData['expires_at'] < time()) {
            $this->addFlash('danger', 'The password reset token is invalid, has expired, or has already been used.');
            return $this->redirectToRoute('user_password_forgot');
        }

        /** @var User $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $tokenData['email']]);
        if (!$user) {
            $this->addFlash('danger', 'The user associated with this token could not be verified.');
            return $this->redirectToRoute('user_password_forgot');
        }

        if ($request->isMethod('POST')) {
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('reset_forgotten_password_token', $submittedToken)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->redirectToRoute('user_password_reset', ['token' => $token]);
            }

            if (!$this->isTurnstileValid($request)) {
                $this->addFlash('danger', 'Bot verification failed. Please complete the challenge and try again.');
                return $this->redirectToRoute('user_password_reset', ['token' => $token]);
            }

            $newPassword = $request->request->get('new_password');
            $newPublicKey = $request->request->get('new_public_key');
            $newEncPrivateKeyPayload = $request->request->get('new_encrypted_private_key');

            if (empty($newPassword) || empty($newPublicKey) || empty($newEncPrivateKeyPayload)) {
                $this->addFlash('danger', 'Cryptographic identity parameters are missing. Refusing server-side authentication update.');
                return $this->redirectToRoute('user_password_reset', ['token' => $token]);
            }

            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->password = $hashedPassword;

            $session->remove('pwd_reset_token_' . $token);
            $this->em->flush();

            $this->addFlash('success', 'Your password has been successfully reset!');

            return $this->redirectToRoute('security_login');
        }

        return $this->render('security/reset_forgotten_password.html.twig', [
            'token' => $token,
            'userEmail' => $user->email,
        ]);
    }
}
