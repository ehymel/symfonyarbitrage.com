<?php

namespace App\Service;

use App\Dto\ArbitrageOpportunityDto;

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
            return null;
        }

        // 1. Calculate weighted average BUY price to acquire the target amount
        $buyResult = $this->calculateBuyCost($asks, $targetAmountUsd);
        if (!$buyResult['filled']) {
            return null; // Insufficient liquidity on order book
        }

        $cryptoQty = $buyResult['qty'];
        $totalBuyCostUsd = $buyResult['total_cost_usd'];
        $effectiveBuyPrice = $totalBuyCostUsd / $cryptoQty;

        // 2. Calculate weighted average SELL revenue for that exact crypto quantity
        $sellResult = $this->calculateSellRevenue($bids, $cryptoQty);
        if (!$sellResult['filled']) {
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

        // 5. Margin Threshold Check
        if ($netMarginPct < $minNetMarginPct) {
            return null;
        }

        $grossSpreadPct = ($effectiveSellPrice - $effectiveBuyPrice) / $effectiveBuyPrice;

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

        return ['filled' => false];
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

        return ['filled' => false];
    }
}
