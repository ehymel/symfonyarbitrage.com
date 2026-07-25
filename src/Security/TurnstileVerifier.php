<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(CLOUDFLARE_TURNSTILE_SECRET_KEY)%')] private string $cloudflareTurnstileSecretKey
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if (empty($token)) {
            return false;
        }

        $body = [
            'secret' => $this->cloudflareTurnstileSecretKey,
            'response' => $token,
        ];

        if ($remoteIp) {
            $body['remoteip'] = $remoteIp;
        }

        try {
            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => $body,
            ]);

            $data = $response->toArray();

            return $data['success'] ?? false;
        } catch (\Exception) {
            return false;
        }
    }
}
