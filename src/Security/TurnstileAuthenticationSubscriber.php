<?php

declare(strict_types=1);

namespace App\Security;

use Scheb\TwoFactorBundle\Security\Http\Authenticator\Passport\Credentials\TwoFactorCodeCredentials;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Enforces Cloudflare Turnstile on the login steps that the firewall handles
 * itself, where there is no controller to put the siteverify call into.
 *
 * Two flows are gated:
 *   - password login  (form_login -> PasswordCredentials)
 *   - 2FA code entry  (scheb/2fa -> TwoFactorCodeCredentials)
 *
 * Passkey/WebAuthn logins carry neither badge and are deliberately left alone:
 * their assertion is already cryptographically bound to the challenge.
 */
final class TurnstileAuthenticationSubscriber implements EventSubscriberInterface
{
    public const string TOKEN_PARAMETER = 'cf-turnstile-response';

    public function __construct(
        private readonly TurnstileVerifier $turnstileVerifier,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // 300 sits below CsrfProtectionListener (512) so a forged request is
        // still rejected on CSRF first, and above UserCheckerListener (256),
        // CheckCredentialsListener (0) and CheckTwoFactorCodeListener (0) so a
        // bot never reaches password hashing or code comparison.
        return [
            CheckPassportEvent::class => ['onCheckPassport', 300],
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        $isGuardedFlow = $passport->hasBadge(PasswordCredentials::class)
            || $passport->hasBadge(TwoFactorCodeCredentials::class);

        if (!$isGuardedFlow) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return;
        }

        $token = (string) $request->request->get(self::TOKEN_PARAMETER, '');

        if (!$this->turnstileVerifier->verify($token, $request->getClientIp())) {
            throw new CustomUserMessageAuthenticationException(
                'Bot verification failed. Please complete the challenge and try again.'
            );
        }
    }
}
