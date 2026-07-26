<?php

namespace App\Service;

use Erkens\Security\TwoFactorTextBundle\Model\TwoFactorTextInterface;
use Erkens\Security\TwoFactorTextBundle\TextSender\AuthCodeTextInterface;

class TextSender2FA implements AuthCodeTextInterface
{
    private string $format;

    public function __construct(private readonly TextSender $textSender)
    {
    }

    public function sendAuthCode(TwoFactorTextInterface $user, ?string $code = null): void
    {
        $message = sprintf($this->getMessageFormat(), $code ?? $user->getTextAuthCode());

        $this->textSender->send($message, $user->getTextAuthRecipient());
    }

    public function setMessageFormat(string $format): void
    {
        $this->format = $format;
    }

    public function getMessageFormat(): string
    {
        return $this->format;
    }
}
