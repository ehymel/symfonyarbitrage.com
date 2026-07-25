<?php

namespace App\EventListener;

use App\Entity\Login;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess')]
readonly class LoginSuccessEventListener
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * This service listens for user login and then records the login in db via Login entity.
     * @throws \Exception
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        /** @var User $user */
        $user = $event->getUser();
        $request = $event->getRequest();

        $login = new Login($user, $request->getClientIp() ?? '127.0.0.1');
        $this->em->persist($login);
        $this->em->flush();
    }
}
