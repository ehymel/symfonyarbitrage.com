<?php

namespace App\Service\ExchangeService;

use ccxt\async\cryptocom;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTaggedItem(index: 'cryptocom')]
class CryptoComExchangeService extends AbstractCcxtExchangeService
{
    public function __construct(
        #[Autowire(env: 'CRYPTOCOM_API_KEY')] string $apiKey,
        #[Autowire(env: 'CRYPTOCOM_API_SECRET')] string $apiSecret
    )
    {
        parent::__construct($apiKey, $apiSecret);
    }

    protected static function ccxtClass(): string
    {
        return cryptocom::class;
    }
}
