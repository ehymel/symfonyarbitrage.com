<?php

namespace App\Service\ExchangeService;

use ccxt\ExchangeError;
use ccxt\kraken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class KrakenExchangeService
{
    private kraken $exchange;

    /**
     * @throws ExchangeError
     */
    public function __construct(
        #[Autowire(env: 'KRAKEN_API_KEY')] string $apiKey,
        #[Autowire(env: 'KRAKEN_PRIVATE_KEY')] string $apiSecret
    )
    {
        $this->exchange = new kraken([
            'apiKey' => $apiKey,
            'secret' => $apiSecret,
            'enableRateLimit' => true, // Respects Kraken's call tier limits automatically
            'options' => [
                'recvWindow' => 5000, // Tolerance window for request latency
                'adjustForTimeDifference' => true, // Syncs server time with Kraken's clock
            ],
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
