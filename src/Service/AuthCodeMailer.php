<?php

namespace App\Service;

use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class AuthCodeMailer implements AuthCodeMailerInterface
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    /**
     * @inheritDoc
     * @throws TransportExceptionInterface
     */
    public function sendAuthCode(TwoFactorInterface $user): void
    {
        $authCode = $user->getEmailAuthCode();

        // now send activation email with above hash
        $email = new TemplatedEmail()
            ->to($user->email)
            ->subject('Authentication Code: '.$authCode)
            ->htmlTemplate('emails/user_authentication.html.twig')
            ->context([
                'authCode' => $authCode,
            ])
        ;

        $email->getHeaders()->add(new TagHeader('two-factor'));
        $email->getHeaders()->add(new MetadataHeader('user', $user->getUserIdentifier()));

        $this->mailer->send($email);
    }
}
