<?php

namespace App\EventListener;

use App\Service\ExchangeWarmer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * ExecuteArbitrageMessage is consumed by a long-lived worker, so the exchange services
 * it resolves live for the worker's whole lifetime. Warming their markets here means
 * the cost is paid once at boot instead of by whichever trade happens to be first.
 */
#[AsEventListener(event: WorkerStartedEvent::class)]
final readonly class WarmExchangeMarketsListener
{
    public function __construct(private ExchangeWarmer $warmer)
    {
    }

    public function __invoke(WorkerStartedEvent $event): void
    {
        $this->warmer->warmAll();
    }
}
