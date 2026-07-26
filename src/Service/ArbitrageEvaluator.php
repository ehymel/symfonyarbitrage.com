<?php

namespace App\Service;

use App\Dto\ArbitrageOpportunityDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ArbitrageEvaluator
{
    /**
     * Taker Fee Percentages (e.g., 0.002 = 0.20%)
     * Adjust these to match your exact fee tier on each exchange.
     */
    private array $takerFees = [
        'coinbase' => 0.0040, // 0.40%
        'kraken'   => 0.0026, // 0.26%
        'binance'  => 0.0010, // 0.10%
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        /**
         * Whether to narrate the spreads that were looked at and passed over.
         *
         * Wired to kernel.debug, so it is on in dev and off in prod. The scanner evaluates
         * every pair in both directions four times a second, and this reports on most of
         * them — useful while working out whether a market has any edge in it, intolerable
         * as a standing production log. Bound the volume with --limit rather than by
         * reading less.
         */
        #[Autowire('%kernel.debug%')]
        private readonly bool             $reportNearMisses = false,
    ) {
    }

    /**
     * Evaluates whether a valid spread exists from Exchange A (Buy) to Exchange B (Sell).
     *
     * A null return means "no tradeable spread right now" — an expected, routine outcome.
     * A misconfigured position size is not that, so it throws instead of going quiet.
     *
     * @throws \InvalidArgumentException if the target trade size is not positive
     */
    public function evaluate(
        string $symbol,
        string $buyExchangeName,
        array $buyOrderBook,
        string $sellExchangeName,
        array $sellOrderBook,
        float $targetAmountUsd = 100.0, // Target trade size in USD
        float $minNetMarginPct = 0.0035 // Min profit threshold (0.35%)
    ): ?ArbitrageOpportunityDto {

        // A non-positive target fills trivially with zero quantity, which would divide by
        // zero when deriving the effective prices below.
        if ($targetAmountUsd <= 0.0) {
            throw new \InvalidArgumentException(
                sprintf('Target trade size must be positive, got %s.', $targetAmountUsd)
            );
        }

        $asks = $buyOrderBook['asks'] ?? []; // Sellers on Exchange A [[price, qty], ...]
        $bids = $sellOrderBook['bids'] ?? []; // Buyers on Exchange B [[price, qty], ...]

        if (empty($asks) || empty($bids)) {
            $this->reportNearMiss($symbol, $buyExchangeName, $sellExchangeName, sprintf(
                'nothing to compare — %d ask levels on %s, %d bid levels on %s',
                count($asks),
                $buyExchangeName,
                count($bids),
                $sellExchangeName
            ));

            return null;
        }

        // 1. Calculate weighted average BUY price to acquire the target amount
        $buyResult = $this->calculateBuyCost($asks, $targetAmountUsd);
        if (!$buyResult['filled']) {
            // The order is too *large* for the book, not too small: every visible ask has
            // been consumed and the target notional is still not met.
            $this->reportNearMiss($symbol, $buyExchangeName, $sellExchangeName, sprintf(
                'the whole %s ask side is only $%s, short of the $%s target',
                $buyExchangeName,
                number_format($buyResult['total_cost_usd'], 2),
                number_format($targetAmountUsd, 2)
            ));

            return null; // Insufficient liquidity on order book
        }

        $cryptoQty = $buyResult['qty'];
        $totalBuyCostUsd = $buyResult['total_cost_usd'];
        $effectiveBuyPrice = $totalBuyCostUsd / $cryptoQty;

        // 2. Calculate weighted average SELL revenue for that exact crypto quantity
        $sellResult = $this->calculateSellRevenue($bids, $cryptoQty);
        if (!$sellResult['filled']) {
            $this->reportNearMiss($symbol, $buyExchangeName, $sellExchangeName, sprintf(
                'the whole %s bid side takes only %s of the %s units the buy would acquire',
                $sellExchangeName,
                rtrim(rtrim(number_format($sellResult['qty'], 8), '0'), '.'),
                rtrim(rtrim(number_format($cryptoQty, 8), '0'), '.')
            ));

            return null; // Insufficient bid liquidity on Exchange B
        }

        $totalSellRevenueUsd = $sellResult['total_revenue_usd'];
        $effectiveSellPrice = $totalSellRevenueUsd / $cryptoQty;

        // 3. Subtract Taker Fees
        $buyFeeRate = $this->takerFees[strtolower($buyExchangeName)] ?? 0.0040;
        $sellFeeRate = $this->takerFees[strtolower($sellExchangeName)] ?? 0.0040;

        $buyFeeUsd = $totalBuyCostUsd * $buyFeeRate;
        $sellFeeUsd = $totalSellRevenueUsd * $sellFeeRate;
        $totalFeesUsd = $buyFeeUsd + $sellFeeUsd;

        // 4. Net-Profit Calculation
        $netProfitUsd = ($totalSellRevenueUsd - $totalBuyCostUsd) - $totalFeesUsd;
        $netMarginPct = $netProfitUsd / $totalBuyCostUsd;

        $grossSpreadPct = ($effectiveSellPrice - $effectiveBuyPrice) / $effectiveBuyPrice;

        // 5. Margin Threshold Check
        if ($netMarginPct < $minNetMarginPct) {
            // Only worth narrating when the venues really were priced apart in this
            // direction. The scanner evaluates both directions of every pair, so one of
            // the two is always the wrong way round — reporting those would bury the near
            // misses under a running commentary on the market being normal.
            if ($grossSpreadPct > 0.0) {
                $this->reportNearMiss($symbol, $buyExchangeName, $sellExchangeName, sprintf(
                    '%s%% gross spread nets %s%% (%s on $%s) after fees, under the %s%% floor',
                    number_format($grossSpreadPct * 100, 4),
                    number_format($netMarginPct * 100, 4),
                    // Sign outside the currency symbol, so a trade that loses money at this
                    // size reads as -$0.03 rather than $-0.03.
                    sprintf('%s$%s', $netProfitUsd < 0.0 ? '-' : '', number_format(abs($netProfitUsd), 4)),
                    number_format($totalBuyCostUsd, 2),
                    number_format($minNetMarginPct * 100, 4)
                ));
            }

            return null;
        }

        return new ArbitrageOpportunityDto(
            pair: $symbol,
            buyExchange: $buyExchangeName,
            sellExchange: $sellExchangeName,
            buyPrice: $effectiveBuyPrice,
            sellPrice: $effectiveSellPrice,
            amount: $cryptoQty,
            grossSpreadPct: $grossSpreadPct,
            netProfitUsd: $netProfitUsd
        );
    }

    /**
     * Narrates a spread that was looked at and passed over.
     *
     * Notice rather than info, so it reaches the console at -v while the scanner's own
     * output stays readable without it; either way it lands in the dev log. Silent in
     * production — see the constructor.
     */
    private function reportNearMiss(
        string $symbol,
        string $buyExchangeName,
        string $sellExchangeName,
        string $reason,
    ): void {
        if (!$this->reportNearMisses || $this->logger === null) {
            return;
        }

        $this->logger->notice(sprintf(
            '🔍 No trade: %s buy %s / sell %s — %s',
            $symbol,
            $buyExchangeName,
            $sellExchangeName,
            $reason
        ));
    }

    /**
     * The accumulated totals come back on failure as well as success: they are what makes
     * a near-miss report say how far short the book fell rather than merely that it did.
     */
    private function calculateBuyCost(array $asks, float $targetUsd): array
    {
        $accumulatedUsd = 0.0;
        $accumulatedQty = 0.0;

        foreach ($asks as [$price, $qty]) {
            $levelCostUsd = $price * $qty;

            if (($accumulatedUsd + $levelCostUsd) >= $targetUsd) {
                $neededUsd = $targetUsd - $accumulatedUsd;
                $neededQty = $neededUsd / $price;

                $accumulatedQty += $neededQty;
                $accumulatedUsd += $neededUsd;

                return ['filled' => true, 'qty' => $accumulatedQty, 'total_cost_usd' => $accumulatedUsd];
            }

            $accumulatedUsd += $levelCostUsd;
            $accumulatedQty += $qty;
        }

        return ['filled' => false, 'qty' => $accumulatedQty, 'total_cost_usd' => $accumulatedUsd];
    }

    private function calculateSellRevenue(array $bids, float $targetQty): array
    {
        $accumulatedQty = 0.0;
        $accumulatedRevenueUsd = 0.0;

        foreach ($bids as [$price, $qty]) {
            if (($accumulatedQty + $qty) >= $targetQty) {
                $neededQty = $targetQty - $accumulatedQty;
                $accumulatedRevenueUsd += ($neededQty * $price);
                $accumulatedQty += $neededQty;

                return ['filled' => true, 'total_revenue_usd' => $accumulatedRevenueUsd];
            }

            $accumulatedQty += $qty;
            $accumulatedRevenueUsd += ($qty * $price);
        }

        return ['filled' => false, 'qty' => $accumulatedQty, 'total_revenue_usd' => $accumulatedRevenueUsd];
    }
}
