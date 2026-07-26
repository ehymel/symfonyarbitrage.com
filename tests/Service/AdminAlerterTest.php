<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AdminAlerter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * This is the last thing standing between a broken trading pipeline and a human who does
 * not know about it yet, so the two things asserted hardest are that it reaches both
 * channels, and that it never throws — every caller is already handling something worse.
 */
#[CoversClass(AdminAlerter::class)]
final class AdminAlerterTest extends TestCase
{
    private const string PHONE = '+15555550123';
    private const string EMAIL = 'ops@example.com';

    /** @var list<array{notification: Notification, recipients: list<RecipientInterface>}> */
    private array $sent = [];

    /** When set, the notifier throws — a transport outage on top of whatever went wrong. */
    private ?\Throwable $notifierFailure = null;

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        $this->sent = [];
        $this->notifierFailure = null;
        $this->loggedMessages = [];
    }

    // ------------------------------------------------------------------ DELIVERY

    public function testAnAlertGoesToBothSmsAndEmail(): void
    {
        $this->alerter()->alert('Scanner stopped', 'The database went away.');

        self::assertCount(1, $this->sent);
        self::assertSame(['email', 'sms'], $this->channelsOf(0));
    }

    public function testTheRecipientCarriesBothAddresses(): void
    {
        $this->alerter()->alert('Scanner stopped');

        $recipient = $this->sent[0]['recipients'][0];

        self::assertSame(self::EMAIL, $recipient->getEmail());
        self::assertSame(self::PHONE, $recipient->getPhone());
    }

    /**
     * Symfony builds the SMS body from the notification subject, not its content, so the
     * headline has to carry the whole message. Detail is email-only by construction —
     * this pins the split so a future caller does not put the useful part in $detail and
     * quietly send a text message that says nothing.
     */
    public function testTheHeadlineBecomesTheSubjectAndTheDetailTheBody(): void
    {
        $this->alerter()->alert('Scanner stopped', 'Reason: server has gone away.');

        self::assertSame('Scanner stopped', $this->sent[0]['notification']->getSubject());
        self::assertSame('Reason: server has gone away.', $this->sent[0]['notification']->getContent());
    }

    public function testAlertsAreMarkedUrgent(): void
    {
        $this->alerter()->alert('Scanner stopped');

        self::assertSame(Notification::IMPORTANCE_URGENT, $this->sent[0]['notification']->getImportance());
    }

    public function testDetailIsOptional(): void
    {
        $this->alerter()->alert('Scanner stopped');

        self::assertSame('', $this->sent[0]['notification']->getContent());
    }

    // ------------------------------------------------------- PARTIAL CONFIGURATION

    /**
     * Handing the notifier a recipient with a blank address makes it throw, which would
     * turn a missing setting into a second failure on top of the one being reported. Each
     * channel is offered only when it has somewhere to deliver.
     */
    #[DataProvider('partialContactProvider')]
    public function testOnlyChannelsWithAnAddressAreUsed(string $phone, string $email, array $expected): void
    {
        $this->alerter($phone, $email)->alert('Scanner stopped');

        self::assertCount(1, $this->sent);
        self::assertSame($expected, $this->channelsOf(0));
    }

    public static function partialContactProvider(): iterable
    {
        yield 'both configured' => [self::PHONE, self::EMAIL, ['email', 'sms']];
        yield 'phone only' => [self::PHONE, '', ['sms']];
        yield 'email only' => ['', self::EMAIL, ['email']];
        yield 'whitespace is not an address' => ['   ', self::EMAIL, ['email']];
    }

    public function testWithNoContactConfiguredNothingIsSentAndTheGapIsLogged(): void
    {
        $this->alerter(phone: '', email: '')->alert('Scanner stopped');

        self::assertSame([], $this->sent);
        self::assertSame(
            ['No admin contact configured, alert not sent: Scanner stopped'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    // ----------------------------------------------------------------- RESILIENCE

    /**
     * The caller is mid-incident. A texting outage must not become an exception that
     * derails whatever recovery is underway.
     */
    public function testATransportOutageIsSwallowedAndLogged(): void
    {
        $this->notifierFailure = new \RuntimeException('SNS unreachable');

        $this->alerter()->alert('Scanner stopped');

        self::assertSame(
            ['Failed to alert admin (Scanner stopped): SNS unreachable'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    public function testASuccessfulAlertLogsNothing(): void
    {
        $this->alerter()->alert('Scanner stopped');

        self::assertSame([], $this->loggedMessages);
    }

    // -------------------------------------------------------------------- HELPERS

    private function alerter(string $phone = self::PHONE, string $email = self::EMAIL): AdminAlerter
    {
        return new AdminAlerter($this->recordingNotifier(), $this->recordingLogger(), $phone, $email);
    }

    private function recordingNotifier(): NotifierInterface
    {
        $notifier = $this->createStub(NotifierInterface::class);

        $notifier->method('send')->willReturnCallback(
            function (Notification $notification, RecipientInterface ...$recipients): void {
                if ($this->notifierFailure !== null) {
                    throw $this->notifierFailure;
                }

                $this->sent[] = ['notification' => $notification, 'recipients' => $recipients];
            }
        );

        return $notifier;
    }

    /**
     * @return list<string>
     */
    private function channelsOf(int $index): array
    {
        $entry = $this->sent[$index];

        return array_values($entry['notification']->getChannels($entry['recipients'][0]));
    }

    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        $logger->method('error')->willReturnCallback(
            function (string|\Stringable $message): void {
                $this->loggedMessages[LogLevel::ERROR][] = (string) $message;
            }
        );

        return $logger;
    }

    /**
     * @return list<string>
     */
    private function logMessages(string $level): array
    {
        return $this->loggedMessages[$level] ?? [];
    }
}
