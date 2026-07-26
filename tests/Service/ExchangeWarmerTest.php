<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ExchangeFactory;
use App\Service\ExchangeService\ExchangeServiceInterface;
use App\Service\ExchangeWarmer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function React\Promise\reject;

/**
 * The warmer exists to move a market's fetch off the critical path of a live trade, and
 * its one hard rule is that it must never be the reason a worker fails to boot. Both
 * halves are asserted here: that every venue is warmed in one concurrent pass, and that
 * an unreachable venue degrades to a log line rather than an exception.
 */
#[CoversClass(ExchangeWarmer::class)]
final class ExchangeWarmerTest extends TestCase
{
    /** @var array<string, PromiseInterface<mixed>> venue => what warmUp() returns */
    private array $warmUps = [];

    /** @var list<string> venues whose warmUp() was actually called */
    private array $warmed = [];

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        $this->warmUps = [];
        $this->warmed = [];
        $this->loggedMessages = [];
    }

    public function testEveryWiredVenueIsWarmed(): void
    {
        $this->warmUps = ['coinbase' => $this->resolved(), 'kraken' => $this->resolved()];

        $this->warmer()->warmAll();

        self::assertSame(['coinbase', 'kraken'], $this->warmed);
    }

    public function testOnlyTheNamedVenuesAreWarmed(): void
    {
        $this->warmUps = ['coinbase' => $this->resolved(), 'kraken' => $this->resolved()];

        $this->warmer()->warm('kraken');

        self::assertSame(['kraken'], $this->warmed);
    }

    public function testASuccessfulWarmUpIsLogged(): void
    {
        $this->warmUps = ['kraken' => $this->resolved()];

        $this->warmer()->warm('kraken');

        self::assertSame(['Markets pre-loaded for kraken.'], $this->logMessages(LogLevel::INFO));
    }

    /**
     * The whole point of warming at boot: the call must not return until the metadata
     * is actually in memory, or the first trade races it and pays the fetch anyway.
     */
    public function testWarmingBlocksUntilTheMetadataHasActuallyLoaded(): void
    {
        $deferred = new Deferred();
        $loaded = false;

        Loop::addTimer(0.02, static function () use ($deferred, &$loaded): void {
            $loaded = true;
            $deferred->resolve(null);
        });

        $this->warmUps = ['kraken' => $deferred->promise()];

        $this->warmer()->warm('kraken');

        self::assertTrue($loaded, 'warm() returned while the load was still in flight');
    }

    public function testVenuesAreWarmedConcurrentlyRatherThanOneAfterAnother(): void
    {
        // Coinbase resolves later than kraken despite being requested first; if the
        // warmer awaited each in turn, this would cost the sum, not the max.
        $this->warmUps = [
            'coinbase' => $this->resolveAfter(0.04),
            'kraken' => $this->resolveAfter(0.04),
        ];

        $startedAt = microtime(true);
        $this->warmer()->warmAll();
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertGreaterThanOrEqual(40, $elapsedMs, 'both venues really did wait ~40ms');
        self::assertLessThan(70, $elapsedMs, 'sequential warming would have cost ~80ms');
    }

    // ------------------------------------------------------------------- RESILIENCE

    public function testAnUnreachableVenueDoesNotStopTheWorkerBooting(): void
    {
        $this->warmUps = ['kraken' => reject(new \RuntimeException('connection refused'))];

        $this->warmer()->warm('kraken');

        self::assertSame(
            ['Market pre-load failed for kraken: connection refused'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    public function testOneFailingVenueDoesNotAbandonTheOthers(): void
    {
        $this->warmUps = [
            'coinbase' => reject(new \RuntimeException('connection refused')),
            'kraken' => $this->resolveAfter(0.02),
        ];

        $this->warmer()->warmAll();

        self::assertSame(['coinbase', 'kraken'], $this->warmed);
        self::assertSame(['Markets pre-loaded for kraken.'], $this->logMessages(LogLevel::INFO));
        self::assertCount(1, $this->logMessages(LogLevel::ERROR));
    }

    public function testAnUnknownVenueIsReportedRatherThanThrown(): void
    {
        $this->warmUps = ['kraken' => $this->resolved()];

        $this->warmer()->warm('ftx', 'kraken');

        self::assertSame(['kraken'], $this->warmed, 'the reachable venue is still warmed');
        self::assertSame(
            ['Market pre-load skipped for ftx: Unsupported exchange: ftx'],
            $this->logMessages(LogLevel::ERROR)
        );
    }

    public function testWarmingNothingIsANoOp(): void
    {
        $this->warmer()->warm();

        self::assertSame([], $this->warmed);
        self::assertSame([], $this->loggedMessages);
    }

    // ----------------------------------------------------------------------- HELPERS

    private function warmer(): ExchangeWarmer
    {
        $factories = [];

        foreach (array_keys($this->warmUps) as $name) {
            $factories[$name] = fn(): ExchangeServiceInterface => $this->venue($name);
        }

        return new ExchangeWarmer(
            new ExchangeFactory(new ServiceLocator($factories)),
            $this->recordingLogger(),
        );
    }

    private function venue(string $name): ExchangeServiceInterface
    {
        $venue = $this->createStub(ExchangeServiceInterface::class);

        $venue->method('warmUp')->willReturnCallback(
            function () use ($name): PromiseInterface {
                $this->warmed[] = $name;

                return $this->warmUps[$name];
            }
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

    /**
     * @return PromiseInterface<null>
     */
    private function resolveAfter(float $seconds): PromiseInterface
    {
        $deferred = new Deferred();

        Loop::addTimer($seconds, static fn() => $deferred->resolve(null));

        return $deferred->promise();
    }

    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        foreach ([LogLevel::INFO, LogLevel::ERROR] as $level) {
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
