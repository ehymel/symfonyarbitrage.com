<?php

namespace App\Service;

use App\Service\ExchangeService\ExchangeServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Pre-trade check that both legs are large enough for the venues to accept them.
 *
 * Every exchange refuses orders below a floor — Kraken's ETH minimum is around 0.002,
 * Coinbase states a minimum notional per quote currency — and it enforces that floor the
 * only way an exchange ever does: by rejecting the order. With one leg already filled,
 * that is the partial-fill path, so a position gets opened and unwound at a loss over a
 * trade the venue was never going to allow. The floors are static market metadata that
 * `warmUp()` has already pulled into memory, so catching it costs a local array lookup.
 *
 * Runs before TradeFundingGuard: this one needs no network, and there is no sense
 * spending a balance round trip on an order that is too small to place.
 *
 * Reports reasons rather than verdicts, because unlike a funding shortfall the caller
 * treats every outcome the same way. A too-small order is not a venue fault, so nothing
 * here is charged to the circuit breaker, and it is not a surprise either: it follows
 * deterministically from the --size the operator chose, which is also how it gets fixed.
 */
final readonly class MinimumOrderSizeGuard
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * @param float $amount    base quantity both legs will trade
     * @param float $buyPrice  price the buy leg was quoted at
     * @param float $sellPrice price the sell leg was quoted at
     *
     * @return list<string> why the trade cannot be placed as sized; empty means both legs
     *                      clear their venue's floor
     */
    public function reasonsToSkip(
        ExchangeServiceInterface $buyVenue,
        string $buyVenueName,
        ExchangeServiceInterface $sellVenue,
        string $sellVenueName,
        string $symbol,
        float $amount,
        float $buyPrice,
        float $sellPrice,
    ): array {
        return array_values(array_filter([
            $this->reasonLegIsTooSmall($buyVenue, $buyVenueName, $symbol, 'buy', $amount, $buyPrice),
            $this->reasonLegIsTooSmall($sellVenue, $sellVenueName, $symbol, 'sell', $amount, $sellPrice),
        ]));
    }

    private function reasonLegIsTooSmall(
        ExchangeServiceInterface $venue,
        string $venueName,
        string $symbol,
        string $side,
        float $amount,
        float $price,
    ): ?string {
        $minimums = $venue->getMinimumOrderSize($symbol);

        if ($minimums === null) {
            // Allowed through deliberately. Warming is best effort, and ccxt loads markets
            // lazily inside create_order anyway, so the venue still gets to enforce its own
            // floor — we simply lose the early warning. Blocking instead would take the
            // whole system offline over a boot-time hiccup, which is the worse failure.
            $this->logger->warning(sprintf(
                'No market data for %s on %s, so the %s leg goes out unchecked against the venue minimum.',
                $symbol,
                $venueName,
                $side
            ));

            return null;
        }

        if ($minimums['amount'] !== null && $amount < $minimums['amount']) {
            return sprintf(
                '%s will not %s less than %s %s and this trade is %s',
                $venueName,
                $side,
                $this->trim($minimums['amount']),
                $symbol,
                $this->trim($amount)
            );
        }

        // Checked second because a venue stating both floors usually has the notional one
        // bite first at small sizes, and the quantity message reads better when it is the
        // quantity that is genuinely the problem.
        $cost = $amount * $price;

        if ($minimums['cost'] !== null && $cost < $minimums['cost']) {
            return sprintf(
                '%s will not %s less than %s of %s and this trade is %s',
                $venueName,
                $side,
                $this->money($minimums['cost']),
                $symbol,
                $this->money($cost)
            );
        }

        return null;
    }

    /**
     * Crypto quantities span eight decimals at one end and whole units at the other, so
     * the trailing zeros come off rather than printing 0.00200000 next to 2.00000000.
     */
    private function trim(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.');
    }

    private function money(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}
