<?php

namespace App\Service\ExchangeService;

use React\Promise\PromiseInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface ExchangeServiceInterface
{
    public function getBalance(): array;

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
