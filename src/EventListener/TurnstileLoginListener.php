<?php

namespace App\EventListener;

use App\Security\TurnstileVerifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

#[AsEventListener(event: CheckPassportEvent::class, method: 'onCheckPassport')]
readonly class TurnstileLoginListener
{
    public function __construct(
        private RequestStack $requestStack,
        private TurnstileVerifier $turnstileVerifier
    ) {
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        // Only verify on the login route
        if ($request->attributes->get('_route') !== 'security_login' || !$request->isMethod('POST')) {
            return;
        }

        $turnstileResponse = $request->request->get('cf-turnstile-response');

        if (!$this->turnstileVerifier->verify($turnstileResponse, $request->getClientIp())) {
            throw new CustomUserMessageAuthenticationException('Invalid security verification. Please try again.');
        }
    }
}
