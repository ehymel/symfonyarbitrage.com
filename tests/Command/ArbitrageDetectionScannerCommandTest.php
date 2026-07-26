<?php
declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ArbitrageDetectionScannerCommand;
use App\Entity\ArbitrageOpportunity;
use App\Message\ExecuteArbitrageMessage;
use App\Service\AdminAlerter;
use App\Service\ArbitrageEvaluator;
use App\Service\ExchangeFactory;
use App\Service\ExchangeService\ExchangeServiceInterface;
use App\Service\ExchangeWarmer;
use App\Service\OrderBookFetcher;
use App\Service\TradingCircuitBreaker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * The scanner is the front half of the trading loop: it decides what counts as an
 * opportunity and what gets handed to the execution queue. So the collaborators that
 * make those decisions are real — the factory, the warmer, the order book fetcher and
 * the evaluator — and only the edges are doubled: the venues themselves, the breaker,
 * the bus and the entity manager. A test that stubbed the evaluator would prove the
 * command calls it, which is not the interesting part.
 *
 * The command's own loop is unbounded in production, so every case here runs it under
 * --limit. Anything asserting on cycles keeps that count at 1 or 2, since each extra
 * cycle costs a real 250ms of rate-limit pacing.
 */
#[CoversClass(ArbitrageDetectionScannerCommand::class)]
final class ArbitrageDetectionScannerCommandTest extends TestCase
{
    /** Mirrors the command's own hard-coded configuration. */
    private const array VENUES = ['coinbase', 'kraken'];
    private const array PAIRS = ['ETH/USDT', 'BTC/USDT', 'SOL/USDT'];

    private const string PROFITABLE_PAIR = 'BTC/USDT';

    private const string ADMIN_PHONE = '+15555550123';
    private const string ADMIN_EMAIL = 'ops@example.com';

    /**
     * Buy on coinbase at 100, sell on kraken at 110. Only that direction works: the
     * reverse (buy kraken at 111, sell coinbase at 99) is an inverted spread, so exactly
     * one opportunity comes out of the pair rather than two mirrored ones.
     */
    private const array COINBASE_BOOK = ['asks' => [[100.0, 10.0]], 'bids' => [[99.0, 10.0]]];
    private const array KRAKEN_BOOK = ['asks' => [[111.0, 10.0]], 'bids' => [[110.0, 10.0]]];

    /** An honest answer from a live venue with nothing worth trading on it. */
    private const array EMPTY_BOOK = ['asks' => [], 'bids' => []];

    /** @var array<string, array|\Throwable> keyed "venue:symbol" */
    private array $books = [];

    /** @var array<string, bool> venue => breaker verdict */
    private array $allowed = [];

    /** @var list<array{venue: string, symbol: string, limit: int|null}> */
    private array $reads = [];

    /** @var list<string> lifecycle markers, in the sequence they occurred */
    private array $trace = [];

    /** @var array<string, int> venue => how many times the factory was asked for it */
    private array $venueLookups = [];

    /** @var array<string, ExchangeServiceInterface> the shared instance per venue */
    private array $venues = [];

    /** @var list<ArbitrageOpportunity> */
    private array $persisted = [];

    /** @var list<ExecuteArbitrageMessage> */
    private array $dispatched = [];

    /** When set, flush() throws — a database wobble mid-scan. */
    private ?\Throwable $flushFailure = null;

    /** Doctrine closes the manager after most write failures; default is a healthy one. */
    private bool $entityManagerOpen = true;

    /** @var list<array{headline: string, detail: string}> */
    private array $alerts = [];

    /** Stands in for the identity Doctrine assigns on flush. */
    private int $nextId = 1;

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        $this->books = [];
        $this->allowed = [];
        $this->reads = [];
        $this->trace = [];
        $this->venueLookups = [];
        $this->venues = [];
        $this->persisted = [];
        $this->dispatched = [];
        $this->flushFailure = null;
        $this->entityManagerOpen = true;
        $this->alerts = [];
        $this->nextId = 1;
        $this->loggedMessages = [];
    }

    // ------------------------------------------------------------------ STARTUP

    /**
     * The entire point of warming at boot: if a market fetch happened lazily inside the
     * first scan, the first cycle would pay for it. Ordering is the assertion.
     */
    public function testMarketsArePreLoadedBeforeTheFirstBookIsRead(): void
    {
        $this->execute();

        self::assertSame(
            ['warm:coinbase', 'warm:kraken', 'read:coinbase', 'read:kraken'],
            array_slice($this->trace, 0, 4)
        );
    }

    /**
     * The venues are long-lived ccxt clients holding their loaded markets. The command
     * resolves them once into $instances and reuses them, so the number of lookups is a
     * property of startup alone — it must not grow with the number of cycles.
     */
    public function testVenuesAreResolvedAtStartupNotPerCycle(): void
    {
        $this->execute(limit: 1);
        $afterOneCycle = $this->venueLookups;

        $this->venueLookups = [];
        $this->execute(limit: 3);

        self::assertSame($afterOneCycle, $this->venueLookups);
    }

    public function testTheSameVenueInstanceIsWarmedAndThenScanned(): void
    {
        $this->execute();

        // Both the warmer and the scan reached the one shared client — if they had not,
        // the markets would have been loaded into an object the scan never touches.
        self::assertSame(
            ['warm:coinbase', 'warm:kraken'],
            array_values(array_filter($this->trace, static fn(string $e): bool => str_starts_with($e, 'warm:')))
        );
        self::assertSame(['coinbase', 'kraken'], array_keys($this->venues));
    }

    public function testTheStartupBannerNamesThePreLoadedVenues(): void
    {
        $tester = $this->execute();

        self::assertStringContainsString('Arbitrage Detection Engine Started', $tester->getDisplay());
        self::assertStringContainsString('Markets pre-loaded for: coinbase, kraken', $tester->getDisplay());
    }

    public function testTheCommandReportsSuccessWhenItsCycleBudgetIsSpent(): void
    {
        self::assertSame(0, $this->execute()->getStatusCode());
    }

    // ------------------------------------------------------------------- CYCLES

    public function testEveryConfiguredPairIsScannedOnEveryVenueEachCycle(): void
    {
        $this->execute();

        self::assertSame([
            ['venue' => 'coinbase', 'symbol' => 'ETH/USDT', 'limit' => 10],
            ['venue' => 'kraken', 'symbol' => 'ETH/USDT', 'limit' => 10],
            ['venue' => 'coinbase', 'symbol' => 'BTC/USDT', 'limit' => 10],
            ['venue' => 'kraken', 'symbol' => 'BTC/USDT', 'limit' => 10],
            ['venue' => 'coinbase', 'symbol' => 'SOL/USDT', 'limit' => 10],
            ['venue' => 'kraken', 'symbol' => 'SOL/USDT', 'limit' => 10],
        ], $this->reads);
    }

    public function testTheLimitBoundsTheNumberOfCycles(): void
    {
        $this->execute(limit: 2);

        self::assertCount(count(self::PAIRS) * count(self::VENUES) * 2, $this->reads);
    }

    public function testAnOpportunityIsActedOnOncePerCycleItSurvives(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(limit: 2);

        self::assertCount(2, $this->dispatched, 'the same standing spread is picked up each cycle');
    }

    // ---------------------------------------------------------------- DETECTION

    public function testAProfitableSpreadIsPersistedWithTheEvaluatedNumbers(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute();

        self::assertCount(1, $this->persisted);
        $opportunity = $this->persisted[0];

        self::assertSame(self::PROFITABLE_PAIR, $opportunity->pair);
        self::assertSame('coinbase', $opportunity->buyExchange);
        self::assertSame('kraken', $opportunity->sellExchange);
        self::assertSame('100', $opportunity->buyPrice);
        self::assertSame('110', $opportunity->sellPrice);
        self::assertNotNull($opportunity->detectedAt);
    }

    /**
     * The row is written before the message goes out, and the message carries the
     * identity the flush assigned — without it the execution handler has no opportunity
     * to attach its trade record to.
     */
    public function testTheDispatchedMessageCarriesThePersistedIdentity(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute();

        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0];

        self::assertSame('1', $message->getOpportunityId());
        self::assertSame((string) $this->persisted[0]->id, $message->getOpportunityId());
    }

    public function testTheDispatchedMessageDescribesTheTradeToExecute(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute();

        $message = $this->dispatched[0];

        self::assertSame(self::PROFITABLE_PAIR, $message->getSymbol());
        self::assertSame('coinbase', $message->getBuyExchange());
        self::assertSame('kraken', $message->getSellExchange());
        self::assertEqualsWithDelta(100.0, $message->getBuyPrice(), 1e-9);
        self::assertEqualsWithDelta(110.0, $message->getSellPrice(), 1e-9);
        self::assertEqualsWithDelta(1.0, $message->getAmount(), 1e-9, '$100 of a $100 ask');
    }

    public function testTheDetectionIsLogged(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute();

        self::assertCount(1, $this->detectionLogs());
        self::assertStringContainsString('BTC/USDT | Buy coinbase @ $100.00', $this->detectionLogs()[0]);
        self::assertStringContainsString('Sell kraken @ $110.00', $this->detectionLogs()[0]);
    }

    public function testAMarketWithNoSpreadProducesNothing(): void
    {
        $this->execute();

        self::assertSame([], $this->persisted);
        self::assertSame([], $this->dispatched);
        self::assertSame([], $this->detectionLogs());
        self::assertSame([], $this->logMessages(LogLevel::ERROR));
    }

    /**
     * Both orderings of every venue pair are evaluated, but a venue is never compared
     * against itself — the spread would be zero and the fees pure loss.
     */
    public function testTheReverseDirectionIsEvaluatedAndSelfComparisonIsNot(): void
    {
        // Reverse of the usual fixture: kraken is now the cheap side.
        $this->books = [
            'coinbase:' . self::PROFITABLE_PAIR => ['asks' => [[111.0, 10.0]], 'bids' => [[110.0, 10.0]]],
            'kraken:' . self::PROFITABLE_PAIR => ['asks' => [[100.0, 10.0]], 'bids' => [[99.0, 10.0]]],
        ];

        $this->execute();

        self::assertCount(1, $this->dispatched, 'exactly one direction is profitable, and self-pairs are skipped');
        self::assertSame('kraken', $this->dispatched[0]->getBuyExchange());
        self::assertSame('coinbase', $this->dispatched[0]->getSellExchange());
    }

    // ------------------------------------------------------------- POSITION SIZE

    /**
     * --size is the dial between "prove the pipeline works" and "put real money through
     * it", so what it actually controls is the quantity on the order that reaches a venue.
     * Everything else about the opportunity is unchanged by it.
     */
    public function testThePositionSizeReachesTheDispatchedTrade(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(size: 500.0);

        self::assertEqualsWithDelta(5.0, $this->dispatched[0]->getAmount(), 1e-9, '$500 of a $100 ask');
    }

    public function testTheDefaultIsAHundredDollarsWhenTheOptionIsOmitted(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute();

        self::assertEqualsWithDelta(1.0, $this->dispatched[0]->getAmount(), 1e-9);
    }

    /** The reason the option exists: a run that can only ever be wrong by $25. */
    public function testASmallSizeLimitsWhatOneOpportunityCanCommit(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(size: 25.0);

        self::assertEqualsWithDelta(0.25, $this->dispatched[0]->getAmount(), 1e-9);
    }

    public function testAFractionalSizeIsHonouredRatherThanRounded(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(size: '12.50');

        self::assertEqualsWithDelta(0.125, $this->dispatched[0]->getAmount(), 1e-9);
    }

    /**
     * Sizing past the visible book is not an opportunity at any price: the evaluator cannot
     * fill it, so nothing is dispatched. Worth pinning, because the failure mode of getting
     * this wrong would be a trade priced off liquidity that was never there.
     */
    public function testASizeTheBookCannotFillProducesNoOpportunity(): void
    {
        $this->books = $this->profitableSpread();

        // The ask holds 10.0 at 100, so $1,500 wants 15.0 and only 10.0 exists.
        $this->execute(size: 1_500.0);

        self::assertSame([], $this->dispatched);
        self::assertSame([], $this->persisted);
    }

    public function testTheBannerStatesWhatEachOpportunityWillCommit(): void
    {
        $tester = $this->execute(size: 2_500.0);

        self::assertStringContainsString('committing $2,500.00 per opportunity', $tester->getDisplay());
    }

    /**
     * A size that is not a size stops the run rather than being coerced into one. Left to
     * the evaluator this would throw from inside the scan loop's catch-all — four times a
     * second, forever, with the process still apparently running.
     */
    #[DataProvider('unusableSizeProvider')]
    public function testAnUnusableSizeStopsTheRunBeforeItStarts(string $size, string $expectedMessage): void
    {
        $this->books = $this->profitableSpread();

        $tester = $this->execute(size: $size);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($expectedMessage, $tester->getDisplay());
        self::assertSame([], $this->dispatched, 'nothing may be traded on a size nobody meant');
    }

    public static function unusableSizeProvider(): iterable
    {
        yield 'not a number' => ['abc', '--size must be an amount in USD, got "abc"'];
        yield 'typo for 100' => ['1o0', '--size must be an amount in USD'];
        yield 'amount with units' => ['100 USD', '--size must be an amount in USD'];
        yield 'zero' => ['0', '--size must be greater than zero'];
        yield 'negative' => ['-100', '--size must be greater than zero'];
        yield 'empty' => ['', '--size must be an amount in USD'];
    }

    public function testAnUnusableSizeIsCaughtBeforeAnythingIsWarmedOrRead(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(size: '0');

        self::assertSame([], $this->trace, 'no venue should be touched over a bad argument');
        self::assertSame([], $this->reads);
    }

    // ------------------------------------------------------------ MARGIN THRESHOLD

    /**
     * The test spread nets 9.314% after fees — buy 1.0 at 100, sell at 110, less coinbase's
     * 0.40% and kraken's 0.26%. Either side of that figure is what proves the threshold is
     * the operator's number and not a constant.
     */
    public function testASpreadThinnerThanTheThresholdIsLeftAlone(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(minMargin: 10.0);

        self::assertSame([], $this->dispatched, '9.314% net does not clear a 10% bar');
        self::assertSame([], $this->persisted);
    }

    public function testASpreadFatterThanTheThresholdIsTaken(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(minMargin: 9.0);

        self::assertCount(1, $this->dispatched);
    }

    /** Zero is a real setting: everything the fees do not eat is fair game. */
    public function testAZeroThresholdTakesEverySpreadThatClearsItsFees(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(minMargin: 0);

        self::assertCount(1, $this->dispatched);
    }

    public function testTheDefaultThresholdIsThirtyFiveBasisPoints(): void
    {
        $tester = $this->execute();

        self::assertStringContainsString('above 0.35% net margin', $tester->getDisplay());
    }

    public function testTheBannerStatesBothRiskDials(): void
    {
        $tester = $this->execute(size: 2_500.0, minMargin: 1.5);

        self::assertStringContainsString(
            'committing $2,500.00 per opportunity above 1.50% net margin',
            $tester->getDisplay()
        );
    }

    /**
     * The mistake that costs money rather than opportunities. A fraction typed into a
     * percentage option sets the bar six thousand times too low and turns the scanner loose
     * on spreads that cannot pay for themselves, so it is named rather than accepted.
     */
    public function testAFractionTypedIntoAPercentageOptionIsRefused(): void
    {
        $this->books = $this->profitableSpread();

        $tester = $this->execute(minMargin: '0.0035');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--min-margin is a percentage', $tester->getDisplay());
        self::assertStringContainsString('Did you mean 0.35?', $tester->getDisplay());
        self::assertSame([], $this->dispatched);
    }

    #[DataProvider('unusableMarginProvider')]
    public function testAnUnusableThresholdStopsTheRunBeforeItStarts(string $margin, string $expectedMessage): void
    {
        $this->books = $this->profitableSpread();

        $tester = $this->execute(minMargin: $margin);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($expectedMessage, $tester->getDisplay());
        self::assertSame([], $this->dispatched);
    }

    public static function unusableMarginProvider(): iterable
    {
        yield 'not a number' => ['abc', '--min-margin must be a percentage, got "abc"'];
        yield 'percent sign included' => ['0.35%', '--min-margin must be a percentage'];
        yield 'empty' => ['', '--min-margin must be a percentage'];
        yield 'negative' => ['-0.35', '--min-margin cannot be negative'];
    }

    public function testAnUnusableThresholdIsCaughtBeforeAnythingIsWarmedOrRead(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(minMargin: '-1');

        self::assertSame([], $this->trace, 'no venue should be touched over a bad argument');
        self::assertSame([], $this->reads);
    }

    /** Both dials are reported together, so one bad argument does not hide the other. */
    public function testTwoBadArgumentsAreBothReported(): void
    {
        $tester = $this->execute(size: '0', minMargin: 'abc');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--size must be greater than zero', $tester->getDisplay());
        self::assertStringContainsString('--min-margin must be a percentage', $tester->getDisplay());
    }

    // ---------------------------------------------------------- CIRCUIT BREAKER

    public function testAVenueBehindAnOpenBreakerIsNeverTraded(): void
    {
        $this->books = $this->profitableSpread();
        $this->allowed['kraken'] = false;

        $this->execute();

        self::assertSame([], $this->dispatched, 'the only profitable route needs kraken as the exit');
        self::assertSame([], $this->persisted, 'nothing is recorded for a trade that cannot be taken');
    }

    /**
     * The breaker gates execution, not observation. Books are still read from a blocked
     * venue so the scanner keeps a current picture and can act the moment it recovers.
     */
    public function testABlockedVenueIsStillPolledForItsBooks(): void
    {
        $this->books = $this->profitableSpread();
        $this->allowed['kraken'] = false;

        $this->execute();

        self::assertCount(count(self::PAIRS) * count(self::VENUES), $this->reads);
    }

    // --------------------------------------------------------------- RESILIENCE

    public function testAVenueThatCannotBeReadIsSkippedRatherThanCompared(): void
    {
        $this->books = $this->profitableSpread();
        $this->books['kraken:' . self::PROFITABLE_PAIR] = new \RuntimeException('502 Bad Gateway');

        $this->execute();

        self::assertSame([], $this->dispatched, 'a one-sided view is not an arbitrage');
        self::assertSame(
            ['Order book unavailable from kraken for BTC/USDT: 502 Bad Gateway'],
            $this->logMessages(LogLevel::DEBUG)
        );
    }

    public function testOnePairFailingDoesNotStopTheOthersFromScanning(): void
    {
        $this->books = $this->profitableSpread();
        $this->books['coinbase:ETH/USDT'] = new \RuntimeException('502 Bad Gateway');

        $this->execute();

        self::assertCount(1, $this->dispatched, 'BTC/USDT was still scanned after ETH/USDT lost a venue');
    }

    // --------------------------------------------------------- WRITE FAILURES

    /**
     * A connection that survived the failure may just have hit a deadlock, so the scan
     * carries on — but nothing is queued, because an opportunity that was never recorded
     * has no row for the execution handler to attach its trade to.
     */
    public function testARecoverableWriteFailureLetsTheScanContinue(): void
    {
        $this->books = $this->profitableSpread();
        $this->flushFailure = new \RuntimeException('deadlock detected');

        $tester = $this->execute();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->dispatched);
        self::assertCount(1, $this->logMessages(LogLevel::CRITICAL));
        self::assertStringContainsString(
            'Could not record BTC/USDT opportunity (buy coinbase, sell kraken): deadlock detected',
            $this->logMessages(LogLevel::CRITICAL)[0]
        );
    }

    public function testARecoverableWriteFailurePagesTheAdmin(): void
    {
        $this->books = $this->profitableSpread();
        $this->flushFailure = new \RuntimeException('deadlock detected');

        $this->execute();

        self::assertCount(1, $this->alerts);
        self::assertStringContainsString('cannot record opportunities', $this->alerts[0]['headline']);
        self::assertStringContainsString('deadlock detected', $this->alerts[0]['detail']);
    }

    /**
     * The point of the whole change. A standing database fault used to emit one error per
     * opportunity per cycle, four times a second, forever. It now pages once and then
     * falls back to logging, so the alert channel stays worth reading.
     */
    public function testAStandingWriteFailurePagesOnceAndThenOnlyLogs(): void
    {
        $this->books = $this->profitableSpread();
        $this->flushFailure = new \RuntimeException('deadlock detected');

        $this->execute(limit: 3);

        self::assertCount(1, $this->alerts, 'one page per run, however long the fault lasts');
        self::assertCount(3, $this->logMessages(LogLevel::CRITICAL), 'every occurrence is still recorded');
    }

    /**
     * Doctrine closes the entity manager after most write failures and never reopens it,
     * so every later flush would throw identically. Continuing would mean watching a
     * market it can no longer record — stop and let the supervisor restart us clean.
     */
    public function testAClosedEntityManagerStopsTheRunWithAFailureCode(): void
    {
        $this->books = $this->profitableSpread();
        $this->flushFailure = new \RuntimeException('server has gone away');
        $this->entityManagerOpen = false;

        $tester = $this->execute(limit: 99);

        self::assertSame(1, $tester->getStatusCode(), 'non-zero so the supervisor restarts the process');
        self::assertStringContainsString('Entity manager closed', $tester->getDisplay());
    }

    public function testAClosedEntityManagerAbandonsTheRestOfTheScan(): void
    {
        $this->books = $this->profitableSpread();
        $this->flushFailure = new \RuntimeException('server has gone away');
        $this->entityManagerOpen = false;

        $this->execute(limit: 99);

        // ETH/USDT scanned clean, BTC/USDT hit the failure; SOL/USDT is never reached and
        // no further cycle begins, despite the generous limit.
        self::assertSame(
            ['ETH/USDT', 'ETH/USDT', 'BTC/USDT', 'BTC/USDT'],
            array_column($this->reads, 'symbol')
        );
    }

    public function testAClosedEntityManagerPagesThatTheScannerHasStopped(): void
    {
        $this->books = $this->profitableSpread();
        $this->flushFailure = new \RuntimeException('server has gone away');
        $this->entityManagerOpen = false;

        $this->execute(limit: 99);

        self::assertCount(1, $this->alerts);
        self::assertStringContainsString('STOPPED', $this->alerts[0]['headline']);
        self::assertStringContainsString('restart', $this->alerts[0]['detail']);
        self::assertStringContainsString('server has gone away', $this->alerts[0]['detail']);
    }

    /**
     * SMS to pull someone's attention now, email to carry the detail an SMS cannot.
     * Symfony builds the text message from the notification subject, so the headline has
     * to stand alone — a page reading "see email" would be useless away from a desk.
     */
    public function testTheAdminIsRaisedByBothSmsAndEmail(): void
    {
        $this->books = $this->profitableSpread();
        $this->flushFailure = new \RuntimeException('deadlock detected');

        $this->execute();

        self::assertSame(['email', 'sms'], $this->alerts[0]['channels']);
        self::assertSame(self::ADMIN_EMAIL, $this->alerts[0]['recipient']->getEmail());
        self::assertSame(self::ADMIN_PHONE, $this->alerts[0]['recipient']->getPhone());
        self::assertLessThanOrEqual(
            160,
            strlen($this->alerts[0]['headline']),
            'the headline is the entire SMS; longer than one segment and carriers split it'
        );
    }

    public function testAHealthyScanNeverPagesAnyone(): void
    {
        $this->books = $this->profitableSpread();

        $this->execute(limit: 2);

        self::assertSame([], $this->alerts);
    }

    // --------------------------------------------------------------- RESILIENCE

    public function testAFailedDispatchDoesNotKillTheScanner(): void
    {
        $this->books = $this->profitableSpread();

        $tester = $this->execute(bus: $this->failingBus());

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['Scanner exception: transport unreachable'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    // ------------------------------------------------------------------ HELPERS

    /**
     * @return array<string, array>
     */
    private function profitableSpread(): array
    {
        return [
            'coinbase:' . self::PROFITABLE_PAIR => self::COINBASE_BOOK,
            'kraken:' . self::PROFITABLE_PAIR => self::KRAKEN_BOOK,
        ];
    }

    private function execute(
        int $limit = 1,
        ?MessageBusInterface $bus = null,
        string|float|null $size = null,
        string|float|null $minMargin = null,
    ): CommandTester {
        $factory = new ExchangeFactory(new ServiceLocator([
            'coinbase' => fn(): ExchangeServiceInterface => $this->venue('coinbase'),
            'kraken' => fn(): ExchangeServiceInterface => $this->venue('kraken'),
        ]));
        $logger = $this->recordingLogger();

        $command = new ArbitrageDetectionScannerCommand(
            $factory,
            new ExchangeWarmer($factory, $logger),
            new OrderBookFetcher($logger),
            new ArbitrageEvaluator(),
            $this->circuitBreaker(),
            $bus ?? $this->recordingBus(),
            $this->entityManager(),
            $logger,
            $this->recordingAlerter(),
            writeFailureBackoffSeconds: 0,
        );

        $options = ['--limit' => $limit];

        if ($size !== null) {
            $options['--size'] = $size;
        }

        if ($minMargin !== null) {
            $options['--min-margin'] = $minMargin;
        }

        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    /**
     * Memoised, because Symfony services are shared: two create() calls for the same
     * venue hand back one object. That matters beyond bookkeeping — ExchangeWarmer
     * resolves the venue independently of the command, and the markets it loads are only
     * of any use to the scan because both end up holding the same ccxt client. A locator
     * that minted a new instance per call would make the warm-up silently pointless, so
     * the double must not model one.
     */
    private function venue(string $name): ExchangeServiceInterface
    {
        $this->venueLookups[$name] = ($this->venueLookups[$name] ?? 0) + 1;

        return $this->venues[$name] ??= $this->makeVenue($name);
    }

    private function makeVenue(string $name): ExchangeServiceInterface
    {
        $venue = $this->createStub(ExchangeServiceInterface::class);

        $venue->method('warmUp')->willReturnCallback(
            function () use ($name): PromiseInterface {
                $this->trace[] = "warm:{$name}";

                return $this->resolved(null);
            }
        );

        $venue->method('getOrderBookAsync')->willReturnCallback(
            function (string $symbol, ?int $limit) use ($name): PromiseInterface {
                $this->reads[] = ['venue' => $name, 'symbol' => $symbol, 'limit' => $limit];
                $this->trace[] = "read:{$name}";

                $book = $this->books["{$name}:{$symbol}"] ?? self::EMPTY_BOOK;

                if ($book instanceof \Throwable) {
                    $deferred = new Deferred();
                    $deferred->reject($book);

                    return $deferred->promise();
                }

                return $this->resolved($book);
            }
        );

        return $venue;
    }

    /**
     * Built from a Deferred rather than React\Promise\resolve(), whose documented
     * parameter unions the plain value with PromiseInterface itself; static analysis
     * matches against the promise arm and reports a plain value as a type error.
     * Same promise either way.
     *
     * @return PromiseInterface<mixed>
     */
    private function resolved(mixed $value): PromiseInterface
    {
        $deferred = new Deferred();
        $deferred->resolve($value);

        return $deferred->promise();
    }

    private function circuitBreaker(): TradingCircuitBreaker
    {
        $breaker = $this->createStub(TradingCircuitBreaker::class);

        $breaker->method('isAllowed')->willReturnCallback(
            fn(string $exchange): bool => $this->allowed[$exchange] ?? true
        );

        return $breaker;
    }

    /**
     * The real alerter over a stubbed notifier, so the channel selection and message
     * construction under test are the ones that would actually run.
     */
    private function recordingAlerter(): AdminAlerter
    {
        $notifier = $this->createStub(NotifierInterface::class);

        $notifier->method('send')->willReturnCallback(
            function (Notification $notification, RecipientInterface ...$recipients): void {
                $this->alerts[] = [
                    'headline' => $notification->getSubject(),
                    'detail' => $notification->getContent(),
                    'channels' => $notification->getChannels($recipients[0]),
                    'recipient' => $recipients[0],
                ];
            }
        );

        return new AdminAlerter(
            $notifier,
            $this->recordingLogger(),
            self::ADMIN_PHONE,
            self::ADMIN_EMAIL,
        );
    }

    private function recordingBus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);

        $bus->method('dispatch')->willReturnCallback(
            function (object $message): Envelope {
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        );

        return $bus;
    }

    private function failingBus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);

        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport unreachable'));

        return $bus;
    }

    /**
     * flush() assigns the identity the same way Doctrine does — by reflection, since the
     * property is private(set). Without it the command would hand a null id to a message
     * that requires a string.
     */
    private function entityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $em->method('persist')->willReturnCallback(
            function (object $entity): void {
                $this->persisted[] = $entity;
            }
        );
        $em->method('flush')->willReturnCallback(
            function (): void {
                if ($this->flushFailure !== null) {
                    throw $this->flushFailure;
                }

                foreach ($this->persisted as $entity) {
                    if ($entity->id === null) {
                        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);
                    }
                }
            }
        );
        $em->method('isOpen')->willReturnCallback(fn(): bool => $this->entityManagerOpen);

        return $em;
    }

    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        foreach ([LogLevel::INFO, LogLevel::ERROR, LogLevel::DEBUG, LogLevel::CRITICAL] as $level) {
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

    /**
     * Detections only — the info channel also carries ExchangeWarmer's per-venue
     * pre-load lines, which are startup noise as far as these assertions go.
     *
     * @return list<string>
     */
    private function detectionLogs(): array
    {
        return array_values(array_filter(
            $this->logMessages(LogLevel::INFO),
            static fn(string $message): bool => str_contains($message, 'ARBITRAGE DETECTED')
        ));
    }
}
