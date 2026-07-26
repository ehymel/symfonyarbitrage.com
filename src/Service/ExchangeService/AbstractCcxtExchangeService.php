<?php

namespace App\Service\ExchangeService;

use ccxt\Exchange;
use ccxt\ExchangeError;
use ccxt\NotSupported;

abstract class AbstractCcxtExchangeService implements ExchangeServiceInterface
{
    protected readonly Exchange $exchange;

    public function __construct(string $apiKey, string $apiSecret)
    {
        $class = static::ccxtClass();

        $this->exchange = new $class([
            'apiKey' => $apiKey,
            'secret' => $apiSecret,
            'enableRateLimit' => true, // Respects each exchange's call tier limits automatically
            'timeout' => 3000, // Strict 3-second network timeout
            'options' => [
                'adjustForTimeDifference' => true, // Syncs with the exchange's clock
                'recvWindow' => 5000, // Tolerance window for request latency
            ],
        ]);
    }

    /**
     * @return class-string<Exchange>
     */
    abstract protected static function ccxtClass(): string;

    /**
     * @throws NotSupported
     */
    public function getBalance(): array
    {
        return $this->exchange->fetch_balance();
    }

    /**
     * @throws NotSupported
     */
    public function getOrderBook(string $symbol = 'ETH/USDT', ?int $limit = null): array
    {
        return $this->exchange->fetch_order_book($symbol, $limit);
    }

    /**
     * @throws ExchangeError
     */
    public function executeMarketOrder(string $symbol, string $side, float $amount): array
    {
        // CCXT forwards $side verbatim to the exchange API, which expects it lowercase.
        $buyOrSell = match ($side) {
            'buy', 'sell' => $side,
            default => throw new \InvalidArgumentException("Invalid side value: {$side}"),
        };

        return $this->exchange->create_order($symbol, 'market', $buyOrSell, $amount);
    }
}
