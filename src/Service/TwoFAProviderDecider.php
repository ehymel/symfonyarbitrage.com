<?php

namespace App\Service;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderDeciderInterface;

class TwoFAProviderDecider implements TwoFactorProviderDeciderInterface
{
    public function getPreferredTwoFactorProvider(array $activeProviders, TwoFactorTokenInterface $token, AuthenticationContextInterface $context): ?string
    {
        $provider = null;

        /** @var User $user */
        $user = $context->getUser();

        if ($user->isTotpAuthenticationEnabled()) {
            $provider = 'totp';
        } elseif ($user->isTextAuthEnabled()) {
            $provider = 'two_factor_text';
        } elseif ($user->isEmailAuthEnabled()) {
            $provider = 'email';
        }

        return $provider;
    }
}
