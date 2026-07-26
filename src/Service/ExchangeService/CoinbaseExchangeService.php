<?php

namespace App\Service\ExchangeService;

use ccxt\async\coinbase;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTaggedItem(index: 'coinbase')]
class CoinbaseExchangeService extends AbstractCcxtExchangeService
{
    public function __construct(
        #[Autowire(env: 'COINBASE_API_KEY')] string $apiKey,
        #[Autowire(env: 'COINBASE_API_SECRET')] string $apiSecret,
    )
    {
        parent::__construct($apiKey, $apiSecret);
    }

    protected static function ccxtClass(): string
    {
        return coinbase::class;
    }
}
