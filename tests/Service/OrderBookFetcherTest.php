<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ExchangeService\ExchangeServiceInterface;
use App\Service\OrderBookFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Two properties matter here, and both are asserted directly rather than inferred:
 * the reads genuinely overlap (a slow venue must not delay a fast one), and a venue
 * that fails drops out of the round instead of taking the scan down with it.
 */
#[CoversClass(OrderBookFetcher::class)]
final class OrderBookFetcherTest extends TestCase
{
    private const string SYMBOL = 'ETH/USDT';

    private const array COINBASE_BOOK = ['asks' => [[100.0, 5.0]], 'bids' => [[99.0, 5.0]]];
    private const array KRAKEN_BOOK = ['asks' => [[101.0, 5.0]], 'bids' => [[100.5, 5.0]]];

    /** @var array<string, array|\Throwable> venue => what its read produces */
    private array $outcomes = [];

    /** @var array<string, float> venue => seconds on the loop before the read settles */
    private array $delays = [];

    /** @var list<array{venue: string, symbol: string, limit: int|null}> */
    private array $reads = [];

    /** @var list<string> read lifecycle markers, in the sequence they occurred */
    private array $trace = [];

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        $this->outcomes = [];
        $this->delays = [];
        $this->reads = [];
        $this->trace = [];
        $this->loggedMessages = [];
    }

    // --------------------------------------------------------------------- HAPPY PATH

    public function testEveryVenueIsReadAndKeyedByName(): void
    {
        $this->outcomes = ['coinbase' => self::COINBASE_BOOK, 'kraken' => self::KRAKEN_BOOK];

        $books = $this->fetch();

        self::assertSame(['coinbase' => self::COINBASE_BOOK, 'kraken' => self::KRAKEN_BOOK], $books);
    }

    public function testTheSymbolAndDepthLimitAreForwardedToEveryVenue(): void
    {
        $this->outcomes = ['coinbase' => self::COINBASE_BOOK, 'kraken' => self::KRAKEN_BOOK];

        $this->fetch(symbol: 'BTC/USDT', limit: 10);

        self::assertSame([
            ['venue' => 'coinbase', 'symbol' => 'BTC/USDT', 'limit' => 10],
            ['venue' => 'kraken', 'symbol' => 'BTC/USDT', 'limit' => 10],
        ], $this->reads);
    }

    public function testAnOmittedLimitIsPassedThroughAsNull(): void
    {
        $this->outcomes = ['coinbase' => self::COINBASE_BOOK];

        $this->fetch();

        self::assertSame([['venue' => 'coinbase', 'symbol' => self::SYMBOL, 'limit' => null]], $this->reads);
    }

    public function testAnEmptyVenueListIsANoOp(): void
    {
        self::assertSame([], $this->fetch());
        self::assertSame([], $this->reads);
    }

    /**
     * An empty book is a real answer from a live venue — the evaluator already treats
     * it as "no opportunity". Only a failed read should be dropped.
     */
    public function testAnEmptyBookIsKeptRatherThanTreatedAsAFailure(): void
    {
        $this->outcomes = ['coinbase' => [], 'kraken' => self::KRAKEN_BOOK];

        $books = $this->fetch();

        self::assertSame(['coinbase' => [], 'kraken' => self::KRAKEN_BOOK], $books);
    }

    // -------------------------------------------------------------------- CONCURRENCY

    public function testEveryReadIsDispatchedBeforeAnyOfThemSettles(): void
    {
        $this->outcomes = ['coinbase' => self::COINBASE_BOOK, 'kraken' => self::KRAKEN_BOOK];

        $this->fetch();

        self::assertSame([
            'coinbase:dispatched',
            'kraken:dispatched',
            'coinbase:settled',
            'kraken:settled',
        ], $this->trace);
    }

    /**
     * The decisive one: coinbase is asked first but answers last. Under the previous
     * blocking implementation it would have had to return before kraken was even asked.
     */
    public function testASlowVenueDoesNotHoldUpAFastOne(): void
    {
        $this->outcomes = ['coinbase' => self::COINBASE_BOOK, 'kraken' => self::KRAKEN_BOOK];
        $this->delays = ['coinbase' => 0.03, 'kraken' => 0.0];

        $books = $this->fetch();

        self::assertSame([
            'coinbase:dispatched',
            'kraken:dispatched',
            'kraken:settled',
            'coinbase:settled',
        ], $this->trace);
        self::assertSame(['coinbase', 'kraken'], array_keys($books), 'results keep the requested order');
    }

    public function testAScanCostsOneRoundTripNotOnePerVenue(): void
    {
        $this->outcomes = ['coinbase' => self::COINBASE_BOOK, 'kraken' => self::KRAKEN_BOOK];
        $this->delays = ['coinbase' => 0.04, 'kraken' => 0.04];

        $startedAt = microtime(true);
        $this->fetch();
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertGreaterThanOrEqual(40, $elapsedMs, 'both venues really did wait ~40ms');
        self::assertLessThan(70, $elapsedMs, 'sequential reads would have cost ~80ms');
    }

    /**
     * The point of overlapping the reads is comparable snapshots, so the gap between
     * the first and last book landing has to stay small even when venues are slow.
     */
    public function testTheBooksAreObservedCloseTogetherInTime(): void
    {
        $this->outcomes = ['coinbase' => self::COINBASE_BOOK, 'kraken' => self::KRAKEN_BOOK];
        $this->delays = ['coinbase' => 0.04, 'kraken' => 0.045];

        $settledAt = [];
        $this->onSettle = static function (string $venue) use (&$settledAt): void {
            $settledAt[$venue] = microtime(true);
        };

        $this->fetch();

        $skewMs = abs($settledAt['coinbase'] - $settledAt['kraken']) * 1000;

        self::assertLessThan(25, $skewMs, 'the two snapshots must be near-simultaneous, not a round trip apart');
    }

    // --------------------------------------------------------------------- RESILIENCE

    public function testAFailedVenueIsDroppedAndTheRestSurvive(): void
    {
        $this->outcomes = [
            'coinbase' => new \RuntimeException('502 Bad Gateway'),
            'kraken' => self::KRAKEN_BOOK,
        ];

        $books = $this->fetch();

        self::assertSame(['kraken' => self::KRAKEN_BOOK], $books);
    }

    public function testAFailedVenueDoesNotAbandonASlowerOneStillInFlight(): void
    {
        $this->outcomes = [
            'coinbase' => new \RuntimeException('502 Bad Gateway'),
            'kraken' => self::KRAKEN_BOOK,
        ];
        $this->delays = ['coinbase' => 0.0, 'kraken' => 0.03];

        $books = $this->fetch();

        self::assertSame(['kraken' => self::KRAKEN_BOOK], $books, 'the slow read was still awaited');
    }

    public function testEveryVenueFailingYieldsNoBooksRatherThanAnException(): void
    {
        $this->outcomes = [
            'coinbase' => new \RuntimeException('502 Bad Gateway'),
            'kraken' => new \RuntimeException('timed out'),
        ];

        self::assertSame([], $this->fetch());
    }

    public function testADroppedVenueIsLoggedWithItsReason(): void
    {
        $this->outcomes = ['coinbase' => new \RuntimeException('502 Bad Gateway')];

        $this->fetch(symbol: 'BTC/USDT');

        self::assertSame(
            ['Order book unavailable from coinbase for BTC/USDT: 502 Bad Gateway'],
            $this->logMessages(LogLevel::DEBUG)
        );
    }

    public function testASuccessfulRoundIsNotLogged(): void
    {
        $this->outcomes = ['coinbase' => self::COINBASE_BOOK, 'kraken' => self::KRAKEN_BOOK];

        $this->fetch();

        self::assertSame([], $this->loggedMessages, 'a scan runs several times a second; silence is the norm');
    }

    // ------------------------------------------------------------------------ HELPERS

    /** @var null|\Closure(string): void invoked as each read settles */
    private ?\Closure $onSettle = null;

    /**
     * @return array<string, array>
     */
    private function fetch(string $symbol = self::SYMBOL, ?int $limit = null): array
    {
        $venues = [];

        foreach (array_keys($this->outcomes) as $name) {
            $venues[$name] = $this->venue($name);
        }

        return new OrderBookFetcher($this->recordingLogger())->fetchConcurrently($venues, $symbol, $limit);
    }

    private function venue(string $name): ExchangeServiceInterface
    {
        $venue = $this->createStub(ExchangeServiceInterface::class);

        $venue->method('getOrderBookAsync')->willReturnCallback(
            function (string $symbol, ?int $limit) use ($name): PromiseInterface {
                $this->reads[] = ['venue' => $name, 'symbol' => $symbol, 'limit' => $limit];
                $this->trace[] = "{$name}:dispatched";

                // Settling on a loop timer is what lets the reads overlap, exactly as
                // an in-flight HTTP response would.
                $deferred = new Deferred();

                Loop::addTimer(
                    $this->delays[$name] ?? 0.0,
                    function () use ($deferred, $name): void {
                        $this->trace[] = "{$name}:settled";
                        ($this->onSettle ?? static fn() => null)($name);

                        $outcome = $this->outcomes[$name];

                        $outcome instanceof \Throwable
                            ? $deferred->reject($outcome)
                            : $deferred->resolve($outcome);
                    }
                );

                return $deferred->promise();
            }
        );

        return $venue;
    }

    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        $logger->method('debug')->willReturnCallback(
            function (string|\Stringable $message): void {
                $this->loggedMessages[LogLevel::DEBUG][] = (string) $message;
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
