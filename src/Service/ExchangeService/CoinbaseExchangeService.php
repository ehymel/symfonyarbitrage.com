<?php

namespace App\Service\ExchangeService;

use ccxt\coinbase;
use ccxt\ExchangeError;
use ccxt\InvalidOrder;
use ccxt\NotSupported;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class CoinbaseExchangeService
{
    private coinbase $exchange;

    /**
     * @throws ExchangeError
     */
    public function __construct(
        #[Autowire(env: 'COINBASE_API_KEY')] string $apiKey,
        #[Autowire(env: 'COINBASE_API_SECRET')] string $apiSecret,
    )
    {
        $this->exchange = new coinbase([
            'apiKey' => $apiKey,
            'secret' => $apiSecret,
            'enableRateLimit' => true,
        ]);
    }

    public function getBalance(): array
    {
        return $this->exchange->fetch_balance();
    }

    public function getOrderBook(string $symbol = 'ETH/USDT'): array
    {
        return $this->exchange->fetch_order_book($symbol);
    }

    /**
     * @throws ExchangeError
     * @throws InvalidOrder
     * @throws NotSupported
     */
    public function executeMarketOrder(string $symbol, string $side, float $amount): array
    {
        // $side = 'buy' or 'sell'
        $buyOrSell = match($side) {
            'buy' => 'BUY',
            'sell' => 'SELL',
            default => throw new \InvalidArgumentException('Invalid side value'),
        };

        return $this->exchange->create_order($symbol, 'market', $buyOrSell, $amount);
    }
}
