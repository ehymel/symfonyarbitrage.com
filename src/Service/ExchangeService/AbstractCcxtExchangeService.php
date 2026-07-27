<?php

namespace App\Service\ExchangeService;

use ccxt\async\Exchange;
use ccxt\ExchangeError;
use ccxt\NotSupported;
use React\Promise\PromiseInterface;

use function React\Async\await;
use function React\Promise\Timer\timeout;

/**
 * Backed by ccxt's async (ReactPHP) client rather than the synchronous one: its HTTP
 * runs through a non-blocking React\Http\Browser on a shared event loop, which is what
 * lets two order legs actually overlap on the wire. The synchronous methods below are
 * thin await() wrappers, so single-call sites read exactly as they did before.
 */
abstract class AbstractCcxtExchangeService implements ExchangeServiceInterface
{
    protected readonly Exchange $exchange;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        /**
         * Resolve venue hostnames to IPv4 only.
         *
         * api.coinbase.com publishes AAAA records (it sits behind Cloudflare), so ReactPHP's
         * default Happy Eyeballs connector races an IPv6 attempt against an IPv4 one on every
         * single request. On a host with no global IPv6 address the v6 leg cannot even pick a
         * source address and fails instantly with EADDRNOTAVAIL — harmless, because the v4 leg
         * wins and the request succeeds, but ReactPHP suppresses the warning with @ and
         * Symfony's error handler dutifully logs every one of them at debug. At four scans a
         * second that is tens of thousands of lines an hour burying everything else in the log.
         *
         * Set false on a host with real IPv6 connectivity to get Happy Eyeballs back. Note
         * that api.kraken.com publishes no AAAA records at all, so IPv4 is the only path there
         * either way.
         */
        bool $preferIpv4 = true,
        /**
         * Ceiling on establishing the TCP connection and TLS handshake.
         *
         * Safe to keep tight: if no connection was established then no bytes were sent, so
         * nothing can have happened at the venue.
         */
        private readonly float $connectTimeoutSeconds = 3.0,
        /**
         * Ceiling on a whole market-data or balance request.
         *
         * Also safe to keep tight. Abandoning a read costs nothing, and an order book that
         * took longer than this to arrive describes a market that has already moved on.
         */
        private readonly float $readTimeoutSeconds = 3.0,
        /**
         * Ceiling on a whole order placement — deliberately several times the read budget.
         *
         * Giving up on an order is not the same as not placing one. The request is already
         * with the venue, and a timeout here cancels only our end of it: the order can still
         * fill. The handler would then record the leg as failed, unwind the other leg, and
         * leave us holding a position nobody knows about — the exact outcome the unwind
         * exists to prevent. So this is set far beyond any plausible fill-report latency,
         * and the circuit breaker's 450ms budget is what flags a venue that is merely slow.
         */
        private readonly float $orderTimeoutSeconds = 10.0,
    ) {
        $class = static::ccxtClass();

        $this->exchange = new $class([
            'apiKey' => $apiKey,
            'secret' => $apiSecret,
            'enableRateLimit' => true, // Respects each exchange's call tier limits automatically
            // ccxt counts this in milliseconds and hands it to React unconverted, where it is
            // read as seconds — see the connector below, which overrides it with a real one.
            'timeout' => 3000,
            'options' => [
                'adjustForTimeDifference' => true, // Syncs with the exchange's clock
                'recvWindow' => 5000, // Tolerance window for request latency
            ],
        ]);

        // Replacing default_connector rather than only the browser: ccxt rebuilds the browser
        // from default_connector at the top of every fetch(), so a browser swapped in here
        // alone would be silently discarded before the first request went out.
        $this->exchange->default_connector = $this->exchange->create_connector([
            'happy_eyeballs' => !$preferIpv4,
            // In seconds, which is what React means by it. ccxt would otherwise pass its own
            // millisecond figure straight through and ask for a 3,000-second connect timeout.
            'timeout' => $this->connectTimeoutSeconds,
        ]);
        $this->exchange->set_request_browser($this->exchange->default_connector);
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
        return timeout($this->exchange->fetch_balance(), $this->readTimeoutSeconds);
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
        return timeout($this->exchange->fetch_order_book($symbol, $limit), $this->readTimeoutSeconds);
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

        return timeout(
            $this->exchange->create_order($symbol, 'market', $buyOrSell, $amount),
            $this->orderTimeoutSeconds
        );
    }

    /**
     * Left unbounded above the connect timeout on purpose. This runs once at boot, pulls
     * over a thousand markets per venue, and would not fit the read budget on a slow link;
     * it is also best effort by contract — ExchangeWarmer logs a failure and carries on —
     * so a slow load delays the first trade rather than risking one.
     */
    public function warmUp(): PromiseInterface
    {
        // ccxt memoises this against the instance, so every later create_order() on
        // this long-lived service finds the markets already populated and skips the fetch.
        return $this->exchange->load_markets();
    }
}
