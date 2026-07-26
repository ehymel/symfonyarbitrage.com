<?php
declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\ArbitrageOpportunity;
use App\Entity\TradeExecution;
use App\Message\ExecuteArbitrageMessage;
use App\MessageHandler\ExecuteArbitrageHandler;
use App\Service\ExchangeFactory;
use App\Service\ExchangeService\ExchangeServiceInterface;
use App\Service\TradingCircuitBreaker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

/**
 * The handler is a risk machine: it decides what gets sent to a venue, what gets
 * unwound when only one leg lands, and what ends up in the ledger. So the tests
 * below assert on the four things that cost money if they are wrong — the orders
 * placed, the unwind, what the circuit breaker is told, and the persisted row.
 *
 * The ExchangeFactory is real (it is a lookup, and driving it for real also pins
 * the case-insensitivity of venue resolution); the breaker is a recording double
 * because the contract under test is "who gets told what", not the breaker's own
 * state machine — that has its own test.
 */
#[CoversClass(ExecuteArbitrageHandler::class)]
final class ExecuteArbitrageHandlerTest extends TestCase
{
    private const string OPPORTUNITY_ID = '4711';
    private const string SYMBOL = 'ETH/USDT';
    private const string BUY_VENUE = 'coinbase';
    private const string SELL_VENUE = 'kraken';
    private const float AMOUNT = 2.0;
    private const float QUOTED_BUY = 100.0;
    private const float QUOTED_SELL = 110.0;

    private const string ADMIN_PHONE = '+15555550123';

    private const array BUY_FILL = ['id' => 'BUY-9001', 'price' => 99.5];
    private const array SELL_FILL = ['id' => 'SELL-9002', 'price' => 110.25];
    /** Flattening a long: sold back below what it cost. */
    private const array UNWIND_SELL_FILL = ['id' => 'UNWIND-9003', 'price' => 98.0];

    /** Flattening a short: bought back above what it sold for. */
    private const array UNWIND_BUY_FILL = ['id' => 'UNWIND-9004', 'price' => 112.0];

    /** @var array<string, bool> venue name => breaker verdict */
    private array $allowed = [];

    /** When set, isAllowed() returns false from this call onwards, whatever $allowed says. */
    private ?int $allowedUntilCall = null;

    /** When set, every circuit breaker call throws instead of recording. */
    private ?\Throwable $breakerOutage = null;

    /** @var array<string, array|\Throwable|\Closure> keyed "venue:side" */
    private array $outcomes = [];

    /** @var array<string, float> keyed "venue:side" — seconds on the loop before the leg settles */
    private array $delays = [];

    /** @var array<string, ExchangeServiceInterface> memoised venue stubs */
    private array $venues = [];

    /** @var list<array{venue: string, symbol: string, side: string, amount: float}> */
    private array $orders = [];

    /** @var list<string> order lifecycle markers, in the sequence they occurred */
    private array $trace = [];

    /** @var list<string> venues passed to isAllowed(), in order */
    private array $gateChecks = [];

    /** @var list<array{0: string, 1: int}> [venue, latencyMs] */
    private array $successes = [];

    /** @var list<array{0: string, 1: string}> [venue, reason] */
    private array $failures = [];

    /** @var list<array{0: string, 1: string}> [venue, reason] — outright trips, not counter increments */
    private array $trips = [];

    /** @var list<array{0: string, 1: mixed}> [entityClass, id] */
    private array $references = [];

    /** @var list<object> */
    private array $persisted = [];

    private int $flushes = 0;

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    /** @var list<SmsMessage> */
    private array $sentMessages = [];

    /** Set to have the SMS transport blow up, simulating a texting outage. */
    private ?\Throwable $texterFailure = null;

    protected function setUp(): void
    {
        $this->allowed = [];
        $this->allowedUntilCall = null;
        $this->breakerOutage = null;
        $this->trips = [];
        $this->outcomes = [];
        $this->delays = [];
        $this->venues = [];
        $this->orders = [];
        $this->trace = [];
        $this->gateChecks = [];
        $this->successes = [];
        $this->failures = [];
        $this->references = [];
        $this->persisted = [];
        $this->flushes = 0;
        $this->loggedMessages = [];
        $this->sentMessages = [];
        $this->texterFailure = null;
    }

    // ------------------------------------------------------- CIRCUIT BREAKER GATE

    public function testAnOpenBreakerOnTheBuyVenueStopsTheTradeBeforeAnyOrderIsSent(): void
    {
        $this->allowed[self::BUY_VENUE] = false;

        $this->handle();

        self::assertSame([], $this->orders, 'no capital may be committed behind an open breaker');
        self::assertSame([], $this->persisted);
        self::assertSame(0, $this->flushes);
    }

    public function testAnOpenBreakerOnTheSellVenueStopsTheTradeBeforeAnyOrderIsSent(): void
    {
        $this->allowed[self::SELL_VENUE] = false;

        $this->handle();

        self::assertSame([], $this->orders, 'a buy with no viable exit is not an arbitrage');
        self::assertSame([], $this->persisted);
    }

    public function testTheAbortIsLoggedAsAWarningNamingTheOpportunity(): void
    {
        $this->allowed[self::SELL_VENUE] = false;

        $this->handle();

        self::assertSame(
            ['Execution aborted by Circuit Breaker for opportunity 4711'],
            $this->logMessages(LogLevel::WARNING)
        );
    }

    public function testTheSellVenueIsNotConsultedOnceTheBuyVenueHasBlockedTheTrade(): void
    {
        $this->allowed[self::BUY_VENUE] = false;

        $this->handle();

        self::assertSame([self::BUY_VENUE], $this->gateChecks, 'the gate short-circuits');
    }

    public function testBothVenuesAreGatedBeforeExecution(): void
    {
        $this->fillBothLegs();

        $this->handle();

        self::assertSame([self::BUY_VENUE, self::SELL_VENUE], $this->gateChecks);
    }

    public function testAnAbortedTradeReportsNothingBackToTheBreaker(): void
    {
        $this->allowed[self::BUY_VENUE] = false;

        $this->handle();

        self::assertSame([], $this->successes);
        self::assertSame([], $this->failures, 'declining to trade is not an exchange failure');
    }

    // ------------------------------------------------------------------ HAPPY PATH

    public function testBothLegsAreSentAsMarketOrdersTakenFromTheMessage(): void
    {
        $this->fillBothLegs();

        $this->handle();

        self::assertSame([
            ['venue' => self::BUY_VENUE, 'symbol' => self::SYMBOL, 'side' => 'buy', 'amount' => self::AMOUNT],
            ['venue' => self::SELL_VENUE, 'symbol' => self::SYMBOL, 'side' => 'sell', 'amount' => self::AMOUNT],
        ], $this->orders);
    }

    public function testASuccessfulRoundTripIsRecordedAgainstBothBreakers(): void
    {
        $this->fillBothLegs();

        $this->handle();

        self::assertCount(2, $this->successes);
        self::assertSame(self::BUY_VENUE, $this->successes[0][0]);
        self::assertSame(self::SELL_VENUE, $this->successes[1][0]);
        self::assertSame([], $this->failures);
    }

    public function testASuccessfulRoundTripIsPersistedAsCompleted(): void
    {
        $this->fillBothLegs();

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('COMPLETED', $execution->status);
        self::assertSame('BUY-9001', $execution->buyOrderId);
        self::assertSame('SELL-9002', $execution->sellOrderId);
        self::assertSame('99.5', $execution->buyFilledPrice);
        self::assertSame('110.25', $execution->sellFilledPrice);
        self::assertSame(1, $this->flushes);
    }

    public function testTheRealisedProfitComesFromTheFilledPricesNotTheQuote(): void
    {
        $this->fillBothLegs();

        $this->handle();

        // (110.25 - 99.5) * 2.0 — the quote said (110 - 100) * 2 = 20.
        self::assertSame('21.5000', $this->persistedExecution()->actualProfitUSD);
    }

    public function testAdverseFillsAreRecordedAsALossRatherThanSuppressed(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = ['id' => 'B', 'price' => 110.0];
        $this->outcomes[self::SELL_VENUE . ':sell'] = ['id' => 'S', 'price' => 100.0];

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('-20.0000', $execution->actualProfitUSD);
        self::assertSame('COMPLETED', $execution->status, 'both legs filled; the trade completed, it just lost money');
    }

    public function testProfitIsRoundedToFourDecimalPlaces(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = ['id' => 'B', 'price' => 100.0];
        $this->outcomes[self::SELL_VENUE . ':sell'] = ['id' => 'S', 'price' => 100.00005];

        $this->handle(amount: 1.0);

        self::assertSame('0.0001', $this->persistedExecution()->actualProfitUSD);
    }

    /**
     * Some venues report only a volume-weighted `average` for a market order.
     * Profit falls back to it, but the price columns key off `price` alone — so a
     * completed trade can carry a profit figure with no fill prices behind it.
     */
    public function testProfitFallsBackToTheAveragePriceWhileTheFillColumnsStayEmpty(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = ['id' => 'B', 'average' => 101.0];
        $this->outcomes[self::SELL_VENUE . ':sell'] = ['id' => 'S', 'average' => 109.0];

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('16.0000', $execution->actualProfitUSD, '(109 - 101) * 2');
        self::assertNull($execution->buyFilledPrice);
        self::assertNull($execution->sellFilledPrice);
    }

    public function testProfitFallsBackToTheQuotedPriceWhenTheVenueReportsNoFillDetail(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = ['id' => 'B'];
        $this->outcomes[self::SELL_VENUE . ':sell'] = ['id' => 'S'];

        $this->handle();

        // Falls all the way back to the message quote: (110 - 100) * 2.
        self::assertSame('20.0000', $this->persistedExecution()->actualProfitUSD);
    }

    public function testAMissingOrderIdIsToleratedRatherThanFatal(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = ['price' => 99.5];
        $this->outcomes[self::SELL_VENUE . ':sell'] = ['price' => 110.25];

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertNull($execution->buyOrderId);
        self::assertNull($execution->sellOrderId);
        self::assertSame('COMPLETED', $execution->status);
    }

    // -------------------------------------------- PARTIAL FILL: BUY LANDED, SELL DIED

    public function testAFailedSellLegIsChargedToTheSellVenueAlone(): void
    {
        $this->partialBuy('venue rejected the order');

        $this->handle();

        self::assertSame([[self::SELL_VENUE, 'SELL order failed: venue rejected the order']], $this->failures);
        self::assertSame([], $this->successes, 'the surviving leg is an exposure, not a success');
    }

    public function testAnOrphanedBuyIsUnwoundWithAMarketSellOnTheSameVenue(): void
    {
        $this->partialBuy();

        $this->handle();

        self::assertSame([
            ['venue' => self::BUY_VENUE, 'symbol' => self::SYMBOL, 'side' => 'buy', 'amount' => self::AMOUNT],
            ['venue' => self::SELL_VENUE, 'symbol' => self::SYMBOL, 'side' => 'sell', 'amount' => self::AMOUNT],
            ['venue' => self::BUY_VENUE, 'symbol' => self::SYMBOL, 'side' => 'sell', 'amount' => self::AMOUNT],
        ], $this->orders, 'the unwind flattens the full position on the venue holding it');
    }

    public function testTheUnwindOnlyRunsOnceBothLegsHaveSettled(): void
    {
        $this->partialBuy();

        $this->handle();

        self::assertSame([
            'coinbase:buy:dispatched',
            'kraken:sell:dispatched',
            'coinbase:buy:filled',
            'kraken:sell:failed',
            'coinbase:sell:unwind',
            'coinbase:sell:unwind-filled',
        ], $this->trace, 'the unwind must not race the leg that has not reported yet');
    }

    public function testAPartialFillIsAnnouncedAsCritical(): void
    {
        $this->partialBuy();

        $this->handle();

        self::assertSame(
            ['PARTIAL FILL DETECTED! Unwinding position on coinbase...'],
            $this->logMessages(LogLevel::CRITICAL)
        );
    }

    public function testASuccessfulUnwindIsLoggedAtInfo(): void
    {
        $this->partialBuy();

        $this->handle();

        self::assertSame(['Position successfully unwound via market sell.'], $this->logMessages(LogLevel::INFO));
        self::assertSame([], $this->logMessages(LogLevel::EMERGENCY));
    }

    /**
     * The reversing order really is the sell side of what happened, so it belongs in the
     * sell columns. Without it the row would show a filled buy, an empty sell and a null
     * P&L — no trace that the money actually went out and came back.
     */
    public function testAnOrphanedBuyIsPersistedWithTheUnwindAsItsSellSide(): void
    {
        $this->partialBuy();

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('PARTIAL_BUY_UNWOUND', $execution->status);
        self::assertSame('BUY-9001', $execution->buyOrderId);
        self::assertSame('99.5', $execution->buyFilledPrice);
        self::assertSame('UNWIND-9003', $execution->sellOrderId);
        self::assertSame('98', $execution->sellFilledPrice);
    }

    public function testTheRealisedLossOnAnUnwoundBuyIsRecorded(): void
    {
        $this->partialBuy();

        $this->handle();

        // Bought 2.0 at 99.5, sold the same 2.0 straight back at 98.0.
        self::assertSame('-3.0000', $this->persistedExecution()->actualProfitUSD);
    }

    public function testAnUnwindReportingOnlyAnAveragePriceStillYieldsTheRealisedLoss(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = ['id' => 'U', 'average' => 98.0];

        $this->handle();

        self::assertSame('-3.0000', $this->persistedExecution()->actualProfitUSD);
    }

    /**
     * The quoted sell price belongs to the arbitrage leg that never executed. Falling back
     * to it here would report a healthy profit on a position that was bought and dumped at
     * a loss, so an unwind with no reported price leaves the P&L unknown instead.
     */
    public function testAnUnwindWithNoReportedPriceDoesNotInventAProfitFromTheQuote(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = ['id' => 'U'];

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('U', $execution->sellOrderId, 'the unwind itself is still recorded');
        self::assertNull(
            $execution->actualProfitUSD,
            'the quote would have fabricated (110.00 - 99.50) * 2 = +21.00 on a losing unwind'
        );
    }

    /**
     * The worst case in the whole handler: the position is stuck open. Nothing here
     * can fix that, so the requirement is that it screams and still leaves a record.
     */
    public function testAFailedUnwindEscalatesToEmergencyAndStillWritesTheLedgerRow(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertSame(
            ['CRITICAL FAILURE: Emergency unwind failed! Manual intervention required! Error: venue is down'],
            $this->logMessages(LogLevel::EMERGENCY)
        );
        self::assertSame([], $this->logMessages(LogLevel::INFO));
        self::assertSame(1, $this->flushes);
    }

    /**
     * The distinction the ledger exists to make. A flattened position and an open one are
     * physically opposite outcomes and must never share a status — reading UNWOUND has to
     * mean the money actually came back.
     */
    public function testAFailedUnwindIsPersistedAsStillOpenNotAsUnwound(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = new \RuntimeException('venue is down');

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('PARTIAL_BUY_UNWIND_FAILED', $execution->status);
        self::assertSame('BUY-9001', $execution->buyOrderId, 'the leg that did fill is still recorded');
        self::assertNull($execution->sellOrderId, 'nothing closed the position');
        self::assertNull($execution->actualProfitUSD, 'the trade is still open, so there is no realised P&L');
    }

    public function testAFailedUnwindOnTheSellSideIsAlsoDistinguishable(): void
    {
        $this->partialSell();
        $this->outcomes[self::SELL_VENUE . ':buy'] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertSame('PARTIAL_SELL_UNWIND_FAILED', $this->persistedExecution()->status);
    }

    /**
     * The status column is length: 30 and these are the longest values the handler emits.
     * A truncated status would silently corrupt the one field that says whether the firm
     * is carrying risk, so the check reads the value the handler actually produced.
     */
    #[DataProvider('unwindFailureProvider')]
    public function testTheOpenPositionStatusFitsTheColumn(string $fixture, string $unwindLeg): void
    {
        $this->{$fixture}();
        $this->outcomes[$unwindLeg] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertLessThanOrEqual(30, strlen((string) $this->persistedExecution()->status));
    }

    public static function unwindFailureProvider(): iterable
    {
        yield 'buy side' => ['partialBuy', self::BUY_VENUE . ':sell'];
        yield 'sell side' => ['partialSell', self::SELL_VENUE . ':buy'];
    }

    // ------------------------------------------------------------------- PAGING

    public function testAFailedUnwindPagesTheAdminBySms(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertCount(1, $this->sentMessages);
        self::assertSame(self::ADMIN_PHONE, $this->sentMessages[0]->getPhone());
        self::assertSame(
            '🚨 UNWIND FAILED on coinbase! 2 ETH/USDT left OPEN (opportunity 4711). Manual intervention required!',
            $this->sentMessages[0]->getSubject()
        );
    }

    public function testThePageNamesTheVenueActuallyHoldingThePosition(): void
    {
        $this->partialSell();
        $this->outcomes[self::SELL_VENUE . ':buy'] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertCount(1, $this->sentMessages);
        self::assertStringContainsString('UNWIND FAILED on kraken!', $this->sentMessages[0]->getSubject());
    }

    /**
     * Everything else in the handler is a routine outcome. Paging on any of them would
     * train the recipient to ignore the one message that means money is at risk.
     */
    #[DataProvider('nonPagingOutcomeProvider')]
    public function testRoutineOutcomesDoNotPageAnyone(string $fixture): void
    {
        $this->{$fixture}();

        $this->handle();

        self::assertSame([], $this->sentMessages);
    }

    public static function nonPagingOutcomeProvider(): iterable
    {
        yield 'both legs filled' => ['fillBothLegs'];
        yield 'partial buy, unwound successfully' => ['partialBuy'];
        yield 'partial sell, unwound successfully' => ['partialSell'];
        yield 'both legs failed' => ['bothLegsFail'];
    }

    /**
     * The page is a courtesy on top of the ledger, not a precondition for it. If the SMS
     * transport is down too, the row recording the open position still has to be written.
     */
    public function testASmsOutageDoesNotStopTheOpenPositionBeingRecorded(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = new \RuntimeException('venue is down');
        $this->texterFailure = new \RuntimeException('SNS unreachable');

        $this->handle();

        self::assertSame('PARTIAL_BUY_UNWIND_FAILED', $this->persistedExecution()->status);
        self::assertSame(1, $this->flushes);
        self::assertSame(
            ['Failed to page admin about an open position: SNS unreachable'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    /**
     * A venue that refuses a risk-reducing order is out of service on the spot — not
     * incremented towards a threshold that would let the next trade through first. The
     * leg failure is still a plain increment, and it belongs to the other venue: one
     * incident, two venues, two different severities.
     */
    public function testAFailedUnwindTripsTheVenueThatRefusedItOutright(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertSame([[self::BUY_VENUE, 'Unwind failed: venue is down']], $this->trips);
        self::assertSame([[self::SELL_VENUE, 'SELL order failed: rejected']], $this->failures);
    }

    public function testASuccessfulUnwindLeavesTheVenueInService(): void
    {
        $this->partialBuy();

        $this->handle();

        self::assertSame([], $this->trips, 'the venue did what was asked of it');
        self::assertSame([[self::SELL_VENUE, 'SELL order failed: rejected']], $this->failures);
    }

    public function testAFailedSellSideUnwindTripsTheSellVenue(): void
    {
        $this->partialSell();
        $this->outcomes[self::SELL_VENUE . ':buy'] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertSame([[self::SELL_VENUE, 'Unwind failed: venue is down']], $this->trips);
        self::assertSame([[self::BUY_VENUE, 'BUY order failed: rejected']], $this->failures);
    }

    /**
     * An outright trip is reserved for the refused unwind. Escalating routine outcomes
     * the same way would take venues out of service for ordinary bad luck.
     */
    #[DataProvider('nonPagingOutcomeProvider')]
    public function testRoutineOutcomesNeverTripAVenueOutright(string $fixture): void
    {
        $this->{$fixture}();

        $this->handle();

        self::assertSame([], $this->trips);
    }

    /**
     * The exemption that makes charging the venue safe. If the unwind were gated, a venue
     * tripped by this very incident could refuse the order that closes the position — the
     * breaker would be causing the exposure it exists to limit.
     */
    public function testTheUnwindNeverConsultsTheCircuitBreaker(): void
    {
        $this->partialBuy();

        $this->handle();

        self::assertSame(
            [self::BUY_VENUE, self::SELL_VENUE],
            $this->gateChecks,
            'the gate is for the two speculative legs only'
        );
    }

    public function testAnUnwindStillRunsAfterTheVenueIsTrippedMidTrade(): void
    {
        // Allow the two entry checks, then slam the gate shut on everything — the state a
        // venue lands in when the leg failure moments earlier tripped its breaker.
        $this->allowedUntilCall = 2;
        $this->partialBuy();

        $this->handle();

        self::assertSame(
            ['coinbase:buy', 'kraken:sell', 'coinbase:sell'],
            array_map(static fn(array $o): string => "{$o['venue']}:{$o['side']}", $this->orders),
            'the position was still flattened'
        );
        self::assertSame('PARTIAL_BUY_UNWOUND', $this->persistedExecution()->status);
    }

    // ----------------------------------------------------------- BREAKER OUTAGE

    /**
     * The breaker is bookkeeping and must never outrank the trade. Every one of its
     * methods touches the cache and can reach the SMS transport, so all of them can throw
     * at exactly the moment things are already going wrong — and an exception escaping
     * here would abort the handler before the ledger row that records what happened.
     */
    public function testABreakerOutageDoesNotStopTheOpenPositionBeingRecorded(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = new \RuntimeException('venue is down');
        $this->breakerOutage = new \RuntimeException('cache unreachable');

        $this->handle();

        self::assertSame('PARTIAL_BUY_UNWIND_FAILED', $this->persistedExecution()->status);
        self::assertSame(1, $this->flushes);
        self::assertCount(1, $this->sentMessages, 'the admin was still paged');
    }

    /**
     * The dangerous one, because it runs *before* the unwind. An exception from the leg
     * failure report used to abort the handler mid-incident: no unwind, no page, no row —
     * a cache blip silently converting a partial fill into an orphaned position.
     */
    public function testABreakerOutageStillLetsThePositionBeUnwound(): void
    {
        $this->partialBuy();
        $this->breakerOutage = new \RuntimeException('cache unreachable');

        $this->handle();

        self::assertSame(
            ['coinbase:buy', 'kraken:sell', 'coinbase:sell'],
            array_map(static fn(array $o): string => "{$o['venue']}:{$o['side']}", $this->orders),
            'the unwind must still run'
        );
        self::assertSame('PARTIAL_BUY_UNWOUND', $this->persistedExecution()->status);
        self::assertSame(
            ['Circuit breaker update failed while recording failure for kraken: cache unreachable'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    /**
     * Worst case for a lost row: money actually moved on both venues. Without a record
     * there is nothing to reconcile against.
     */
    public function testABreakerOutageDoesNotStopACompletedTradeBeingRecorded(): void
    {
        $this->fillBothLegs();
        $this->breakerOutage = new \RuntimeException('cache unreachable');

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('COMPLETED', $execution->status);
        self::assertSame('21.5000', $execution->actualProfitUSD);
        self::assertSame([
            'Circuit breaker update failed while recording success for coinbase: cache unreachable',
            'Circuit breaker update failed while recording success for kraken: cache unreachable',
        ], $this->logMessages(LogLevel::ERROR), 'both legs are reported independently');
    }

    public function testABreakerOutageDoesNotStopAFailedTradeBeingRecorded(): void
    {
        $this->bothLegsFail();
        $this->breakerOutage = new \RuntimeException('cache unreachable');

        $this->handle();

        self::assertSame('FAILED', $this->persistedExecution()->status);
        self::assertCount(2, $this->logMessages(LogLevel::ERROR));
    }

    /**
     * A degraded breaker is an infrastructure problem, not a position at risk. Paging on
     * it would flood the on-call the moment the cache wobbles, drowning out the one
     * message that means money is exposed.
     */
    public function testABreakerOutageAloneDoesNotPageAnyone(): void
    {
        $this->partialBuy();
        $this->breakerOutage = new \RuntimeException('cache unreachable');

        $this->handle();

        self::assertSame([], $this->sentMessages);
    }

    // -------------------------------------------- PARTIAL FILL: SELL LANDED, BUY DIED

    public function testAFailedBuyLegIsChargedToTheBuyVenueAlone(): void
    {
        $this->partialSell('insufficient funds');

        $this->handle();

        self::assertSame([[self::BUY_VENUE, 'BUY order failed: insufficient funds']], $this->failures);
        self::assertSame([], $this->successes);
    }

    public function testAnOrphanedSellIsUnwoundWithAMarketBuyOnTheSameVenue(): void
    {
        $this->partialSell();

        $this->handle();

        self::assertSame([
            ['venue' => self::BUY_VENUE, 'symbol' => self::SYMBOL, 'side' => 'buy', 'amount' => self::AMOUNT],
            ['venue' => self::SELL_VENUE, 'symbol' => self::SYMBOL, 'side' => 'sell', 'amount' => self::AMOUNT],
            ['venue' => self::SELL_VENUE, 'symbol' => self::SYMBOL, 'side' => 'buy', 'amount' => self::AMOUNT],
        ], $this->orders, 'a short is closed by buying it back on the venue that is short');
    }

    public function testAnOrphanedSellIsPersistedWithTheUnwindAsItsBuySide(): void
    {
        $this->partialSell();

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('PARTIAL_SELL_UNWOUND', $execution->status);
        self::assertSame('SELL-9002', $execution->sellOrderId);
        self::assertSame('110.25', $execution->sellFilledPrice);
        self::assertSame('UNWIND-9004', $execution->buyOrderId);
        self::assertSame('112', $execution->buyFilledPrice);
    }

    public function testTheRealisedLossOnAnUnwoundSellIsRecorded(): void
    {
        $this->partialSell();

        $this->handle();

        // Sold 2.0 at 110.25, bought the same 2.0 straight back at 112.0.
        self::assertSame('-3.5000', $this->persistedExecution()->actualProfitUSD);
    }

    public function testTheOrphanedSellUnwindNamesTheCorrectVenue(): void
    {
        $this->partialSell();

        $this->handle();

        self::assertSame(
            ['PARTIAL FILL DETECTED! Unwinding position on kraken...'],
            $this->logMessages(LogLevel::CRITICAL)
        );
        self::assertSame(['Position successfully unwound via market buy.'], $this->logMessages(LogLevel::INFO));
    }

    // --------------------------------------------------------------- TOTAL FAILURE

    public function testBothLegsFailingIsChargedToBothVenuesWithTheRawReason(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = new \RuntimeException('buy timed out');
        $this->outcomes[self::SELL_VENUE . ':sell'] = new \RuntimeException('sell timed out');

        $this->handle();

        self::assertSame([
            [self::BUY_VENUE, 'buy timed out'],
            [self::SELL_VENUE, 'sell timed out'],
        ], $this->failures, 'no leg landed, so there is nothing to disambiguate with a prefix');
        self::assertSame([], $this->successes);
    }

    public function testBothLegsFailingLeavesNothingToUnwind(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = new \RuntimeException('buy timed out');
        $this->outcomes[self::SELL_VENUE . ':sell'] = new \RuntimeException('sell timed out');

        $this->handle();

        self::assertCount(2, $this->orders, 'one attempt per leg and no reversal');
        self::assertSame([], $this->logMessages(LogLevel::CRITICAL));
        self::assertSame([], $this->logMessages(LogLevel::EMERGENCY));
    }

    public function testBothLegsFailingIsStillPersisted(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = new \RuntimeException('buy timed out');
        $this->outcomes[self::SELL_VENUE . ':sell'] = new \RuntimeException('sell timed out');

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('FAILED', $execution->status);
        self::assertNull($execution->buyOrderId);
        self::assertNull($execution->sellOrderId);
        self::assertNull($execution->buyFilledPrice);
        self::assertNull($execution->sellFilledPrice);
        self::assertNull($execution->actualProfitUSD);
        self::assertSame(1, $this->flushes, 'a failed attempt is still an audit record');
    }

    // ------------------------------------------------------------------- LEDGER

    public function testTheOpportunityIsAttachedByReferenceRatherThanLoaded(): void
    {
        $this->fillBothLegs();

        $this->handle();

        self::assertSame([[ArbitrageOpportunity::class, self::OPPORTUNITY_ID]], $this->references);
        self::assertInstanceOf(ArbitrageOpportunity::class, $this->persistedExecution()->opportunity);
    }

    public function testTheExecutionIsStampedWithTheTimeItWasWritten(): void
    {
        $this->fillBothLegs();

        $before = new \DateTimeImmutable('now');
        $this->handle();

        $createdAt = $this->persistedExecution()->createdAt;

        self::assertInstanceOf(\DateTimeImmutable::class, $createdAt);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $createdAt->getTimestamp());
    }

    public function testTheLatencyReportedToTheBreakerIsTheOneWrittenToTheLedger(): void
    {
        // Both legs stall long enough that the measurement cannot round to zero.
        $this->outcomes[self::BUY_VENUE . ':buy'] = function (): array {
            usleep(6_000);

            return self::BUY_FILL;
        };
        $this->outcomes[self::SELL_VENUE . ':sell'] = self::SELL_FILL;

        $this->handle();

        $latency = $this->persistedExecution()->executionTimeMs;

        self::assertGreaterThanOrEqual(5, $latency, 'the ~6ms stall must show up in the measurement');
        self::assertSame([[self::BUY_VENUE, $latency], [self::SELL_VENUE, $latency]], $this->successes);
    }

    public function testLatencyIsAlsoRecordedForTradesThatFailed(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = new \RuntimeException('buy timed out');
        $this->outcomes[self::SELL_VENUE . ':sell'] = new \RuntimeException('sell timed out');

        $this->handle();

        self::assertGreaterThanOrEqual(0, $this->persistedExecution()->executionTimeMs);
    }

    // ------------------------------------------------------------ VENUE RESOLUTION

    public function testVenueNamesAreResolvedCaseInsensitively(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = self::BUY_FILL;
        $this->outcomes[self::SELL_VENUE . ':sell'] = self::SELL_FILL;

        $this->handle(buyVenue: 'Coinbase', sellVenue: 'KRAKEN');

        self::assertSame('COMPLETED', $this->persistedExecution()->status);
    }

    /**
     * ...but the breaker is keyed on the message's spelling, not the resolved venue.
     * 'Coinbase' and 'coinbase' therefore track as two independent circuits, so an
     * inconsistent producer would dilute the failure counts that trip the breaker.
     */
    public function testTheBreakerSeesTheVenueNameExactlyAsTheMessageSpelledIt(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = self::BUY_FILL;
        $this->outcomes[self::SELL_VENUE . ':sell'] = self::SELL_FILL;

        $this->handle(buyVenue: 'Coinbase', sellVenue: 'KRAKEN');

        self::assertSame(['Coinbase', 'KRAKEN'], $this->gateChecks);
        self::assertSame(['Coinbase', 'KRAKEN'], array_column($this->successes, 0));
    }

    public function testAnUnknownVenueAbortsBeforeAnyOrderIsPlaced(): void
    {
        $this->fillBothLegs();

        try {
            $this->handle(sellVenue: 'ftx');
            self::fail('an unresolvable venue must not be swallowed');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Unsupported exchange: ftx', $e->getMessage());
        }

        self::assertSame([], $this->orders, 'the buy leg must not fire when the exit venue does not exist');
        self::assertSame([], $this->persisted, 'nothing happened, so there is nothing to record');
    }

    public function testBothLegsRoutingToTheSameVenueRecordTwoSuccessesOnOneCircuit(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = self::BUY_FILL;
        $this->outcomes[self::BUY_VENUE . ':sell'] = self::SELL_FILL;

        $this->handle(sellVenue: self::BUY_VENUE);

        self::assertCount(2, $this->successes);
        self::assertSame(self::BUY_VENUE, $this->successes[0][0]);
        self::assertSame(self::BUY_VENUE, $this->successes[1][0]);
    }

    // ---------------------------------------------------------------- CONCURRENCY

    public function testBothLegsAreOnTheWireBeforeEitherOneSettles(): void
    {
        $this->fillBothLegs();

        $this->handle();

        self::assertSame([
            'coinbase:buy:dispatched',
            'kraken:sell:dispatched',
            'coinbase:buy:filled',
            'kraken:sell:filled',
        ], $this->trace);
    }

    /**
     * The decisive one. The buy leg is made the slower of the two, so if the legs were
     * serialised — as they were under the old Guzzle wrapper around blocking cURL — the
     * buy would have to settle before the sell was even dispatched. Seeing the sell
     * settle first proves the two requests genuinely overlap.
     */
    public function testASlowLegDoesNotHoldUpTheOtherOne(): void
    {
        $this->fillBothLegs();
        $this->delays[self::BUY_VENUE . ':buy'] = 0.03;
        $this->delays[self::SELL_VENUE . ':sell'] = 0.0;

        $this->handle();

        self::assertSame([
            'coinbase:buy:dispatched',
            'kraken:sell:dispatched',
            'kraken:sell:filled',
            'coinbase:buy:filled',
        ], $this->trace);
    }

    /**
     * Wall-clock proof of the same thing: two legs of 30ms each must take ~30ms
     * together, not ~60ms. The bound is loose enough not to flake on a busy machine
     * but far below what serialised execution could achieve.
     */
    public function testConcurrentLegsCostOneLegOfLatencyNotTwo(): void
    {
        $this->fillBothLegs();
        $this->delays[self::BUY_VENUE . ':buy'] = 0.03;
        $this->delays[self::SELL_VENUE . ':sell'] = 0.03;

        $this->handle();

        $latency = $this->persistedExecution()->executionTimeMs;

        self::assertGreaterThanOrEqual(30, $latency, 'both legs really did wait ~30ms');
        self::assertLessThan(55, $latency, 'serialised execution would have cost ~60ms');
    }

    /**
     * A leg that fails fast must not shorten the wait for one that is still live —
     * that is what would leave an unknown position open on the slow venue.
     */
    public function testAFastFailureStillWaitsForTheLegThatIsStillInFlight(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = new \RuntimeException('rejected instantly');
        $this->outcomes[self::SELL_VENUE . ':sell'] = self::SELL_FILL;
        $this->delays[self::SELL_VENUE . ':sell'] = 0.03;

        $this->handle();

        self::assertSame([
            'coinbase:buy:dispatched',
            'kraken:sell:dispatched',
            'coinbase:buy:failed',
            'kraken:sell:filled',
            'kraken:buy:unwind',
            'kraken:buy:unwind-filled',
        ], $this->trace);
        self::assertSame('PARTIAL_SELL_UNWOUND', $this->persistedExecution()->status);
    }

    // -------------------------------------------------------------------- HELPERS

    private function handle(
        string $buyVenue = self::BUY_VENUE,
        string $sellVenue = self::SELL_VENUE,
        float $amount = self::AMOUNT,
        string $symbol = self::SYMBOL,
    ): void {
        $handler = new ExecuteArbitrageHandler(
            $this->exchangeFactory(),
            $this->circuitBreaker(),
            $this->entityManager(),
            $this->recordingLogger(),
            $this->recordingTexter(),
            self::ADMIN_PHONE,
        );

        $handler(new ExecuteArbitrageMessage(
            self::OPPORTUNITY_ID,
            $symbol,
            $buyVenue,
            $sellVenue,
            self::QUOTED_BUY,
            self::QUOTED_SELL,
            $amount,
        ));
    }

    private function fillBothLegs(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = self::BUY_FILL;
        $this->outcomes[self::SELL_VENUE . ':sell'] = self::SELL_FILL;
    }

    private function bothLegsFail(): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = new \RuntimeException('buy timed out');
        $this->outcomes[self::SELL_VENUE . ':sell'] = new \RuntimeException('sell timed out');
    }

    /** Buy leg lands, sell leg dies — the position is long and needs flattening. */
    private function partialBuy(string $reason = 'rejected'): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = self::BUY_FILL;
        $this->outcomes[self::SELL_VENUE . ':sell'] = new \RuntimeException($reason);
        $this->outcomes[self::BUY_VENUE . ':sell'] = self::UNWIND_SELL_FILL;
    }

    /** Sell leg lands, buy leg dies — the position is short and needs flattening. */
    private function partialSell(string $reason = 'rejected'): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = new \RuntimeException($reason);
        $this->outcomes[self::SELL_VENUE . ':sell'] = self::SELL_FILL;
        $this->outcomes[self::SELL_VENUE . ':buy'] = self::UNWIND_BUY_FILL;
    }

    private function exchangeFactory(): ExchangeFactory
    {
        return new ExchangeFactory(new ServiceLocator([
            self::BUY_VENUE => fn(): ExchangeServiceInterface => $this->venue(self::BUY_VENUE),
            self::SELL_VENUE => fn(): ExchangeServiceInterface => $this->venue(self::SELL_VENUE),
        ]));
    }

    /**
     * One stub per venue, reused across create() calls so the unwind lands on the
     * same recorder as the original leg.
     */
    private function venue(string $name): ExchangeServiceInterface
    {
        return $this->venues[$name] ??= $this->makeVenue($name);
    }

    /**
     * Both halves of the interface are stubbed, because the handler uses both: the two
     * arbitrage legs go through the async method, while the emergency unwind — a single
     * call with nothing to overlap — stays synchronous. The trace labels say which
     * mechanism produced each event so the ordering assertions stay readable.
     */
    private function makeVenue(string $name): ExchangeServiceInterface
    {
        $venue = $this->createStub(ExchangeServiceInterface::class);

        $venue->method('executeMarketOrderAsync')->willReturnCallback(
            function (string $symbol, string $side, float $amount) use ($name): PromiseInterface {
                $this->recordOrder($name, $symbol, $side, $amount);
                $this->trace[] = "{$name}:{$side}:dispatched";

                // Settling on a loop timer rather than immediately is what makes the
                // legs overlap — exactly as a real in-flight HTTP request would.
                $deferred = new Deferred();

                Loop::addTimer(
                    $this->delays["{$name}:{$side}"] ?? 0.0,
                    function () use ($deferred, $name, $side): void {
                        $outcome = $this->outcomeFor($name, $side);

                        if ($outcome instanceof \Throwable) {
                            $this->trace[] = "{$name}:{$side}:failed";
                            $deferred->reject($outcome);

                            return;
                        }

                        $this->trace[] = "{$name}:{$side}:filled";
                        $deferred->resolve($outcome);
                    }
                );

                return $deferred->promise();
            }
        );

        // Reached only via the emergency unwind.
        $venue->method('executeMarketOrder')->willReturnCallback(
            function (string $symbol, string $side, float $amount) use ($name): array {
                $this->recordOrder($name, $symbol, $side, $amount);
                $this->trace[] = "{$name}:{$side}:unwind";

                $outcome = $this->outcomeFor($name, $side);

                if ($outcome instanceof \Throwable) {
                    $this->trace[] = "{$name}:{$side}:unwind-failed";

                    throw $outcome;
                }

                $this->trace[] = "{$name}:{$side}:unwind-filled";

                return $outcome;
            }
        );

        $venue->method('warmUp')->willReturnCallback(
            fn (): PromiseInterface => $this->resolved()
        );

        return $venue;
    }

    /**
     * A promise that has already fulfilled.
     *
     * Built from a Deferred rather than React\Promise\resolve(), whose documented
     * parameter unions the plain value with PromiseInterface itself; static analysis
     * matches against the promise arm and reports a plain value as a type error.
     * Same promise either way.
     *
     * @return PromiseInterface<null>
     */
    private function resolved(): PromiseInterface
    {
        $deferred = new Deferred();
        $deferred->resolve(null);

        return $deferred->promise();
    }

    private function recordOrder(string $venue, string $symbol, string $side, float $amount): void
    {
        $this->orders[] = [
            'venue' => $venue,
            'symbol' => $symbol,
            'side' => $side,
            'amount' => $amount,
        ];
    }

    /**
     * @return array|\Throwable the fill payload, or the failure to raise
     */
    private function outcomeFor(string $venue, string $side): array|\Throwable
    {
        $outcome = $this->outcomes["{$venue}:{$side}"] ?? ['id' => "auto-{$venue}-{$side}"];

        return $outcome instanceof \Closure ? $outcome() : $outcome;
    }

    private function circuitBreaker(): TradingCircuitBreaker
    {
        $breaker = $this->createStub(TradingCircuitBreaker::class);

        $breaker->method('isAllowed')->willReturnCallback(
            function (string $exchange): bool {
                $this->gateChecks[] = $exchange;

                if ($this->allowedUntilCall !== null && count($this->gateChecks) > $this->allowedUntilCall) {
                    return false;
                }

                return $this->allowed[$exchange] ?? true;
            }
        );
        $breaker->method('recordSuccess')->willReturnCallback(
            function (string $exchange, int $executionTimeMs): void {
                $this->failIfBreakerIsOut();

                $this->successes[] = [$exchange, $executionTimeMs];
            }
        );
        $breaker->method('recordFailure')->willReturnCallback(
            function (string $exchange, string $reason): void {
                $this->failIfBreakerIsOut();

                $this->failures[] = [$exchange, $reason];
            }
        );
        $breaker->method('tripImmediately')->willReturnCallback(
            function (string $exchange, string $reason): void {
                $this->failIfBreakerIsOut();

                $this->trips[] = [$exchange, $reason];
            }
        );

        return $breaker;
    }

    private function failIfBreakerIsOut(): void
    {
        if ($this->breakerOutage !== null) {
            throw $this->breakerOutage;
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $em->method('getReference')->willReturnCallback(
            function (string $entityName, mixed $id): object {
                $this->references[] = [$entityName, $id];

                return new $entityName();
            }
        );
        $em->method('persist')->willReturnCallback(
            function (object $entity): void {
                $this->persisted[] = $entity;
            }
        );
        $em->method('flush')->willReturnCallback(
            function (): void {
                ++$this->flushes;
            }
        );

        return $em;
    }

    private function persistedExecution(): TradeExecution
    {
        self::assertCount(1, $this->persisted, 'exactly one ledger row per handled message');
        self::assertInstanceOf(TradeExecution::class, $this->persisted[0]);

        return $this->persisted[0];
    }

    /**
     * Stubbing each level separately pins *which* severity every event is reported
     * at — a routed-through log() double would let a downgrade from emergency slip by.
     */
    private function recordingTexter(): TexterInterface
    {
        $texter = $this->createStub(TexterInterface::class);

        $texter->method('supports')->willReturn(true);
        $texter->method('send')->willReturnCallback(
            function (MessageInterface $message): ?SentMessage {
                if ($this->texterFailure !== null) {
                    throw $this->texterFailure;
                }

                $this->sentMessages[] = $message;

                return null;
            }
        );

        return $texter;
    }

    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        foreach ([LogLevel::WARNING, LogLevel::CRITICAL, LogLevel::EMERGENCY, LogLevel::INFO, LogLevel::ERROR] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string|\Stringable $message) use ($level): void {
                    $this->loggedMessages[$level][] = (string) $message;
                }
            );
        }

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
