<?php

namespace App\Service;

use App\Service\ExchangeService\ExchangeServiceInterface;
use Psr\Log\LoggerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function React\Async\await;
use function React\Promise\all;

/**
 * Pre-trade check that both venues can actually settle their side of the arbitrage: the
 * sell venue holding the coin it is about to deliver, the buy venue holding the cash it
 * is about to spend.
 *
 * Without it, an underfunded account is discovered the only way an exchange will tell
 * you — by rejecting one leg after the other has already filled. That is the partial-fill
 * path: a live position, an emergency unwind, and a realized loss on a trade that was
 * never going to work, repeated for every opportunity detected on that pair until a human
 * notices. One balance read beforehand turns all of that into a skipped trade.
 *
 * The check is deliberately conservative in both directions: it reads free balance rather
 * than total, and it treats "the venue would not tell us" as a reason not to trade.
 */
class TradeFundingGuard
{
    public function __construct(
        private readonly CacheInterface  $cache,
        private readonly AdminAlerter    $alerter,
        private readonly LoggerInterface $logger,
        /**
         * Headroom on the quote currency the buy leg spends. Needs to be the larger of the
         * two: the cost is derived from the quoted price, and a market buy can fill above
         * it, on top of a taker fee charged in quote.
         */
        #[Autowire(env: 'float:BUY_FUNDING_SAFETY_MARGIN')]
        private readonly float           $buySafetyMargin = 0.0,
        /**
         * Headroom on the base asset the sell leg delivers. Smaller, because the quantity
         * is exact — this only absorbs venues that take the taker fee in the base asset and
         * lot-size rounding that nudges the order above the quantity we asked for.
         */
        #[Autowire(env: 'float:SELL_FUNDING_SAFETY_MARGIN')]
        private readonly float           $sellSafetyMargin = 0.0,
        /** How long one venue+asset stays quiet after paging about an underfunded account. */
        private readonly int             $alertThrottleSeconds = 3600,
    ) {
        // A negative margin would demand less than the leg needs, quietly defeating the
        // guard. Refusing to start beats trading behind a risk control wired backwards.
        foreach (['buy' => $this->buySafetyMargin, 'sell' => $this->sellSafetyMargin] as $side => $margin) {
            if ($margin < 0.0) {
                throw new \InvalidArgumentException(
                    sprintf('The %s funding safety margin cannot be negative, got %s.', $side, $margin)
                );
            }
        }
    }

    /**
     * Clears both legs at once.
     *
     * Both reads are dispatched before either is awaited, so the pre-flight costs one round
     * trip rather than two — the same reason the legs themselves overlap. Checking them
     * together also means a trade blocked on both sides is reported as such, instead of the
     * first refusal hiding the second until the next attempt.
     *
     * @param float $amount   the base-asset quantity both legs will trade
     * @param float $buyPrice the price the buy leg was quoted at, used to derive its cost
     *
     * @throws \InvalidArgumentException if the symbol is not a BASE/QUOTE pair, or the size
     *                                   and price do not describe a real trade
     * @throws \Throwable
     */
    public function clearLegs(
        ExchangeServiceInterface $buyVenue,
        string $buyVenueName,
        ExchangeServiceInterface $sellVenue,
        string $sellVenueName,
        string $symbol,
        float $amount,
        float $buyPrice,
    ): FundingReport {
        [$base, $quote] = $this->splitPair($symbol);

        // Validated before anything goes on the wire. A zero or negative size or price makes
        // the requirement zero, which every account trivially satisfies — the check would
        // report clearance without having checked anything.
        if ($amount <= 0.0) {
            throw new \InvalidArgumentException(sprintf('Trade size must be positive, got %s.', $amount));
        }

        if ($buyPrice <= 0.0) {
            throw new \InvalidArgumentException(sprintf('Buy price must be positive, got %s.', $buyPrice));
        }

        $reads = [
            'buy' => $this->readBalance($buyVenue),
            'sell' => $this->readBalance($sellVenue),
        ];

        $settled = await(all($reads));

        return new FundingReport(
            buy: $this->verdictFor(
                $settled['buy'],
                $buyVenueName,
                $quote,
                'buy',
                $amount * $buyPrice * (1.0 + $this->buySafetyMargin),
                $symbol,
                $amount
            ),
            sell: $this->verdictFor(
                $settled['sell'],
                $sellVenueName,
                $base,
                'sell',
                $amount * (1.0 + $this->sellSafetyMargin),
                $symbol,
                $amount
            ),
        );
    }

    /**
     * Turns one venue's balance read into a verdict, logging and paging as it goes.
     *
     * @param array{state: string, value?: array, reason?: \Throwable} $settled
     * @param string $asset    what this leg spends: the quote currency on a buy, the base on a sell
     * @param float  $required how much of it, margin included
     */
    private function verdictFor(
        array $settled,
        string $venueName,
        string $asset,
        string $side,
        float $required,
        string $symbol,
        float $amount,
    ): FundingVerdict {
        if ($settled['state'] === 'rejected') {
            // Not an alert: a private endpoint that will not answer is a venue fault, and
            // the circuit breaker is what escalates those. Paging here would double up on
            // an incident the breaker already reports.
            $this->logger->error(sprintf(
                'Could not read %s balance on %s, so the %s leg cannot be cleared: %s',
                $asset,
                $venueName,
                $side,
                $settled['reason']->getMessage()
            ));

            return FundingVerdict::Unknown;
        }

        $available = $this->freeBalanceOf($settled['value'], $asset);

        if ($available === null) {
            $this->logger->error(sprintf(
                'Balance response from %s carried no readable %s figure; treating the %s leg as uncleared.',
                $venueName,
                $asset,
                $side
            ));

            return FundingVerdict::Unknown;
        }

        if ($available >= $required) {
            return FundingVerdict::Sufficient;
        }

        $this->logger->warning(sprintf(
            'Insufficient %s on %s to %s %.8f %s (%.8f required with margin, %.8f free).',
            $asset,
            $venueName,
            $side,
            $amount,
            $symbol,
            $required,
            $available
        ));

        $this->alertOnce($venueName, $asset, $side, $symbol, $required, $available);

        return FundingVerdict::Short;
    }

    /**
     * Dispatches a balance read that always settles, carrying its own outcome.
     *
     * all() rejects the moment any input rejects, which here would discard the other
     * venue's answer along with it — and the two are reported differently, one possibly to
     * the circuit breaker. A synchronous throw from the client is folded into the same
     * shape so the caller has one path to reason about.
     *
     * @return PromiseInterface<array{state: string, value?: array, reason?: \Throwable}>
     */
    private function readBalance(ExchangeServiceInterface $venue): PromiseInterface
    {
        try {
            return $venue->getBalanceAsync()->then(
                static fn (array $balance): array => ['state' => 'fulfilled', 'value' => $balance],
                static fn (\Throwable $e): array => ['state' => 'rejected', 'reason' => $e],
            );
        } catch (\Throwable $e) {
            $deferred = new Deferred();
            $deferred->resolve(['state' => 'rejected', 'reason' => $e]);

            return $deferred->promise();
        }
    }

    /**
     * The two assets a leg can spend: base leaves the account on a sell, quote on a buy.
     *
     * Validated eagerly rather than guessed at: silently mis-deriving the asset would check
     * the balance of something the trade never touches, which is worse than no check at all
     * because it reads as a pass.
     *
     * @return array{0: string, 1: string} [base, quote]
     */
    private function splitPair(string $symbol): array
    {
        $parts = explode('/', $symbol, 2);

        if (count($parts) !== 2) {
            throw new \InvalidArgumentException(
                sprintf('Cannot derive the traded assets from symbol "%s"; expected BASE/QUOTE.', $symbol)
            );
        }

        $base = strtoupper(trim($parts[0]));
        // Settlement suffixes ('ETH/USDT:USDT') name the same currency the leg pays in.
        $quote = strtoupper(trim(explode(':', $parts[1], 2)[0]));

        if ($base === '' || $quote === '') {
            throw new \InvalidArgumentException(
                sprintf('Cannot derive the traded assets from symbol "%s"; expected BASE/QUOTE.', $symbol)
            );
        }

        return [$base, $quote];
    }

    /**
     * Free balance in $asset, or null if the response does not say.
     *
     * Free rather than total, because coin committed to open orders cannot be spent twice —
     * totalling it would pass a check the venue is then guaranteed to reject.
     *
     * The three outcomes are all distinct and all matter. A venue that lists its wallets
     * and omits this asset is telling us it holds none of it, which is a firm zero. A venue
     * that lists the asset with a null figure is telling us nothing, and ccxt does exactly
     * that for exchanges that do not break out the free portion — reading that as zero
     * would block trading on venues that are perfectly well funded.
     */
    private function freeBalanceOf(array $balance, string $asset): ?float
    {
        $free = $balance['free'] ?? null;

        if (is_array($free)) {
            if (!array_key_exists($asset, $free)) {
                return 0.0;
            }

            return is_numeric($free[$asset]) ? (float) $free[$asset] : null;
        }

        // Per-currency shape: ['ETH' => ['free' => ..., 'used' => ..., 'total' => ...]].
        $perAsset = $balance[$asset] ?? null;

        if (is_array($perAsset) && array_key_exists('free', $perAsset)) {
            return is_numeric($perAsset['free']) ? (float) $perAsset['free'] : null;
        }

        return null;
    }

    /**
     * Pages the admin about an underfunded account, at most once per venue+asset per window.
     *
     * It has to page at all because nothing else will: a shortfall is not a venue failure,
     * so the circuit breaker never sees it, and the strategy simply goes quiet on that pair
     * until someone tops the account up. It has to be throttled because the scanner detects
     * the same opportunity several times a second, and a pager that fires four times a
     * second is a pager nobody reads.
     *
     * Wrapped whole, because everything here is a courtesy on top of the decision already
     * made. A cache miss-fire or a notifier outage must not turn "skip this trade" into an
     * exception thrown at the handler.
     */
    private function alertOnce(
        string $venueName,
        string $asset,
        string $side,
        string $symbol,
        float $required,
        float $available,
    ): void {
        try {
            $key = sprintf(
                'funding_alert_%s_%s',
                preg_replace('/[^A-Za-z0-9_]/', '_', $venueName),
                preg_replace('/[^A-Za-z0-9_]/', '_', $asset)
            );

            $firstInWindow = false;

            $this->cache->get($key, function (ItemInterface $item) use (&$firstInWindow): bool {
                $item->expiresAfter($this->alertThrottleSeconds);
                $firstInWindow = true;

                return true;
            });

            if (!$firstInWindow) {
                return;
            }

            $this->alerter->alert(
                sprintf('⚠️ Out of %s on %s — arbitrage %ss are being skipped', $asset, $venueName, $side),
                sprintf(
                    "A %s arbitrage was skipped because %s does not hold enough %s to settle the %s leg.\n\n"
                    . "Required: %.8f %s (including the configured safety margin)\n"
                    . "Free:     %.8f %s\n\n"
                    . "No orders were placed, so nothing is at risk — but every opportunity on this pair "
                    . "will keep being skipped until the account is funded. Note that only free balance "
                    . "counts here; anything tied up in open orders cannot be spent.\n\n"
                    . "Further shortfalls on %s/%s will be logged rather than alerted for the next %d seconds.",
                    $symbol,
                    $venueName,
                    $asset,
                    $side,
                    $required,
                    $asset,
                    $available,
                    $asset,
                    $venueName,
                    $asset,
                    $this->alertThrottleSeconds
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to alert admin about insufficient {$asset} on {$venueName}: " . $e->getMessage()
            );
        }
    }
}
