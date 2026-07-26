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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ServiceLocator;

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

    private const array BUY_FILL = ['id' => 'BUY-9001', 'price' => 99.5];
    private const array SELL_FILL = ['id' => 'SELL-9002', 'price' => 110.25];

    /** @var array<string, bool> venue name => breaker verdict */
    private array $allowed = [];

    /** @var array<string, array|\Throwable|\Closure> keyed "venue:side" */
    private array $outcomes = [];

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

    /** @var list<array{0: string, 1: mixed}> [entityClass, id] */
    private array $references = [];

    /** @var list<object> */
    private array $persisted = [];

    private int $flushes = 0;

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        $this->allowed = [];
        $this->outcomes = [];
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
            'coinbase:buy:start',
            'coinbase:buy:end',
            'kraken:sell:start',
            'kraken:sell:threw',
            'coinbase:sell:start',
            'coinbase:sell:end',
        ], $this->trace);
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

    public function testAnOrphanedBuyIsPersistedWithNoSellSideDetail(): void
    {
        $this->partialBuy();

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('PARTIAL_BUY_UNWOUND', $execution->status);
        self::assertSame('BUY-9001', $execution->buyOrderId);
        self::assertSame('99.5', $execution->buyFilledPrice);
        self::assertNull($execution->sellOrderId);
        self::assertNull($execution->sellFilledPrice);
        self::assertNull($execution->actualProfitUSD, 'a half-filled trade has no realised P&L to report');
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
     * Documents a gap worth knowing about: the status is chosen before the unwind is
     * attempted, so a row can read PARTIAL_BUY_UNWOUND while the position is still open.
     * The emergency log above is the only signal that separates the two.
     */
    public function testAFailedUnwindIsStillPersistedAsUnwound(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertSame('PARTIAL_BUY_UNWOUND', $this->persistedExecution()->status);
    }

    public function testTheUnwindItselfIsNotChargedToTheBreaker(): void
    {
        $this->partialBuy();
        $this->outcomes[self::BUY_VENUE . ':sell'] = new \RuntimeException('venue is down');

        $this->handle();

        self::assertSame([[self::SELL_VENUE, 'SELL order failed: rejected']], $this->failures);
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

    public function testAnOrphanedSellIsPersistedWithNoBuySideDetail(): void
    {
        $this->partialSell();

        $this->handle();

        $execution = $this->persistedExecution();

        self::assertSame('PARTIAL_SELL_UNWOUND', $execution->status);
        self::assertSame('SELL-9002', $execution->sellOrderId);
        self::assertSame('110.25', $execution->sellFilledPrice);
        self::assertNull($execution->buyOrderId);
        self::assertNull($execution->buyFilledPrice);
        self::assertNull($execution->actualProfitUSD);
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

    /**
     * The promise wrapper does not make the legs concurrent. Guzzle promises only
     * parallelise transports that cooperate with the event loop (curl_multi); a
     * blocking CCXT call inside a wait() callback runs to completion before the
     * next promise is waited on. The trace below shows leg two starting only after
     * leg one returned — that serialisation *is* the exposure window the unwind
     * logic exists to cover, so it is asserted rather than assumed.
     */
    public function testTheLegsExecuteSequentiallyDespiteThePromiseWrapper(): void
    {
        $this->fillBothLegs();

        $this->handle();

        self::assertSame([
            'coinbase:buy:start',
            'coinbase:buy:end',
            'kraken:sell:start',
            'kraken:sell:end',
        ], $this->trace);
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

    /** Buy leg lands, sell leg dies — the position is long and needs flattening. */
    private function partialBuy(string $reason = 'rejected'): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = self::BUY_FILL;
        $this->outcomes[self::SELL_VENUE . ':sell'] = new \RuntimeException($reason);
    }

    /** Sell leg lands, buy leg dies — the position is short and needs flattening. */
    private function partialSell(string $reason = 'rejected'): void
    {
        $this->outcomes[self::BUY_VENUE . ':buy'] = new \RuntimeException($reason);
        $this->outcomes[self::SELL_VENUE . ':sell'] = self::SELL_FILL;
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

    private function makeVenue(string $name): ExchangeServiceInterface
    {
        $venue = $this->createStub(ExchangeServiceInterface::class);

        $venue->method('executeMarketOrder')->willReturnCallback(
            function (string $symbol, string $side, float $amount) use ($name): array {
                $this->trace[] = "{$name}:{$side}:start";
                $this->orders[] = [
                    'venue' => $name,
                    'symbol' => $symbol,
                    'side' => $side,
                    'amount' => $amount,
                ];

                $outcome = $this->outcomes["{$name}:{$side}"] ?? ['id' => "auto-{$name}-{$side}"];

                if ($outcome instanceof \Throwable) {
                    $this->trace[] = "{$name}:{$side}:threw";

                    throw $outcome;
                }

                if ($outcome instanceof \Closure) {
                    $outcome = $outcome();
                }

                $this->trace[] = "{$name}:{$side}:end";

                return $outcome;
            }
        );

        return $venue;
    }

    private function circuitBreaker(): TradingCircuitBreaker
    {
        $breaker = $this->createStub(TradingCircuitBreaker::class);

        $breaker->method('isAllowed')->willReturnCallback(
            function (string $exchange): bool {
                $this->gateChecks[] = $exchange;

                return $this->allowed[$exchange] ?? true;
            }
        );
        $breaker->method('recordSuccess')->willReturnCallback(
            function (string $exchange, int $executionTimeMs): void {
                $this->successes[] = [$exchange, $executionTimeMs];
            }
        );
        $breaker->method('recordFailure')->willReturnCallback(
            function (string $exchange, string $reason): void {
                $this->failures[] = [$exchange, $reason];
            }
        );

        return $breaker;
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
    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        foreach ([LogLevel::WARNING, LogLevel::CRITICAL, LogLevel::EMERGENCY, LogLevel::INFO] as $level) {
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
