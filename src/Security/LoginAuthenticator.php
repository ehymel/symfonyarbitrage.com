<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Webauthn\Bundle\Security\Authentication\WebauthnAuthenticator;
use Webauthn\Bundle\Security\Authentication\WebauthnBadge;
use Webauthn\Bundle\Security\Authentication\WebauthnPassport;

final class LoginAuthenticator extends WebauthnAuthenticator
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {}

    public function supports(Request $request): bool
    {
        // Support traditional form submissions
        if ($request->isMethod('POST') && $request->request->has('_assertion') && !empty($request->request->get('_assertion'))) {
            return true;
        }

        // Support AJAX JSON requests
        if ($request->isMethod('POST') && str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
            $content = json_decode($request->getContent(), true);

            // Check if it's a valid JSON array with an 'id' and 'response'
            if (is_array($content) && isset($content['id']) && isset($content['response'])) {
                // Distinguish between Registration (Attestation) and Login (Assertion)
                // Registrations have 'attestationObject', Logins have 'authenticatorData' and 'signature'
                if (isset($content['response']['authenticatorData']) && isset($content['response']['signature'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function authenticate(Request $request): Passport
    {
        $assertion = $request->request->get('_assertion');
        $isJsonRequest = str_contains($request->headers->get('Content-Type', ''), 'application/json');


        if (empty($assertion) && $isJsonRequest) {
            $assertion = $request->getContent();
        }

        if (empty($assertion)) {
            throw new CustomUserMessageAuthenticationException('No WebAuthn assertion data found.');
        }

        // Create the passport
        $passport =  new WebauthnPassport(
            new WebauthnBadge(
                $request->getHost(),
                $assertion
            )
        );


        // Detect if "Remember Me" was requested
        $rememberMeRequested = false;
        if ($request->query->getBoolean('_remember_me') || $request->request->getBoolean('_remember_me')) {
            $rememberMeRequested = true;
        } elseif ($isJsonRequest) {
            $content = json_decode($request->getContent(), true);
            if (is_array($content) && !empty($content['_remember_me'])) {
                $rememberMeRequested = true;
            }
        }

        if ($rememberMeRequested) {
            // Explicitly enable the badge so Symfony generates the REMEMBERME cookie
            $passport->addBadge(new RememberMeBadge()->enable());
        }

        return $passport;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new JsonResponse([
            'success' => true,
            'redirect' => $this->urlGenerator->generate('login_redirect'),
        ]);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('security_login'); //Redirect to the login controller
    }
}
