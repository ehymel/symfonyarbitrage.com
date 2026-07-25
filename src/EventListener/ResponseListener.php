<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponse')]
class ResponseListener
{
    public function onKernelResponse(ResponseEvent $response): void
    {
        $responseHeaders = $response->getResponse()->headers;

        $responseHeaders->set('X-FRAME-OPTIONS', 'DENY');
        $responseHeaders->set('X-XSS-Protection', '1');
        $responseHeaders->set('X-Content-Type-Options', 'nosniff');
    }
}
