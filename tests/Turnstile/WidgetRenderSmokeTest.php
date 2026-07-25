<?php
declare(strict_types=1);

namespace App\Tests\Turnstile;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WidgetRenderSmokeTest extends WebTestCase
{
    public function testLoginPageRendersTurnstileWidget(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $widget = $crawler->filter('#turnstile-widget');
        self::assertCount(1, $widget, 'login page must render one widget container');
        self::assertStringContainsString('cf-turnstile', (string) $widget->attr('class'));

        $scope = $crawler->filter('[data-controller="turnstile"]');
        self::assertSame('turnstile-spin-v2', $scope->attr('data-turnstile-action-value'));
        self::assertNotEmpty($scope->attr('data-turnstile-sitekey-value'), 'sitekey must be injected');
    }

    public function testForgotPasswordPageRendersTurnstileWidget(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/user/password/password/forgot/');

        self::assertResponseIsSuccessful();

        $widget = $crawler->filter('form div.cf-turnstile');
        self::assertCount(1, $widget, 'widget must sit inside the form so the token is submitted');
        self::assertSame('turnstile-spin-v2', $widget->attr('data-action'));
        self::assertNotEmpty($widget->attr('data-sitekey'));

        self::assertCount(
            1,
            $crawler->filter('script[src^="https://challenges.cloudflare.com/turnstile/v0/api.js"]'),
            'api.js must be loaded exactly once'
        );
    }

    /**
     * The gate must reject before the handler runs. A missing token short-circuits
     * in TurnstileVerifier, so this never reaches the database or the mailer.
     */
    public function testForgotPasswordRejectsSubmissionWithoutTurnstileToken(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/user/password/password/forgot/');

        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/user/password/password/forgot/', [
            '_token' => $csrfToken,
            'email' => 'someone@example.com',
            // no cf-turnstile-response
        ]);

        self::assertResponseRedirects('/user/password/password/forgot/');
        self::assertEmailCount(0, 'no reset mail may be dispatched when verification fails');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Bot verification failed');
    }
}
