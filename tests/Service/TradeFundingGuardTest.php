<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AdminAlerter;
use App\Service\ExchangeService\ExchangeServiceInterface;
use App\Service\FundingReport;
use App\Service\FundingVerdict;
use App\Service\TradeFundingGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * This guard is the last thing between a detected opportunity and real money, so the
 * tests are organised around the three answers it can give per leg and what each one
 * costs if it is wrong. A false Sufficient sends an order the venue will reject with the
 * other leg already filled. A false Short quietly stops the strategy. A false Unknown
 * does both at once, since the handler charges it to the circuit breaker.
 *
 * A real ArrayAdapter and a real AdminAlerter are driven rather than mocked — the
 * assertions are about throttling behaviour and what the on-call receives, not about how
 * many times the cache was poked. The venue doubles settle on loop timers so the two
 * balance reads overlap exactly as real in-flight requests would.
 */
#[CoversClass(TradeFundingGuard::class)]
#[CoversClass(FundingReport::class)]
final class TradeFundingGuardTest extends TestCase
{
    private const string BUY_VENUE = 'coinbase';
    private const string SELL_VENUE = 'kraken';
    private const string SYMBOL = 'ETH/USDT';
    private const float AMOUNT = 2.0;
    private const float BUY_PRICE = 100.0;

    /** What the buy leg costs at the quoted price, before any margin. */
    private const float BUY_COST = 200.0;

    private const string PHONE = '+15555550123';
    private const string EMAIL = 'ops@example.com';

    private const int THROTTLE_SECONDS = 3600;

    private ArrayAdapter $cache;

    /** @var list<array{notification: Notification, recipients: list<RecipientInterface>}> */
    private array $sent = [];

    /** When set, the notifier throws — an alerting outage on top of an empty account. */
    private ?\Throwable $notifierFailure = null;

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    private int $balanceReads = 0;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->sent = [];
        $this->notifierFailure = null;
        $this->loggedMessages = [];
        $this->balanceReads = 0;
    }

    // ------------------------------------------------------------------- SELL LEG

    public function testAFundedSellVenueClearsTheSellLeg(): void
    {
        $report = $this->clear(sellVenue: $this->venueHolding(['ETH' => 5.0]));

        self::assertSame(FundingVerdict::Sufficient, $report->sell);
        self::assertTrue($report->bothLegsCleared());
    }

    public function testAClearedTradeIsSilent(): void
    {
        $this->clear();

        self::assertSame([], $this->sent, 'a funded pair of accounts is not news');
        self::assertSame([], $this->logMessages(LogLevel::WARNING));
        self::assertSame([], $this->logMessages(LogLevel::ERROR));
    }

    public function testAnExactCoverClearsWhenNoMarginIsAsked(): void
    {
        $report = $this->clear(sellVenue: $this->venueHolding(['ETH' => 2.0]));

        self::assertSame(FundingVerdict::Sufficient, $report->sell);
    }

    public function testTheSellMarginIsAppliedOnTopOfTheOrderSize(): void
    {
        // 2.0 ETH at a 50% margin needs 3.0 free.
        $guard = $this->guard(sellSafetyMargin: 0.5);

        self::assertSame(
            FundingVerdict::Sufficient,
            $this->clear(sellVenue: $this->venueHolding(['ETH' => 3.0]), guard: $guard)->sell
        );
        self::assertSame(
            FundingVerdict::Short,
            $this->clear(sellVenue: $this->venueHolding(['ETH' => 2.9]), guard: $guard)->sell
        );
    }

    /**
     * The reason this reads `free` and not `total`. Coin already committed to an open order
     * cannot be sold a second time, so a balance that looks ample in aggregate is not one
     * the venue will let us trade against.
     */
    public function testCoinLockedInOpenOrdersDoesNotCountTowardsTheSell(): void
    {
        $sellVenue = $this->venueReturning([
            'free' => ['ETH' => 0.5],
            'used' => ['ETH' => 4.5],
            'total' => ['ETH' => 5.0],
        ]);

        self::assertSame(FundingVerdict::Short, $this->clear(sellVenue: $sellVenue)->sell);
    }

    /**
     * A venue that lists its wallets and does not mention this asset is telling us it holds
     * none of it. That is a firm zero, not a gap in the response.
     */
    public function testAnAssetMissingFromTheWalletListIsAFirmZero(): void
    {
        $report = $this->clear(sellVenue: $this->venueHolding(['USDT' => 10_000.0]));

        self::assertSame(FundingVerdict::Short, $report->sell);
    }

    public function testNumericStringsFromTheVenueAreRead(): void
    {
        $report = $this->clear(sellVenue: $this->venueHolding(['ETH' => '5.0']));

        self::assertSame(FundingVerdict::Sufficient, $report->sell);
    }

    /**
     * ccxt also exposes balances per currency alongside the free/used/total maps, and not
     * every venue populates both. Missing the second shape would read a well funded account
     * as empty.
     */
    public function testThePerCurrencyBalanceShapeIsUnderstood(): void
    {
        $sellVenue = $this->venueReturning([
            'ETH' => ['free' => 5.0, 'used' => 0.0, 'total' => 5.0],
            'USDT' => ['free' => 100.0, 'used' => 0.0, 'total' => 100.0],
        ]);

        self::assertSame(FundingVerdict::Sufficient, $this->clear(sellVenue: $sellVenue)->sell);
    }

    public function testTheSellShortfallIsLoggedWithBothFigures(): void
    {
        $this->clear(sellVenue: $this->venueHolding(['ETH' => 0.25]));

        self::assertSame(
            ['Insufficient ETH on kraken to sell 2.00000000 ETH/USDT (2.00000000 required with margin, 0.25000000 free).'],
            $this->logMessages(LogLevel::WARNING)
        );
    }

    // -------------------------------------------------------------------- BUY LEG

    public function testAFundedBuyVenueClearsTheBuyLeg(): void
    {
        $report = $this->clear(buyVenue: $this->venueHolding(['USDT' => 500.0]));

        self::assertSame(FundingVerdict::Sufficient, $report->buy);
        self::assertTrue($report->bothLegsCleared());
    }

    public function testABuyVenueShortOfCashIsRefused(): void
    {
        $report = $this->clear(buyVenue: $this->venueHolding(['USDT' => 100.0]));

        self::assertSame(FundingVerdict::Short, $report->buy);
        self::assertFalse($report->bothLegsCleared());
    }

    /**
     * The buy leg spends quote currency, not the asset it is acquiring. Checking the wrong
     * side of the pair would read a wallet full of ETH as clearance to buy ETH with USDT
     * that is not there.
     */
    public function testTheAssetCheckedOnTheBuyLegIsTheQuoteCurrency(): void
    {
        $report = $this->clear(buyVenue: $this->venueHolding(['ETH' => 1_000.0]));

        self::assertSame(FundingVerdict::Short, $report->buy);
    }

    /** Cost is quantity times the price the scanner quoted, so 2.0 ETH at 100 needs 200 USDT. */
    public function testTheBuyCostIsDerivedFromTheQuotedPrice(): void
    {
        self::assertSame(
            FundingVerdict::Sufficient,
            $this->clear(buyVenue: $this->venueHolding(['USDT' => self::BUY_COST]))->buy
        );
        self::assertSame(
            FundingVerdict::Short,
            $this->clear(buyVenue: $this->venueHolding(['USDT' => self::BUY_COST - 0.01]))->buy
        );
    }

    public function testTheBuyMarginIsAppliedToTheCost(): void
    {
        // 200 USDT of coin at a 50% margin needs 300 free.
        $guard = $this->guard(buySafetyMargin: 0.5);

        self::assertSame(
            FundingVerdict::Sufficient,
            $this->clear(buyVenue: $this->venueHolding(['USDT' => 300.0]), guard: $guard)->buy
        );
        self::assertSame(
            FundingVerdict::Short,
            $this->clear(buyVenue: $this->venueHolding(['USDT' => 299.0]), guard: $guard)->buy
        );
    }

    /**
     * The two margins are independent knobs: the buy leg needs the larger one for slippage
     * and a quote-denominated fee, and turning it up must not quietly tighten the sell side.
     */
    public function testTheTwoMarginsDoNotBleedIntoEachOther(): void
    {
        $guard = $this->guard(buySafetyMargin: 0.5, sellSafetyMargin: 0.0);

        $report = $this->clear(
            buyVenue: $this->venueHolding(['USDT' => 300.0]),
            sellVenue: $this->venueHolding(['ETH' => 2.0]),
            guard: $guard
        );

        self::assertTrue($report->bothLegsCleared());
    }

    public function testTheBuyShortfallIsLoggedWithBothFigures(): void
    {
        $this->clear(buyVenue: $this->venueHolding(['USDT' => 12.5]));

        self::assertSame(
            ['Insufficient USDT on coinbase to buy 2.00000000 ETH/USDT (200.00000000 required with margin, 12.50000000 free).'],
            $this->logMessages(LogLevel::WARNING)
        );
    }

    public function testAnUnderfundedBuyVenuePagesAboutTheQuoteCurrency(): void
    {
        $this->clear(buyVenue: $this->venueHolding([]));

        self::assertCount(1, $this->sent);
        self::assertSame(
            '⚠️ Out of USDT on coinbase — arbitrage buys are being skipped',
            $this->sent[0]['notification']->getSubject()
        );
    }

    // ---------------------------------------------------------------- BOTH LEGS

    public function testEachLegIsJudgedOnItsOwnVenue(): void
    {
        $report = $this->clear(
            buyVenue: $this->venueHolding(['USDT' => 10.0]),
            sellVenue: $this->venueHolding(['ETH' => 500.0])
        );

        self::assertSame(FundingVerdict::Short, $report->buy);
        self::assertSame(FundingVerdict::Sufficient, $report->sell);
    }

    public function testATradeBlockedOnBothSidesReportsBothSides(): void
    {
        $report = $this->clear(
            buyVenue: $this->venueHolding([]),
            sellVenue: $this->venueHolding([])
        );

        self::assertSame(FundingVerdict::Short, $report->buy);
        self::assertSame(FundingVerdict::Short, $report->sell);
        self::assertCount(2, $this->sent, 'two accounts to fund, two things for the on-call to do');
    }

    /**
     * Why the reads settle rather than being handed straight to all(): a rejection there
     * would discard the other venue's answer with it, and the two are acted on differently —
     * only the unreadable one is charged to its circuit breaker.
     */
    public function testOneLegFailingDoesNotHideTheOther(): void
    {
        $report = $this->clear(
            buyVenue: $this->brokenVenue(new \RuntimeException('403 Invalid API key')),
            sellVenue: $this->venueHolding(['ETH' => 0.1])
        );

        self::assertSame(FundingVerdict::Unknown, $report->buy);
        self::assertSame(FundingVerdict::Short, $report->sell);
    }

    /**
     * Wall-clock proof that the pre-flight costs one round trip and not two. Two 30ms reads
     * taken in sequence would be ~60ms; the bound is loose enough not to flake on a busy
     * machine but well below what serialised reads could manage.
     */
    public function testBothBalancesAreReadConcurrently(): void
    {
        $buyVenue = $this->venueHolding(['USDT' => 500.0], delay: 0.03);
        $sellVenue = $this->venueHolding(['ETH' => 5.0], delay: 0.03);

        $start = microtime(true);
        $this->clear(buyVenue: $buyVenue, sellVenue: $sellVenue);
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertGreaterThanOrEqual(30, $elapsedMs, 'both reads really did wait ~30ms');
        self::assertLessThan(55, $elapsedMs, 'sequential reads would have cost ~60ms');
    }

    #[DataProvider('unclearedCombinationProvider')]
    public function testBothLegsClearedOnlyHoldsWhenNeitherLegIsBlocked(
        FundingVerdict $buy,
        FundingVerdict $sell,
        bool $expected,
    ): void {
        self::assertSame($expected, (new FundingReport($buy, $sell))->bothLegsCleared());
    }

    public static function unclearedCombinationProvider(): iterable
    {
        yield 'both clear' => [FundingVerdict::Sufficient, FundingVerdict::Sufficient, true];
        yield 'buy short' => [FundingVerdict::Short, FundingVerdict::Sufficient, false];
        yield 'sell short' => [FundingVerdict::Sufficient, FundingVerdict::Short, false];
        yield 'buy unknown' => [FundingVerdict::Unknown, FundingVerdict::Sufficient, false];
        yield 'sell unknown' => [FundingVerdict::Sufficient, FundingVerdict::Unknown, false];
        yield 'both blocked' => [FundingVerdict::Short, FundingVerdict::Unknown, false];
    }

    // -------------------------------------------------------------------- UNKNOWN

    /**
     * Fail closed. Not knowing whether a leg can settle is the situation this guard exists
     * to avoid trading through — reading it as "probably fine" would reinstate the partial
     * fill for exactly the venues least able to cope with one.
     */
    public function testABalanceReadThatRejectsLeavesTheLegUncleared(): void
    {
        $report = $this->clear(sellVenue: $this->brokenVenue(new \RuntimeException('403 Invalid API key')));

        self::assertSame(FundingVerdict::Unknown, $report->sell);
        self::assertSame(
            ['Could not read ETH balance on kraken, so the sell leg cannot be cleared: 403 Invalid API key'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    /**
     * ccxt validates before it dispatches, so the client can raise instead of handing back a
     * promise. Unhandled, that would escape the guard as an exception rather than a verdict.
     */
    public function testAClientThatThrowsBeforeReturningAPromiseIsStillAVerdict(): void
    {
        $report = $this->clear(sellVenue: $this->synchronouslyBrokenVenue(new \RuntimeException('not supported')));

        self::assertSame(FundingVerdict::Unknown, $report->sell);
    }

    /**
     * The distinction that keeps a working venue tradeable: ccxt reports null for exchanges
     * that do not break out the free portion. Reading that as zero would refuse to trade on
     * an account with plenty in it, permanently.
     */
    public function testANullFigureIsUnknownRatherThanZero(): void
    {
        $report = $this->clear(sellVenue: $this->venueHolding(['ETH' => null]));

        self::assertSame(FundingVerdict::Unknown, $report->sell);
        self::assertNotSame(FundingVerdict::Short, $report->sell, 'a silent venue is not an empty one');
    }

    public function testANonNumericFigureIsUnknown(): void
    {
        $report = $this->clear(sellVenue: $this->venueHolding(['ETH' => 'n/a']));

        self::assertSame(FundingVerdict::Unknown, $report->sell);
    }

    public function testABalancePayloadInNeitherShapeIsUnknown(): void
    {
        $report = $this->clear(sellVenue: $this->venueReturning(['info' => ['error' => []]]));

        self::assertSame(FundingVerdict::Unknown, $report->sell);
        self::assertSame(
            ['Balance response from kraken carried no readable ETH figure; treating the sell leg as uncleared.'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    /**
     * An unreadable balance is a venue fault, and the handler charges it to the circuit
     * breaker. Paging as well would double-report one incident.
     */
    #[DataProvider('unreadableBalanceProvider')]
    public function testAnUnreadableBalancePagesNobody(string $factory, mixed $argument): void
    {
        $this->clear(sellVenue: $this->{$factory}($argument));

        self::assertSame([], $this->sent);
    }

    public static function unreadableBalanceProvider(): iterable
    {
        yield 'read rejected' => ['brokenVenue', new \RuntimeException('timeout')];
        yield 'client threw' => ['synchronouslyBrokenVenue', new \RuntimeException('not supported')];
        yield 'null figure' => ['venueHolding', ['ETH' => null]];
        yield 'unrecognised payload' => ['venueReturning', ['info' => []]];
    }

    // ------------------------------------------------------------------- SYMBOLS

    public function testASettlementSuffixDoesNotConfuseEitherAsset(): void
    {
        $report = $this->clear(symbol: 'ETH/USDT:USDT');

        self::assertTrue($report->bothLegsCleared());
    }

    public function testLowercaseSymbolsResolveToTheSameAssets(): void
    {
        $report = $this->clear(symbol: 'eth/usdt');

        self::assertTrue($report->bothLegsCleared());
    }

    /**
     * Guessing at a malformed symbol is worse than refusing it: the balance of whatever was
     * guessed would come back as a pass, and the guard would report clearance for a trade it
     * never actually checked.
     */
    #[DataProvider('unusableSymbolProvider')]
    public function testAnUnusableSymbolIsRejectedRatherThanGuessedAt(string $symbol): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->clear(symbol: $symbol);
    }

    public static function unusableSymbolProvider(): iterable
    {
        yield 'no separator' => ['ETHUSDT'];
        yield 'empty base' => ['/USDT'];
        yield 'blank base' => ['  /USDT'];
        yield 'empty quote' => ['ETH/'];
        yield 'bare settlement suffix' => ['ETH/:USDT'];
        yield 'empty string' => [''];
    }

    /**
     * A zero size or price makes the requirement zero, which every account trivially
     * satisfies — the guard would report clearance without having checked anything.
     */
    #[DataProvider('unusableTradeProvider')]
    public function testATradeThatCannotBeSizedIsRejected(float $amount, float $price): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->clear(amount: $amount, price: $price);
    }

    public static function unusableTradeProvider(): iterable
    {
        yield 'zero size' => [0.0, self::BUY_PRICE];
        yield 'negative size' => [-1.0, self::BUY_PRICE];
        yield 'zero price' => [self::AMOUNT, 0.0];
        yield 'negative price' => [self::AMOUNT, -100.0];
    }

    public function testTheArgumentsAreValidatedBeforeAnyBalanceIsRead(): void
    {
        try {
            $this->clear(symbol: 'ETHUSDT');
        } catch (\InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, $this->balanceReads, 'no point spending a round trip on a trade we cannot size');
    }

    // -------------------------------------------------------------------- PAGING

    /**
     * Nothing else will report this. A shortfall is not a venue failure, so the circuit
     * breaker never sees it, and the strategy just goes quiet on the pair until somebody
     * funds the account.
     */
    public function testAnEmptyAccountPagesTheAdminOnBothChannels(): void
    {
        $this->clear(sellVenue: $this->venueHolding([]));

        self::assertCount(1, $this->sent);
        self::assertSame(['email', 'sms'], $this->channelsOf(0));
    }

    public function testThePageNamesTheAssetTheVenueAndTheLeg(): void
    {
        $this->clear(sellVenue: $this->venueHolding([]));

        self::assertSame(
            '⚠️ Out of ETH on kraken — arbitrage sells are being skipped',
            $this->sent[0]['notification']->getSubject()
        );
    }

    public function testThePageSpellsOutWhatWasNeededAndWhatWasThere(): void
    {
        $this->clear(sellVenue: $this->venueHolding(['ETH' => 0.25]));

        $content = $this->sent[0]['notification']->getContent();

        self::assertStringContainsString('Required: 2.00000000 ETH', $content);
        self::assertStringContainsString('Free:     0.25000000 ETH', $content);
        self::assertStringContainsString('No orders were placed', $content);
    }

    /**
     * The scanner detects the same opportunity several times a second, so an unthrottled
     * page would send hundreds of texts about one empty account — and train the recipient to
     * ignore the channel that also carries open-position alerts.
     */
    public function testRepeatShortfallsInsideTheWindowPageOnlyOnce(): void
    {
        $guard = $this->guard();
        $sellVenue = $this->venueHolding([]);

        $this->clear(sellVenue: $sellVenue, guard: $guard);
        $this->clear(sellVenue: $sellVenue, guard: $guard);
        $this->clear(sellVenue: $sellVenue, guard: $guard);

        self::assertCount(1, $this->sent);
    }

    public function testEveryShortfallIsStillLoggedWhileThePagerIsQuiet(): void
    {
        $guard = $this->guard();
        $sellVenue = $this->venueHolding([]);

        $this->clear(sellVenue: $sellVenue, guard: $guard);
        $this->clear(sellVenue: $sellVenue, guard: $guard);

        self::assertCount(2, $this->logMessages(LogLevel::WARNING), 'throttling the pager must not blind the log');
    }

    public function testTheThrottleIsPerAssetSoASecondEmptyWalletIsStillReported(): void
    {
        $guard = $this->guard();
        $sellVenue = $this->venueHolding([]);

        $this->clear(sellVenue: $sellVenue, symbol: 'ETH/USDT', guard: $guard);
        $this->clear(sellVenue: $sellVenue, symbol: 'BTC/USDT', guard: $guard);

        self::assertCount(2, $this->sent);
    }

    /**
     * Same asset, different venue. Suppressing the second would hide an empty wallet on one
     * exchange because a different exchange reported one first.
     */
    public function testTheThrottleIsPerVenue(): void
    {
        $guard = $this->guard();
        $empty = $this->venueHolding([]);
        $funded = $this->wellFundedVenue();

        // The same missing asset on two venues, so only the venue distinguishes them.
        $guard->clearLegs($funded, self::BUY_VENUE, $empty, 'kraken', self::SYMBOL, self::AMOUNT, self::BUY_PRICE);
        $guard->clearLegs($funded, self::BUY_VENUE, $empty, 'binance', self::SYMBOL, self::AMOUNT, self::BUY_PRICE);

        self::assertCount(2, $this->sent);
    }

    public function testThePagerSpeaksAgainOnceTheWindowHasLapsed(): void
    {
        $guard = $this->guard();
        $sellVenue = $this->venueHolding([]);

        $this->clear(sellVenue: $sellVenue, guard: $guard);
        $this->cache->clear(); // the throttle entry has expired
        $this->clear(sellVenue: $sellVenue, guard: $guard);

        self::assertCount(2, $this->sent, 'an account still empty an hour later is worth saying again');
    }

    /**
     * The throttle entry must carry an expiry. Without one the first page would be the last
     * one ever sent for that venue and asset.
     */
    public function testTheThrottleEntryExpires(): void
    {
        $guard = $this->guard(throttleSeconds: 0);
        $sellVenue = $this->venueHolding([]);

        $this->clear(sellVenue: $sellVenue, guard: $guard);
        $this->clear(sellVenue: $sellVenue, guard: $guard);

        self::assertCount(2, $this->sent);
    }

    // ------------------------------------------------- ALERTING FAILS SAFE

    /**
     * The verdict is the product; the page is a courtesy on top of it. An alerting problem
     * must never propagate into the handler, which would turn "skip this trade" into an
     * exception mid-execution.
     */
    public function testANotifierOutageStillYieldsTheVerdict(): void
    {
        $this->notifierFailure = new \RuntimeException('SNS unreachable');

        $report = $this->clear(sellVenue: $this->venueHolding([]));

        self::assertSame(FundingVerdict::Short, $report->sell);
    }

    public function testACacheOutageStillYieldsTheVerdict(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('redis is gone'));

        $report = $this->clear(sellVenue: $this->venueHolding([]), guard: $this->guard(cache: $cache));

        self::assertSame(FundingVerdict::Short, $report->sell);
        self::assertSame(
            ['Failed to alert admin about insufficient ETH on kraken: redis is gone'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    // ---------------------------------------------------------------- CONFIG

    /**
     * A negative margin demands less than the leg needs, which silently defeats the guard.
     * Refusing to start beats trading behind a risk control wired backwards.
     */
    public function testANegativeBuyMarginIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The buy funding safety margin cannot be negative');

        $this->guard(buySafetyMargin: -0.1);
    }

    public function testANegativeSellMarginIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The sell funding safety margin cannot be negative');

        $this->guard(sellSafetyMargin: -0.1);
    }

    // -------------------------------------------------------------------- HELPERS

    /**
     * Clears a trade whose unspecified side is comfortably funded, so each test only has to
     * describe the account it is actually about.
     */
    private function clear(
        ?ExchangeServiceInterface $buyVenue = null,
        ?ExchangeServiceInterface $sellVenue = null,
        string $symbol = self::SYMBOL,
        float $amount = self::AMOUNT,
        float $price = self::BUY_PRICE,
        ?TradeFundingGuard $guard = null,
    ): FundingReport {
        return ($guard ?? $this->guard())->clearLegs(
            $buyVenue ?? $this->wellFundedVenue(),
            self::BUY_VENUE,
            $sellVenue ?? $this->wellFundedVenue(),
            self::SELL_VENUE,
            $symbol,
            $amount,
            $price,
        );
    }

    private function guard(
        float $buySafetyMargin = 0.0,
        float $sellSafetyMargin = 0.0,
        int $throttleSeconds = self::THROTTLE_SECONDS,
        ?CacheInterface $cache = null,
    ): TradeFundingGuard {
        $logger = $this->recordingLogger();

        return new TradeFundingGuard(
            $cache ?? $this->cache,
            new AdminAlerter($this->recordingNotifier(), $logger, self::PHONE, self::EMAIL),
            $logger,
            $buySafetyMargin,
            $sellSafetyMargin,
            $throttleSeconds,
        );
    }

    private function wellFundedVenue(): ExchangeServiceInterface
    {
        return $this->venueHolding(['ETH' => 1_000.0, 'BTC' => 1_000.0, 'USDT' => 1_000_000.0, 'USD' => 1_000_000.0]);
    }

    /**
     * A venue reporting the given free balances in ccxt's free/used/total shape.
     *
     * @param array<string, mixed> $free
     */
    private function venueHolding(array $free, float $delay = 0.0): ExchangeServiceInterface
    {
        return $this->venueReturning([
            'free' => $free,
            'used' => array_map(static fn (): float => 0.0, $free),
            'total' => $free,
        ], $delay);
    }

    private function venueReturning(array $balance, float $delay = 0.0): ExchangeServiceInterface
    {
        return $this->venue(
            static fn (Deferred $deferred): mixed => $deferred->resolve($balance),
            $delay
        );
    }

    /** A venue whose balance read is dispatched and then rejected, as a failed request is. */
    private function brokenVenue(\Throwable $failure, float $delay = 0.0): ExchangeServiceInterface
    {
        return $this->venue(
            static fn (Deferred $deferred): mixed => $deferred->reject($failure),
            $delay
        );
    }

    /** A client that raises instead of handing back a promise, as ccxt does when it validates. */
    private function synchronouslyBrokenVenue(\Throwable $failure): ExchangeServiceInterface
    {
        $venue = $this->createStub(ExchangeServiceInterface::class);

        $venue->method('getBalanceAsync')->willReturnCallback(
            function () use ($failure): PromiseInterface {
                ++$this->balanceReads;

                throw $failure;
            }
        );

        return $venue;
    }

    /**
     * Settling on a loop timer rather than immediately is what lets the two reads overlap,
     * exactly as real in-flight HTTP requests would.
     */
    private function venue(\Closure $settle, float $delay): ExchangeServiceInterface
    {
        $venue = $this->createStub(ExchangeServiceInterface::class);

        $venue->method('getBalanceAsync')->willReturnCallback(
            function () use ($settle, $delay): PromiseInterface {
                ++$this->balanceReads;

                $deferred = new Deferred();
                Loop::addTimer($delay, static fn (): mixed => $settle($deferred));

                return $deferred->promise();
            }
        );

        return $venue;
    }

    private function recordingNotifier(): NotifierInterface
    {
        $notifier = $this->createStub(NotifierInterface::class);

        $notifier->method('send')->willReturnCallback(
            function (Notification $notification, RecipientInterface ...$recipients): void {
                if ($this->notifierFailure !== null) {
                    throw $this->notifierFailure;
                }

                $this->sent[] = ['notification' => $notification, 'recipients' => $recipients];
            }
        );

        return $notifier;
    }

    /**
     * @return list<string>
     */
    private function channelsOf(int $index): array
    {
        $entry = $this->sent[$index];

        return array_values($entry['notification']->getChannels($entry['recipients'][0]));
    }

    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        foreach ([LogLevel::WARNING, LogLevel::ERROR] as $level) {
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
