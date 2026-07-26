<?php

namespace App\Service\ExchangeService;

use ccxt\async\Exchange;
use ccxt\ExchangeError;
use ccxt\NotSupported;
use React\Promise\PromiseInterface;

use function React\Async\await;

/**
 * Backed by ccxt's async (ReactPHP) client rather than the synchronous one: its HTTP
 * runs through a non-blocking React\Http\Browser on a shared event loop, which is what
 * lets two order legs actually overlap on the wire. The synchronous methods below are
 * thin await() wrappers, so single-call sites read exactly as they did before.
 */
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
     * @throws NotSupported|\Throwable
     */
    public function getBalance(): array
    {
        return await($this->exchange->fetch_balance());
    }

    /**
     * @throws NotSupported|\Throwable
     */
    public function getOrderBook(string $symbol = 'ETH/USDT', ?int $limit = null): array
    {
        return await($this->getOrderBookAsync($symbol, $limit));
    }

    /**
     * @throws NotSupported
     */
    public function getOrderBookAsync(string $symbol = 'ETH/USDT', ?int $limit = null): PromiseInterface
    {
        return $this->exchange->fetch_order_book($symbol, $limit);
    }

    /**
     * @throws ExchangeError|\Throwable
     */
    public function executeMarketOrder(string $symbol, string $side, float $amount): array
    {
        return await($this->executeMarketOrderAsync($symbol, $side, $amount));
    }

    /**
     * @throws NotSupported
     */
    public function executeMarketOrderAsync(string $symbol, string $side, float $amount): PromiseInterface
    {
        // CCXT forwards $side verbatim to the exchange API, which expects it lowercase.
        // Validated eagerly rather than as a rejection: a bad side is a programming
        // error, and failing before the request is dispatched leaves nothing in flight.
        $buyOrSell = match ($side) {
            'buy', 'sell' => $side,
            default => throw new \InvalidArgumentException("Invalid side value: {$side}"),
        };

        return $this->exchange->create_order($symbol, 'market', $buyOrSell, $amount);
    }

    public function warmUp(): PromiseInterface
    {
        // ccxt memoises this against the instance, so every later create_order() on
        // this long-lived service finds the markets already populated and skips the fetch.
        return $this->exchange->load_markets();
    }
}
