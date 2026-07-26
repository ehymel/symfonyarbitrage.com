<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ArbitrageOpportunityDto;
use App\Service\ArbitrageEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The evaluator is pure arithmetic over two order books, so every case here is
 * hand-computed rather than derived from the implementation: the expected values
 * below are what the trade *should* net, which is what makes the tests worth having.
 *
 * Shorthand used throughout: a book level is [price, quantity].
 */
#[CoversClass(ArbitrageEvaluator::class)]
final class ArbitrageEvaluatorTest extends TestCase
{
    private const float DELTA = 1e-9;

    private ArbitrageEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ArbitrageEvaluator();
    }

    // ------------------------------------------------------- HAPPY PATH MATH

    /**
     * Buy 1.0 unit at 100 on binance (0.10% taker), sell at 110 on kraken (0.26% taker).
     *   cost      = 100.00      revenue = 110.00
     *   buy fee   = 100 * 0.0010 = 0.100
     *   sell fee  = 110 * 0.0026 = 0.286
     *   net       = (110 - 100) - 0.386 = 9.614  ->  9.614% margin
     */
    public function testProfitableSpreadIsReturnedWithFullyCostedNumbers(): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            buyExchange: 'binance',
            sellExchange: 'kraken',
        );

        self::assertInstanceOf(ArbitrageOpportunityDto::class, $opportunity);
        self::assertSame('BTC/USD', $opportunity->pair);
        self::assertSame('binance', $opportunity->buyExchange);
        self::assertSame('kraken', $opportunity->sellExchange);
        self::assertEqualsWithDelta(100.0, $opportunity->buyPrice, self::DELTA);
        self::assertEqualsWithDelta(110.0, $opportunity->sellPrice, self::DELTA);
        self::assertEqualsWithDelta(1.0, $opportunity->amount, self::DELTA);
        self::assertEqualsWithDelta(0.10, $opportunity->grossSpreadPct, self::DELTA);
        self::assertEqualsWithDelta(9.614, $opportunity->netProfitUsd, self::DELTA);
    }

    public function testGrossSpreadIgnoresFeesWhileNetProfitDoesNot(): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            buyExchange: 'coinbase',
            sellExchange: 'coinbase',
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta(0.10, $opportunity->grossSpreadPct, self::DELTA, 'gross spread is fee-blind');
        self::assertEqualsWithDelta(9.16, $opportunity->netProfitUsd, self::DELTA, '10 - (0.40 + 0.44)');
    }

    public function testTheTradeIsAlwaysSizedToTheTargetNotional(): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 0.5], [102.0, 5.0]],
            bids: [[130.0, 10.0]],
            targetAmountUsd: 250.0,
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta(
            250.0,
            $opportunity->buyPrice * $opportunity->amount,
            1e-6,
            'effective buy price x quantity must reconstitute the target spend'
        );
    }

    // ------------------------------------------------------ ORDER BOOK WALK

    /**
     * $100 of depth cannot be taken at the top level alone, so the fill walks down:
     *   level 1: 100 x 0.5 = $50   (consumed whole)
     *   level 2: $50 remaining / 102 = 0.490196... units
     *   qty = 0.990196...  ->  effective buy price = 100 / 0.990196... = 100.990099...
     */
    public function testBuySideWalksMultipleAskLevelsAndWeightsThePrice(): void
    {
        $expectedQty = 0.5 + (50.0 / 102.0);

        $opportunity = $this->evaluate(
            asks: [[100.0, 0.5], [102.0, 5.0]],
            bids: [[200.0, 10.0]],
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta($expectedQty, $opportunity->amount, self::DELTA);
        self::assertEqualsWithDelta(100.0 / $expectedQty, $opportunity->buyPrice, self::DELTA);
    }

    /**
     * Selling 2.0 units into a thin top-of-book:
     *   level 1: 0.5 @ 110 = $55.0
     *   level 2: 1.5 @ 105 = $157.5
     *   revenue = $212.50  ->  effective sell price = 106.25
     */
    public function testSellSideWalksMultipleBidLevelsAndWeightsThePrice(): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 2.0]],
            bids: [[110.0, 0.5], [105.0, 3.0]],
            buyExchange: 'binance',
            sellExchange: 'binance',
            targetAmountUsd: 200.0,
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta(2.0, $opportunity->amount, self::DELTA);
        self::assertEqualsWithDelta(106.25, $opportunity->sellPrice, self::DELTA);
        self::assertEqualsWithDelta(0.0625, $opportunity->grossSpreadPct, self::DELTA);
        self::assertEqualsWithDelta(12.0875, $opportunity->netProfitUsd, self::DELTA, '12.50 - (0.20 + 0.2125)');
    }

    public function testDepthBeyondTheFillPointIsIgnored(): void
    {
        $shallow = $this->evaluate(asks: [[100.0, 10.0]], bids: [[110.0, 10.0]]);
        $deep = $this->evaluate(
            asks: [[100.0, 10.0], [500.0, 99.0]],
            bids: [[110.0, 10.0], [1.0, 99.0]],
        );

        self::assertNotNull($shallow);
        self::assertNotNull($deep);
        self::assertEqualsWithDelta($shallow->netProfitUsd, $deep->netProfitUsd, self::DELTA);
        self::assertEqualsWithDelta($shallow->amount, $deep->amount, self::DELTA);
    }

    public function testAskLevelsAreConsumedInTheOrderGivenNotSorted(): void
    {
        // Documents a caller precondition: books must arrive sorted (asks ascending).
        // Given an unsorted book the evaluator takes the expensive level first.
        $opportunity = $this->evaluate(
            asks: [[200.0, 1.0], [100.0, 1.0]],
            bids: [[300.0, 10.0]],
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta(200.0, $opportunity->buyPrice, self::DELTA);
        self::assertEqualsWithDelta(0.5, $opportunity->amount, self::DELTA);
    }

    public function testABookThatCoversTheTargetExactlyStillFills(): void
    {
        // $100 target against exactly $100 of asks, and a bid side holding exactly 1.0 unit.
        $opportunity = $this->evaluate(
            asks: [[100.0, 1.0]],
            bids: [[110.0, 1.0]],
        );

        self::assertNotNull($opportunity, 'the fill check is >=, so an exact match is a fill');
        self::assertEqualsWithDelta(1.0, $opportunity->amount, self::DELTA);
    }

    // ------------------------------------------------------------ REJECTIONS

    #[DataProvider('unusableBookProvider')]
    public function testUnusableBooksYieldNoOpportunity(array $buyBook, array $sellBook, string $why): void
    {
        $opportunity = $this->evaluator->evaluate('BTC/USD', 'binance', $buyBook, 'kraken', $sellBook);

        self::assertNull($opportunity, $why);
    }

    public static function unusableBookProvider(): iterable
    {
        $goodAsks = ['asks' => [[100.0, 10.0]]];
        $goodBids = ['bids' => [[110.0, 10.0]]];

        yield 'empty ask list' => [['asks' => []], $goodBids, 'nothing to buy'];
        yield 'empty bid list' => [$goodAsks, ['bids' => []], 'nothing to sell into'];
        yield 'missing asks key' => [[], $goodBids, 'a malformed buy book must not fatal'];
        yield 'missing bids key' => [$goodAsks, [], 'a malformed sell book must not fatal'];
        yield 'both books empty' => [[], [], 'no data at all'];
        yield 'bids present on the buy book only' => [$goodBids, $goodBids, 'the buy side reads asks, not bids'];
        yield 'asks present on the sell book only' => [$goodAsks, $goodAsks, 'the sell side reads bids, not asks'];
    }

    public function testInsufficientAskLiquidityYieldsNoOpportunity(): void
    {
        // Only $50 of depth against the default $100 target.
        $opportunity = $this->evaluate(
            asks: [[100.0, 0.3], [100.0, 0.2]],
            bids: [[500.0, 10.0]],
        );

        self::assertNull($opportunity);
    }

    public function testInsufficientBidLiquidityYieldsNoOpportunity(): void
    {
        // The buy side fills 1.0 unit but the sell side can only absorb 0.5.
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[500.0, 0.5]],
        );

        self::assertNull($opportunity, 'a half-exitable position is not an arbitrage');
    }

    public function testInvertedSpreadYieldsNoOpportunity(): void
    {
        $opportunity = $this->evaluate(
            asks: [[110.0, 10.0]],
            bids: [[100.0, 10.0]],
        );

        self::assertNull($opportunity);
    }

    /**
     * A 0.50% gross spread that fees eat down to 0.2995% net, under the 0.35% default floor.
     *   net = 0.50 - (100 * 0.001 + 100.5 * 0.001) = 0.2995
     */
    public function testSpreadThatFeesEatBelowTheMarginFloorIsRejected(): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[100.5, 10.0]],
            buyExchange: 'binance',
            sellExchange: 'binance',
        );

        self::assertNull($opportunity, 'gross spread was positive but net margin was only 0.2995%');
    }

    public function testTheSameSpreadClearsTheFloorOnceTheThresholdIsLowered(): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[100.5, 10.0]],
            buyExchange: 'binance',
            sellExchange: 'binance',
            minNetMarginPct: 0.001,
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta(0.2995, $opportunity->netProfitUsd, self::DELTA);
    }

    // ------------------------------------------------------------- THRESHOLD

    public function testMarginFloorIsInclusiveOfTradesThatMeetIt(): void
    {
        $asks = [[100.0, 10.0]];
        $bids = [[110.0, 10.0]];

        $margin = $this->evaluate(asks: $asks, bids: $bids, minNetMarginPct: 0.0)->netProfitUsd / 100.0;

        self::assertNotNull(
            $this->evaluate(asks: $asks, bids: $bids, minNetMarginPct: $margin * 0.999),
            'a trade comfortably above the floor must pass'
        );
        self::assertNull(
            $this->evaluate(asks: $asks, bids: $bids, minNetMarginPct: $margin * 1.001),
            'a trade just under the floor must be rejected'
        );
    }

    public function testAZeroFloorStillRejectsALossMakingTrade(): void
    {
        // Gross spread of 0.05% is entirely consumed by coinbase's 0.40% fees.
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[100.05, 10.0]],
            buyExchange: 'coinbase',
            sellExchange: 'coinbase',
            minNetMarginPct: 0.0,
        );

        self::assertNull($opportunity);
    }

    // ------------------------------------------------------------------ FEES

    /**
     * Same $100 -> $110 trade on every fee tier. Net = 10 - (100 * f + 110 * f).
     */
    #[DataProvider('feeTierProvider')]
    public function testTakerFeesAreAppliedPerExchange(string $exchange, float $expectedNetProfit): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            buyExchange: $exchange,
            sellExchange: $exchange,
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta($expectedNetProfit, $opportunity->netProfitUsd, self::DELTA);
    }

    public static function feeTierProvider(): iterable
    {
        yield 'coinbase 0.40%' => ['coinbase', 9.16];
        yield 'kraken 0.26%' => ['kraken', 9.454];
        yield 'binance 0.10%' => ['binance', 9.79];
        yield 'unknown venue falls back to 0.40%' => ['some-new-dex', 9.16];
    }

    #[DataProvider('exchangeCasingProvider')]
    public function testExchangeNamesAreMatchedCaseInsensitively(string $buy, string $sell): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            buyExchange: $buy,
            sellExchange: $sell,
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta(
            9.614,
            $opportunity->netProfitUsd,
            self::DELTA,
            'binance buy (0.10%) + kraken sell (0.26%) regardless of casing'
        );
    }

    public static function exchangeCasingProvider(): iterable
    {
        yield 'lowercase' => ['binance', 'kraken'];
        yield 'uppercase' => ['BINANCE', 'KRAKEN'];
        yield 'title case' => ['Binance', 'Kraken'];
        yield 'mixed' => ['bInAnCe', 'KrAkEn'];
    }

    public function testTheExchangeNamesAreEchoedBackVerbatimNotNormalised(): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            buyExchange: 'Binance',
            sellExchange: 'Kraken',
        );

        self::assertNotNull($opportunity);
        self::assertSame('Binance', $opportunity->buyExchange, 'casing is only normalised for the fee lookup');
        self::assertSame('Kraken', $opportunity->sellExchange);
    }

    public function testFeeAsymmetryBetweenLegsIsRespected(): void
    {
        $cheapBuy = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            buyExchange: 'binance',
            sellExchange: 'coinbase',
        );
        $cheapSell = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            buyExchange: 'coinbase',
            sellExchange: 'binance',
        );

        self::assertNotNull($cheapBuy);
        self::assertNotNull($cheapSell);
        self::assertEqualsWithDelta(9.46, $cheapBuy->netProfitUsd, self::DELTA, '10 - (0.10 + 0.44)');
        self::assertEqualsWithDelta(9.49, $cheapSell->netProfitUsd, self::DELTA, '10 - (0.40 + 0.11)');
        self::assertGreaterThan(
            $cheapBuy->netProfitUsd,
            $cheapSell->netProfitUsd,
            'the larger fee should land on the smaller leg — here the buy leg is the cheaper notional'
        );
    }

    // -------------------------------------------------------------- DEFAULTS

    public function testTargetNotionalDefaultsToOneHundredDollars(): void
    {
        $explicit = $this->evaluate(asks: [[100.0, 10.0]], bids: [[110.0, 10.0]], targetAmountUsd: 100.0);
        $default = $this->evaluator->evaluate(
            'BTC/USD',
            'binance',
            ['asks' => [[100.0, 10.0]]],
            'kraken',
            ['bids' => [[110.0, 10.0]]],
        );

        self::assertNotNull($default);
        self::assertNotNull($explicit);
        self::assertEqualsWithDelta($explicit->amount, $default->amount, self::DELTA);
        self::assertEqualsWithDelta($explicit->netProfitUsd, $default->netProfitUsd, self::DELTA);
    }

    public function testLargerTargetNotionalScalesProfitAndWalksDeeper(): void
    {
        // $1000 target: 5.0 units @ 100 ($500), then $500 / 110 = 4.5454... units.
        $expectedQty = 5.0 + (500.0 / 110.0);

        $opportunity = $this->evaluate(
            asks: [[100.0, 5.0], [110.0, 10.0]],
            bids: [[200.0, 20.0]],
            buyExchange: 'binance',
            sellExchange: 'binance',
            targetAmountUsd: 1000.0,
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta($expectedQty, $opportunity->amount, self::DELTA);
        self::assertEqualsWithDelta(1000.0 / $expectedQty, $opportunity->buyPrice, self::DELTA);
        self::assertEqualsWithDelta(200.0, $opportunity->sellPrice, self::DELTA);
    }

    public function testSymbolIsCarriedThroughToTheOpportunity(): void
    {
        $opportunity = $this->evaluator->evaluate(
            'ETH/USDT',
            'binance',
            ['asks' => [[100.0, 10.0]]],
            'kraken',
            ['bids' => [[110.0, 10.0]]],
        );

        self::assertNotNull($opportunity);
        self::assertSame('ETH/USDT', $opportunity->pair);
    }

    // ------------------------------------------------------ INPUT VALIDATION

    #[DataProvider('nonPositiveTargetProvider')]
    public function testNonPositiveTargetNotionalIsRejected(float $targetAmountUsd): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            targetAmountUsd: $targetAmountUsd,
        );
    }

    public static function nonPositiveTargetProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative cent' => [-0.01];
        yield 'negative notional' => [-100.0];
    }

    public function testTheRejectionNamesTheOffendingValue(): void
    {
        $this->expectExceptionMessage('Target trade size must be positive, got 0.');

        $this->evaluate(asks: [[100.0, 10.0]], bids: [[110.0, 10.0]], targetAmountUsd: 0.0);
    }

    /**
     * The guard is a caller contract, not a market condition, so it must fire even when
     * the books are unusable — otherwise a bad position size hides behind a routine null.
     */
    public function testTargetIsValidatedBeforeTheOrderBooksAreInspected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->evaluator->evaluate('BTC/USD', 'binance', [], 'kraken', [], 0.0);
    }

    /**
     * The floor is exclusive: any positive notional is legitimate, however small.
     * Profit scales linearly with size while the margin stays put.
     */
    public function testATinyButPositiveTargetIsAccepted(): void
    {
        $opportunity = $this->evaluate(
            asks: [[100.0, 10.0]],
            bids: [[110.0, 10.0]],
            targetAmountUsd: 0.01,
        );

        self::assertNotNull($opportunity);
        self::assertEqualsWithDelta(0.0001, $opportunity->amount, self::DELTA);
        self::assertEqualsWithDelta(0.0009614, $opportunity->netProfitUsd, self::DELTA, '9.614 scaled by 1/10000');
        self::assertEqualsWithDelta(
            0.09614,
            $opportunity->netProfitUsd / 0.01,
            self::DELTA,
            'net margin is independent of position size'
        );
    }

    // ----------------------------------------------------------------- HELPER

    /**
     * @param list<array{0: float, 1: float}> $asks levels on the buy venue
     * @param list<array{0: float, 1: float}> $bids levels on the sell venue
     */
    private function evaluate(
        array $asks,
        array $bids,
        string $buyExchange = 'binance',
        string $sellExchange = 'kraken',
        float $targetAmountUsd = 100.0,
        float $minNetMarginPct = 0.0035,
        string $symbol = 'BTC/USD',
    ): ?ArbitrageOpportunityDto {
        return $this->evaluator->evaluate(
            $symbol,
            $buyExchange,
            ['asks' => $asks],
            $sellExchange,
            ['bids' => $bids],
            $targetAmountUsd,
            $minNetMarginPct,
        );
    }
}
