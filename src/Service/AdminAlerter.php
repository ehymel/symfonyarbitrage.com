<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

/**
 * Raises the on-call admin over SMS and email at once.
 *
 * Two channels because they fail differently: SMS reaches someone away from a desk but
 * carries almost no detail, while email carries the full picture but may sit unread for
 * hours. Together the text says "look now" and the mail says "here is what happened".
 *
 * Reserved for things a human must act on. Anything that merely wants recording belongs
 * in the log, and paging on routine events is how a pager gets ignored.
 */
final readonly class AdminAlerter
{
    public function __construct(
        private NotifierInterface $notifier,
        private LoggerInterface $logger,
        #[Autowire(env: 'ADMIN_PHONE_NUMBER')] private string $adminPhoneNumber,
        #[Autowire(env: 'ADMIN_EMAIL')] private string $adminEmail,
    ) {
    }

    /**
     * Alerting is best effort. Every caller is already dealing with something worse than
     * a missed notification, so a transport failure is logged and swallowed rather than
     * allowed to derail whatever recovery is in progress.
     *
     * @param string $headline short and self-contained — Symfony builds the SMS body from
     *                         the notification subject, so this text IS the whole text
     *                         message. Keep it under ~160 characters and actionable.
     * @param string $detail   the long version; reaches the email body only.
     */
    public function alert(string $headline, string $detail = ''): void
    {
        $channels = $this->availableChannels();

        if ($channels === []) {
            $this->logger->error("No admin contact configured, alert not sent: {$headline}");

            return;
        }

        try {
            $notification = (new Notification($headline, $channels))
                ->importance(Notification::IMPORTANCE_URGENT)
                ->content($detail);

            $this->notifier->send($notification, new Recipient($this->adminEmail, $this->adminPhoneNumber));
        } catch (\Throwable $e) {
            $this->logger->error("Failed to alert admin ({$headline}): " . $e->getMessage());
        }
    }

    /**
     * Only the channels with somewhere to deliver to. Handing the notifier a recipient
     * with a blank address makes it throw, which would turn a missing setting into a
     * second failure on top of the one being reported.
     *
     * @return list<string>
     */
    private function availableChannels(): array
    {
        $channels = [];

        if (trim($this->adminEmail) !== '') {
            $channels[] = 'email';
        }

        if (trim($this->adminPhoneNumber) !== '') {
            $channels[] = 'sms';
        }

        return $channels;
    }
}
