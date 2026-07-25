<?php

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequest')]
readonly class SessionTimeoutListener
{
    private int $maxIdleTime;

    public function __construct(#[Autowire(param: 'session_max_idle_time')] string $maxIdleTime,
                                private TokenStorageInterface $securityToken,
                                private Security $security,
                                private RouterInterface $router)
    {
        $this->maxIdleTime = (int) $maxIdleTime;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->security->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return;
        }

        $token = $this->securityToken->getToken();
        if ($this->maxIdleTime > 0 && $token) {
            if ($token->hasAttribute('REMEMBER_ME') && (bool) $token->getAttribute('REMEMBER_ME')) {
                return;
            }

            $session = $event->getRequest()->getSession();
            if (!$session->isStarted()) {
                $session->start();
            }

            $lapse = time() - $session->getMetadataBag()->getLastUsed();

            if ($lapse > $this->maxIdleTime) {
                $this->securityToken->setToken(null);
                $session->getFlashBag()->add('info', 'You have been logged out due to inactivity.');

                $event->setResponse(new RedirectResponse($this->router->generate('security_logout')));
            }
        }
    }
}
