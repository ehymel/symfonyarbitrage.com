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
        return await($this->getBalanceAsync());
    }

    /**
     * @throws NotSupported
     */
    public function getBalanceAsync(): PromiseInterface
    {
        return $this->exchange->fetch_balance();
    }

    /**
     * Reads what warmUp() already put in memory rather than asking the venue, so this can
     * sit on the critical path of a trade without costing it anything.
     *
     * @return array{amount: float|null, cost: float|null}|null
     */
    public function getMinimumOrderSize(string $symbol): ?array
    {
        // Null until load_markets() has run. Warming is best effort by design — see
        // ExchangeWarmer — so an unwarmed venue is a real possibility, and saying "unknown"
        // is honest where inventing a zero floor would read as "no minimum".
        $market = $this->exchange->markets[$symbol] ?? null;

        if (!is_array($market)) {
            return null;
        }

        $limits = $market['limits'] ?? [];

        return [
            'amount' => $this->statedMinimum($limits['amount']['min'] ?? null),
            'cost' => $this->statedMinimum($limits['cost']['min'] ?? null),
        ];
    }

    /**
     * A minimum the venue actually stated. ccxt leaves the figure null where a venue
     * publishes none, and a zero floor constrains nothing, so both collapse to "no limit"
     * rather than to a number that would have to be compared against.
     */
    private function statedMinimum(mixed $value): ?float
    {
        if (!is_numeric($value) || (float) $value <= 0.0) {
            return null;
        }

        return (float) $value;
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
