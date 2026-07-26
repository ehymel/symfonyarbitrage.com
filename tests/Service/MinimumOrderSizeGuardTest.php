<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ExchangeService\ExchangeServiceInterface;
use App\Service\MinimumOrderSizeGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The gate exists because an exchange enforces its size floor the same way it enforces
 * everything else — by rejecting the order, with the other leg already filled. So the
 * cases that matter are the ones either side of a floor, and the one where the floor is
 * not known: getting that last one wrong in the cautious direction would stop all trading
 * over a boot-time hiccup.
 *
 * Sizing throughout: 2.0 units at 100.0 on the buy side and 110.0 on the sell, so the
 * legs are worth $200.00 and $220.00.
 */
#[CoversClass(MinimumOrderSizeGuard::class)]
final class MinimumOrderSizeGuardTest extends TestCase
{
    private const string BUY_VENUE = 'coinbase';
    private const string SELL_VENUE = 'kraken';
    private const string SYMBOL = 'ETH/USDT';
    private const float AMOUNT = 2.0;
    private const float BUY_PRICE = 100.0;
    private const float SELL_PRICE = 110.0;

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        $this->loggedMessages = [];
    }

    // ------------------------------------------------------------------ ACCEPTED

    public function testAnOrderClearingBothFloorsOnBothVenuesIsAccepted(): void
    {
        $reasons = $this->check(
            buyMinimums: ['amount' => 0.001, 'cost' => 5.0],
            sellMinimums: ['amount' => 0.002, 'cost' => 10.0],
        );

        self::assertSame([], $reasons);
    }

    /** ccxt states null where a venue publishes no floor, which constrains nothing. */
    public function testAVenueStatingNoFloorAcceptsAnything(): void
    {
        $reasons = $this->check(
            buyMinimums: ['amount' => null, 'cost' => null],
            sellMinimums: ['amount' => null, 'cost' => null],
            amount: 0.00000001,
        );

        self::assertSame([], $reasons);
    }

    /** The floor is inclusive: an order exactly on it is one the venue will take. */
    public function testAnOrderExactlyOnTheFloorIsAccepted(): void
    {
        $reasons = $this->check(
            buyMinimums: ['amount' => self::AMOUNT, 'cost' => 200.0],
            sellMinimums: ['amount' => self::AMOUNT, 'cost' => 220.0],
        );

        self::assertSame([], $reasons);
    }

    // ------------------------------------------------------------------- REFUSED

    public function testAQuantityUnderTheVenueFloorIsRefusedWithBothFigures(): void
    {
        $reasons = $this->check(
            sellMinimums: ['amount' => 0.002, 'cost' => null],
            amount: 0.0001,
        );

        self::assertSame(
            ['kraken will not sell less than 0.002 ETH/USDT and this trade is 0.0001'],
            $reasons
        );
    }

    /**
     * The one that bites a small test position first: Coinbase mostly publishes a notional
     * floor rather than a quantity one, so a $10 run trips the cost check.
     */
    public function testANotionalUnderTheVenueFloorIsRefusedWithBothFigures(): void
    {
        $reasons = $this->check(
            buyMinimums: ['amount' => null, 'cost' => 5.0],
            amount: 0.01, // $1.00 at the quoted 100.0
        );

        self::assertSame(
            ['coinbase will not buy less than $5.00 of ETH/USDT and this trade is $1.00'],
            $reasons
        );
    }

    /** Each leg is priced on its own side of the book, so the notionals differ. */
    public function testEachLegIsMeasuredAgainstItsOwnPrice(): void
    {
        // 2.0 units is $200 buying at 100 and $220 selling at 110, so a $210 floor
        // catches the buy leg and clears the sell.
        $reasons = $this->check(
            buyMinimums: ['amount' => null, 'cost' => 210.0],
            sellMinimums: ['amount' => null, 'cost' => 210.0],
        );

        self::assertSame(
            ['coinbase will not buy less than $210.00 of ETH/USDT and this trade is $200.00'],
            $reasons
        );
    }

    public function testBothLegsTooSmallAreBothReported(): void
    {
        $reasons = $this->check(
            buyMinimums: ['amount' => null, 'cost' => 500.0],
            sellMinimums: ['amount' => 5.0, 'cost' => null],
        );

        self::assertCount(2, $reasons);
        self::assertStringContainsString('coinbase will not buy less than $500.00', $reasons[0]);
        self::assertStringContainsString('kraken will not sell less than 5 ', $reasons[1]);
    }

    /**
     * A venue publishing both floors usually has the notional one bite first at small
     * sizes, but when the quantity is what is genuinely short that is what gets said.
     */
    public function testTheQuantityFloorIsReportedAheadOfTheNotionalOne(): void
    {
        $reasons = $this->check(
            sellMinimums: ['amount' => 5.0, 'cost' => 1_000.0],
        );

        self::assertCount(1, $reasons);
        self::assertStringContainsString('less than 5 ETH/USDT', $reasons[0]);
    }

    /** Quantities span eight decimals to whole units; neither should print padded. */
    #[DataProvider('quantityFormattingProvider')]
    public function testQuantitiesReadCleanlyAtEitherEndOfTheScale(float $floor, string $expected): void
    {
        $reasons = $this->check(
            sellMinimums: ['amount' => $floor, 'cost' => null],
            amount: 0.00000001,
        );

        self::assertStringContainsString("less than {$expected} ETH/USDT", $reasons[0]);
    }

    public static function quantityFormattingProvider(): iterable
    {
        yield 'whole units' => [2.0, '2'];
        yield 'two decimals' => [0.05, '0.05'];
        yield 'satoshi scale' => [0.0001, '0.0001'];
    }

    // ------------------------------------------------------------------- UNKNOWN

    /**
     * Deliberately permissive, and the one place this guard does not fail closed. Warming
     * is best effort by design, ccxt loads markets lazily inside create_order anyway, and
     * blocking every trade over missing metadata is a worse failure than losing the early
     * warning.
     */
    public function testAVenueWithNoMarketDataLetsTheLegThrough(): void
    {
        $reasons = $this->check(sellMinimums: null);

        self::assertSame([], $reasons);
    }

    public function testAnUncheckableLegSaysSoInTheLog(): void
    {
        $this->check(sellMinimums: null);

        self::assertSame(
            ['No market data for ETH/USDT on kraken, so the sell leg goes out unchecked against the venue minimum.'],
            $this->logMessages(LogLevel::WARNING)
        );
    }

    /** One venue being unwarmed must not stop the other's floor from being enforced. */
    public function testAnUncheckableLegDoesNotSuppressTheOtherLegsRefusal(): void
    {
        $reasons = $this->check(
            buyMinimums: null,
            sellMinimums: ['amount' => 5.0, 'cost' => null],
        );

        self::assertCount(1, $reasons);
        self::assertStringContainsString('kraken will not sell less than 5', $reasons[0]);
        self::assertCount(1, $this->logMessages(LogLevel::WARNING));
    }

    // -------------------------------------------------------------------- HELPERS

    /**
     * @param array{amount: float|null, cost: float|null}|null $buyMinimums
     * @param array{amount: float|null, cost: float|null}|null $sellMinimums
     *
     * @return list<string>
     */
    private function check(
        ?array $buyMinimums = ['amount' => null, 'cost' => null],
        ?array $sellMinimums = ['amount' => null, 'cost' => null],
        float $amount = self::AMOUNT,
    ): array {
        $guard = new MinimumOrderSizeGuard($this->recordingLogger());

        return $guard->reasonsToSkip(
            $this->venue($buyMinimums),
            self::BUY_VENUE,
            $this->venue($sellMinimums),
            self::SELL_VENUE,
            self::SYMBOL,
            $amount,
            self::BUY_PRICE,
            self::SELL_PRICE,
        );
    }

    private function venue(?array $minimums): ExchangeServiceInterface
    {
        $venue = $this->createStub(ExchangeServiceInterface::class);
        $venue->method('getMinimumOrderSize')->willReturn($minimums);

        return $venue;
    }

    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        $logger->method('warning')->willReturnCallback(
            function (string|\Stringable $message): void {
                $this->loggedMessages[LogLevel::WARNING][] = (string) $message;
            }
        );

        return $logger;
    }

    /**
     * @return list<string>
     */
    private function logMessages(string $level): array
    {
        return $this->loggedMessages[$level] ?? [];
    }
}
