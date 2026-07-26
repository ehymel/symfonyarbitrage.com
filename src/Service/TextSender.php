<?php

namespace App\Service;

use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

readonly class TextSender
{
    public function __construct(private TexterInterface $texter)
    {}

    /**
     * @throws TransportExceptionInterface
     */
    public function send(string $message, string $cell): void
    {
        // the '+1' assumes sending to a US number
        $cell = '+1' . preg_replace("/[^0-9]+/", "", $cell);

        $sms = new SmsMessage($cell, $message);

        $this->texter->send($sms);
    }
}
