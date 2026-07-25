<?php

namespace App\Service;

use Aws\Result;
use Aws\Sns\SnsClient;
use Erkens\Security\TwoFactorTextBundle\Model\TwoFactorTextInterface;
use Erkens\Security\TwoFactorTextBundle\TextSender\AuthCodeTextInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class TextSender2FA implements AuthCodeTextInterface
{
    private string $format;

    public function __construct(#[Autowire(param: 'env(AMAZON_SNS_DEFAULT_REGION)')] private readonly string $sns_region)
    {
    }

    public function sendAuthCode(TwoFactorTextInterface $user, ?string $code = null): void
    {
        $message = sprintf($this->getMessageFormat(), $code ?? $user->getTextAuthCode());

        $this->send($message, $user->getTextAuthRecipient());
    }

    public function setMessageFormat(string $format): void
    {
        $this->format = $format;
    }

    public function getMessageFormat(): string
    {
        return $this->format;
    }

    private function send(string $message, string $cell): Result
    {
        // the '+1' assumes sending to a US number
        $cell = '+1'.preg_replace("/[^0-9]+/", "", $cell);

        $snsClient = new SnsClient(['region' => $this->sns_region]);

        return $snsClient->publish([
            'Message' => $message,
            'PhoneNumber' => $cell,
        ]);
    }

}
