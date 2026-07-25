<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(CLOUDFLARE_TURNSTILE_SECRET_KEY)%')] private readonly string $cloudflareTurnstileSecretKey,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Validates a Turnstile token against Cloudflare's siteverify endpoint.
     *
     * Fails closed: any missing token, transport error or non-true `success`
     * field results in false, so callers can gate on a plain boolean.
     */
    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if (empty($token)) {
            $this->logger?->warning('Turnstile verification failed: no token present in the request.');

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

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            // Never let a siteverify outage surface as a 500 on a login form.
            $this->logger?->error('Turnstile verification could not reach siteverify: {reason}', [
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        if (($data['success'] ?? false) === true) {
            return true;
        }

        // Cloudflare reports the reason here; without it, a broken secret is
        // indistinguishable from a bot and the integration looks like a no-op.
        $this->logger?->warning('Turnstile verification rejected the token: {codes}', [
            'codes' => implode(', ', $data['error-codes'] ?? ['unknown']),
        ]);

        return false;
    }
}
