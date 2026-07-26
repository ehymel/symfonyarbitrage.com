<?php

namespace App\Service\ExchangeService;

use ccxt\async\kraken;
use ccxt\ExchangeError;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTaggedItem(index: 'kraken')]
class KrakenExchangeService extends AbstractCcxtExchangeService
{
    /**
     * @throws ExchangeError
     */
    public function __construct(
        #[Autowire(env: 'KRAKEN_API_KEY')] string $apiKey,
        #[Autowire(env: 'KRAKEN_PRIVATE_KEY')] string $apiSecret
    )
    {
        parent::__construct($apiKey, $apiSecret);
    }

    protected static function ccxtClass(): string
    {
        return kraken::class;
    }
}
