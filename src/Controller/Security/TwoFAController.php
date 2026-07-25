<?php

namespace App\Controller\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Email\Generator\CodeGeneratorInterface as EmailCodeGeneratorInterface;
use Erkens\Security\TwoFactorTextBundle\Generator\CodeGeneratorInterface as TextCodeGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/2fa/', name: '2fa_')]
class TwoFAController extends AbstractController
{
    #[Route('manage', name: 'manage')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function manage(): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        return $this->render('security/2fa_manage.html.twig', [
            'user' => $currentUser,
        ]);
    }

    #[Route('email/resend', name: 'email_resend')]
    public function resendAuthEmail(EmailCodeGeneratorInterface $codeGenerator): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('security_login');
        }

        try {
            $codeGenerator->reSend($user);
        } catch (\LogicException) {
            $codeGenerator->generateAndSend($user);
        }

        $this->addFlash('success', 'Authentication code re-sent.');

        return $this->redirectToRoute('2fa_login');
    }

    #[Route('text/resend', name: 'text_resend')]
    public function resendAuthText(TextCodeGeneratorInterface $codeGenerator): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('security_login');
        }

        try {
            $codeGenerator->reSend($user);
        } catch (\LogicException) {
            $codeGenerator->generateAndSend($user);
        }

        $this->addFlash('success', 'Authentication code re-sent.');

        return $this->redirectToRoute('2fa_login');
    }
}
