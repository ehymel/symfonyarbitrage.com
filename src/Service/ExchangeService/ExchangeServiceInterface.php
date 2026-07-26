<?php

namespace App\Service\ExchangeService;

use React\Promise\PromiseInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface ExchangeServiceInterface
{
    public function getBalance(): array;

    /**
     * Non-blocking counterpart to getBalance(), so a pre-trade funding check can hold a
     * read open against both venues at once rather than paying for them in sequence.
     *
     * @return PromiseInterface<array> resolves with the ccxt balance structure
     */
    public function getBalanceAsync(): PromiseInterface;

    /**
     * The venue's own floor on order size for a symbol, read from the market metadata
     * that warmUp() loads. A local array lookup — no round trip.
     *
     * Either figure may be null, which is ccxt's way of saying the venue states no
     * minimum of that kind; Kraken publishes both (`ordermin` and `costmin`) while
     * Coinbase mostly publishes only the cost floor.
     *
     * @return array{amount: float|null, cost: float|null}|null the minimum base quantity
     *         and the minimum quote notional, or null when no market is loaded for the
     *         symbol and the venue's floor is therefore unknown
     */
    public function getMinimumOrderSize(string $symbol): ?array;

    public function getOrderBook(string $symbol = 'ETH/USDT', ?int $limit = null): array;

    /**
     * Non-blocking counterpart to getOrderBook(), so a scanner can hold a read open
     * against every venue at once instead of paying for them one after another.
     *
     * @return PromiseInterface<array> resolves with the ccxt order book structure
     */
    public function getOrderBookAsync(string $symbol = 'ETH/USDT', ?int $limit = null): PromiseInterface;

    public function executeMarketOrder(string $symbol, string $side, float $amount): array;

    /**
     * Non-blocking counterpart to executeMarketOrder(). The request is already on the
     * wire when this returns, so callers can hold several legs in flight at once and
     * settle them together.
     *
     * @return PromiseInterface<array> resolves with the ccxt order structure
     */
    public function executeMarketOrderAsync(string $symbol, string $side, float $amount): PromiseInterface;

    /**
     * Pre-loads the venue's market metadata. ccxt otherwise fetches it lazily inside
     * the first order placed, putting a full markets round trip on the critical path
     * of a live trade.
     *
     * @return PromiseInterface<mixed>
     */
    public function warmUp(): PromiseInterface;
}
