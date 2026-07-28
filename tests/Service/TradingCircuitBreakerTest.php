<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TradingCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

/**
 * The breaker keeps all of its state in the cache, so these tests drive a real
 * ArrayAdapter rather than mocking get()/delete() call sequences: the assertions
 * are about the state machine, not about how many times the cache was poked.
 */
#[CoversClass(TradingCircuitBreaker::class)]
final class TradingCircuitBreakerTest extends TestCase
{
    private const string EXCHANGE = 'binance';
    private const string ADMIN_PHONE = '+15555550123';
    private const int MAX_FAILURES = 2;
    private const int MAX_LATENCY_MS = 450;
    private const int COOLDOWN_SECONDS = 300;

    private ArrayAdapter $cache;

    /** @var list<SmsMessage> */
    private array $sentMessages = [];

    /** @var array<string, list<string>> messages captured per PSR-3 level */
    private array $loggedMessages = [];

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->sentMessages = [];
        $this->loggedMessages = [];
        $this->logger = $this->recordingLogger();
    }

    // ---------------------------------------------------------------- CLOSED

    public function testFreshCircuitAllowsTrading(): void
    {
        self::assertTrue($this->breaker()->isAllowed(self::EXCHANGE));
    }

    /**
     * The scanner checks two venues per pair several times a second and never records an
     * outcome, so a pre-trade check that persisted its own default turned a pure reader
     * into a writer: on a filesystem pool owned by the worker's user, every one of those
     * checks failed to save and logged a warning. Nothing about an untripped venue is
     * worth writing down — an absent entry already means CLOSED.
     */
    public function testCheckingAnUntrippedCircuitWritesNothing(): void
    {
        $breaker = $this->breaker();

        $breaker->isAllowed(self::EXCHANGE);
        $breaker->isAllowed(self::EXCHANGE);

        self::assertSame([], $this->storedKeys());
    }

    public function testCheckingATrippedCircuitDoesNotRewriteItsState(): void
    {
        $breaker = $this->trippedBreaker();
        $before = $this->cache->getValues();

        self::assertFalse($breaker->isAllowed(self::EXCHANGE));

        self::assertSame($before, $this->cache->getValues());
    }

    public function testFailureBelowThresholdLeavesCircuitClosed(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::EXCHANGE, 'order rejected');

        self::assertTrue($breaker->isAllowed(self::EXCHANGE));
        self::assertSame('CLOSED', $this->state(self::EXCHANGE));
        self::assertSame([], $this->sentMessages, 'no alert until the breaker actually trips');
    }

    public function testEachFailureIsLoggedAsAWarningWithItsRunningCount(): void
    {
        $breaker = $this->breaker(maxFailures: 5);

        $breaker->recordFailure(self::EXCHANGE, 'timeout');
        $breaker->recordFailure(self::EXCHANGE, 'timeout');

        self::assertSame([
            'Circuit breaker warning for binance: timeout (Count: 1)',
            'Circuit breaker warning for binance: timeout (Count: 2)',
        ], $this->logMessages(LogLevel::WARNING));
    }

    public function testCleanTradeResetsTheFailureCounter(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::EXCHANGE, 'transient error');
        $breaker->recordSuccess(self::EXCHANGE, 100);
        $breaker->recordFailure(self::EXCHANGE, 'transient error');

        self::assertTrue(
            $breaker->isAllowed(self::EXCHANGE),
            'the clean trade should have cleared the first failure, so this is only failure #1'
        );
        self::assertSame('Circuit breaker warning for binance: transient error (Count: 1)', $this->logMessages(LogLevel::WARNING)[1]);
    }

    public function testCleanTradeInClosedStateSendsNoNotification(): void
    {
        $this->breaker()->recordSuccess(self::EXCHANGE, 10);

        self::assertSame([], $this->sentMessages);
    }

    // ------------------------------------------------------------- TRIPPING

    public function testCircuitTripsWhenFailuresReachTheThreshold(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::EXCHANGE, 'order rejected');
        $breaker->recordFailure(self::EXCHANGE, 'order rejected');

        self::assertFalse($breaker->isAllowed(self::EXCHANGE));
        self::assertSame('OPEN', $this->state(self::EXCHANGE));
    }

    public function testTripHonoursACustomFailureThreshold(): void
    {
        $breaker = $this->breaker(maxFailures: 3);

        $breaker->recordFailure(self::EXCHANGE, 'boom');
        $breaker->recordFailure(self::EXCHANGE, 'boom');
        self::assertTrue($breaker->isAllowed(self::EXCHANGE));

        $breaker->recordFailure(self::EXCHANGE, 'boom');
        self::assertFalse($breaker->isAllowed(self::EXCHANGE));
    }

    public function testTripLogsCriticalAndTextsTheAdminWithReasonAndCooldown(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::EXCHANGE, 'order rejected');
        $breaker->recordFailure(self::EXCHANGE, 'order rejected');

        $expected = '🚨 CIRCUIT BREAKER TRIPPED on binance! Reason: order rejected. Trading paused for 300s.';

        self::assertSame([$expected], $this->logMessages(LogLevel::CRITICAL));
        self::assertCount(1, $this->sentMessages);
        self::assertSame($expected, $this->sentMessages[0]->getSubject());
        self::assertSame(self::ADMIN_PHONE, $this->sentMessages[0]->getPhone());
    }

    public function testTripRecordsWhenTheCircuitOpened(): void
    {
        $breaker = $this->breaker();
        $before = time();

        $breaker->recordFailure(self::EXCHANGE, 'boom');
        $breaker->recordFailure(self::EXCHANGE, 'boom');

        $openedAt = $this->cached(sprintf('cb_%s_opened_at', self::EXCHANGE));
        self::assertIsInt($openedAt);
        self::assertGreaterThanOrEqual($before, $openedAt);
        self::assertLessThanOrEqual(time(), $openedAt);
    }

    // -------------------------------------------------------------- LATENCY

    public function testLatencySpikeIsRecordedAsAFailure(): void
    {
        $breaker = $this->breaker(maxFailures: 5);

        $breaker->recordSuccess(self::EXCHANGE, self::MAX_LATENCY_MS + 1);

        self::assertSame(
            ['Circuit breaker warning for binance: Latency spike detected: 451ms (Count: 1)'],
            $this->logMessages(LogLevel::WARNING)
        );
    }

    public function testExecutionExactlyAtTheLatencyLimitCountsAsASuccess(): void
    {
        $breaker = $this->breaker();

        $breaker->recordSuccess(self::EXCHANGE, self::MAX_LATENCY_MS);

        self::assertSame([], $this->logMessages(LogLevel::WARNING), 'the limit is exclusive: > maxLatencyMs, not >=');
        self::assertTrue($breaker->isAllowed(self::EXCHANGE));
    }

    public function testRepeatedLatencySpikesTripTheCircuit(): void
    {
        $breaker = $this->breaker();

        $breaker->recordSuccess(self::EXCHANGE, 900);
        $breaker->recordSuccess(self::EXCHANGE, 900);

        self::assertFalse($breaker->isAllowed(self::EXCHANGE));
        self::assertStringContainsString('Latency spike detected: 900ms', $this->logMessages(LogLevel::CRITICAL)[0]);
    }

    public function testLatencySpikeDoesNotResetTheFailureCounter(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(self::EXCHANGE, 'order rejected');
        $breaker->recordSuccess(self::EXCHANGE, 900);

        self::assertFalse($breaker->isAllowed(self::EXCHANGE), 'a slow trade must not count as a clean trade');
    }

    public function testHonoursACustomLatencyBudget(): void
    {
        $breaker = $this->breaker(maxLatencyMs: 50);

        $breaker->recordSuccess(self::EXCHANGE, 51);

        self::assertSame(
            ['Circuit breaker warning for binance: Latency spike detected: 51ms (Count: 1)'],
            $this->logMessages(LogLevel::WARNING)
        );
    }

    // -------------------------------------------------------------- COOLDOWN

    public function testOpenCircuitBlocksTradingForTheWholeCooldown(): void
    {
        $breaker = $this->trippedBreaker();

        $this->backdateOpenedAt(self::EXCHANGE, self::COOLDOWN_SECONDS - 5);

        self::assertFalse($breaker->isAllowed(self::EXCHANGE));
        self::assertSame('OPEN', $this->state(self::EXCHANGE));
    }

    public function testCircuitHalfOpensOnceTheCooldownHasElapsed(): void
    {
        $breaker = $this->trippedBreaker();

        $this->backdateOpenedAt(self::EXCHANGE, self::COOLDOWN_SECONDS + 1);

        self::assertTrue($breaker->isAllowed(self::EXCHANGE), 'a probe trade must be allowed');
        self::assertSame('HALF_OPEN', $this->state(self::EXCHANGE));
    }

    public function testHalfOpenCircuitKeepsAllowingCallsUntilAProbeReports(): void
    {
        $breaker = $this->halfOpenBreaker();

        self::assertTrue($breaker->isAllowed(self::EXCHANGE));
        self::assertSame('HALF_OPEN', $this->state(self::EXCHANGE));
    }

    /**
     * An open circuit that has lost its opened_at marker cannot be dated. Treating that
     * as "the cooldown must have elapsed" would readmit trades to a venue on the strength
     * of a missing cache entry, so the clock restarts instead — and it is written down,
     * because a restart held only in memory would repeat on every check and leave the
     * venue permanently dark.
     */
    public function testAnOpenCircuitWithNoOpenedAtMarkerServesAFreshCooldown(): void
    {
        $breaker = $this->breaker();
        $this->cache->save($this->cache->getItem(sprintf('cb_%s_state', self::EXCHANGE))->set('OPEN'));

        self::assertFalse($breaker->isAllowed(self::EXCHANGE), 'an undateable block is not a recovered venue');

        $openedAt = $this->cached(sprintf('cb_%s_opened_at', self::EXCHANGE));
        self::assertIsInt($openedAt, 'the restarted clock must be persisted, or it restarts again on the next check');

        $this->backdateOpenedAt(self::EXCHANGE, self::COOLDOWN_SECONDS + 1);
        self::assertTrue($breaker->isAllowed(self::EXCHANGE), 'and it must then expire like any other cooldown');
    }

    // --------------------------------------------------------------- RECOVERY

    public function testSuccessfulProbeClosesTheCircuitAndAnnouncesRecovery(): void
    {
        $breaker = $this->halfOpenBreaker();
        $this->sentMessages = [];

        $breaker->recordSuccess(self::EXCHANGE, 100);

        self::assertSame('CLOSED', $this->state(self::EXCHANGE));
        self::assertTrue($breaker->isAllowed(self::EXCHANGE));
        self::assertCount(1, $this->sentMessages);
        self::assertSame(
            '🟢 Circuit Breaker CLOSED for binance. Trading resumed.',
            $this->sentMessages[0]->getSubject()
        );
        self::assertSame(self::ADMIN_PHONE, $this->sentMessages[0]->getPhone());
    }

    public function testRecoveryClearsTheFailureCounterAndOpenedAtMarker(): void
    {
        $breaker = $this->halfOpenBreaker();

        $breaker->recordSuccess(self::EXCHANGE, 100);

        self::assertNull($this->cached(sprintf('cb_%s_failures', self::EXCHANGE)));
        self::assertNull($this->cached(sprintf('cb_%s_opened_at', self::EXCHANGE)));
    }

    public function testAfterRecoveryTheBreakerNeedsAFullRunOfFailuresToTripAgain(): void
    {
        $breaker = $this->halfOpenBreaker();
        $breaker->recordSuccess(self::EXCHANGE, 100);

        $breaker->recordFailure(self::EXCHANGE, 'boom');

        self::assertTrue($breaker->isAllowed(self::EXCHANGE), 'pre-trip failures must not carry over');
    }

    public function testFailedProbeReopensTheCircuitAndRestartsTheCooldown(): void
    {
        $breaker = $this->halfOpenBreaker();
        $this->sentMessages = [];

        $breaker->recordFailure(self::EXCHANGE, 'still broken');

        self::assertSame('OPEN', $this->state(self::EXCHANGE));
        self::assertFalse(
            $breaker->isAllowed(self::EXCHANGE),
            'the cooldown clock must restart, otherwise the stale opened_at would half-open immediately'
        );
        self::assertCount(1, $this->sentMessages);
        self::assertStringContainsString('Reason: still broken', $this->sentMessages[0]->getSubject());
    }

    public function testSlowProbeReopensTheCircuit(): void
    {
        $breaker = $this->halfOpenBreaker();

        $breaker->recordSuccess(self::EXCHANGE, 5_000);

        self::assertSame('OPEN', $this->state(self::EXCHANGE));
        self::assertFalse($breaker->isAllowed(self::EXCHANGE));
    }

    // ------------------------------------------------------- IMMEDIATE TRIPS

    /**
     * The escalation path for events where the threshold is the wrong instrument: one
     * refused emergency order is already one too many, and waiting for a second would
     * mean admitting another trade to a venue that has proven it cannot be trusted.
     */
    public function testAnImmediateTripOpensTheCircuitOnTheFirstCall(): void
    {
        $breaker = $this->breaker();

        $breaker->tripImmediately(self::EXCHANGE, 'Unwind failed: venue is down');

        self::assertSame('OPEN', $this->state(self::EXCHANGE));
        self::assertFalse($breaker->isAllowed(self::EXCHANGE));
    }

    public function testAnImmediateTripIgnoresTheFailureThreshold(): void
    {
        $breaker = $this->breaker(maxFailures: 99);

        $breaker->tripImmediately(self::EXCHANGE, 'Unwind failed: venue is down');

        self::assertFalse($breaker->isAllowed(self::EXCHANGE), 'the threshold must not apply');
    }

    public function testAnImmediateTripAnnouncesItselfLikeAnyOtherTrip(): void
    {
        $breaker = $this->breaker();

        $breaker->tripImmediately(self::EXCHANGE, 'Unwind failed: venue is down');

        self::assertCount(1, $this->sentMessages);
        self::assertSame(
            '🚨 CIRCUIT BREAKER TRIPPED on binance! Reason: Unwind failed: venue is down. Trading paused for 300s.',
            $this->sentMessages[0]->getSubject()
        );
        self::assertSame([$this->sentMessages[0]->getSubject()], $this->logMessages(LogLevel::CRITICAL));
    }

    public function testAnImmediateTripServesTheNormalCooldown(): void
    {
        $breaker = $this->breaker();
        $breaker->tripImmediately(self::EXCHANGE, 'Unwind failed: venue is down');

        $this->backdateOpenedAt(self::EXCHANGE, self::COOLDOWN_SECONDS + 1);

        self::assertTrue($breaker->isAllowed(self::EXCHANGE), 'a probe is allowed once the cooldown elapses');
        self::assertSame('HALF_OPEN', $this->state(self::EXCHANGE));
    }

    /**
     * The reason the counter is forced rather than left alone. A trip that skipped it
     * would leave a count of 0, so the first failed probe after the cooldown would reach
     * only 1, fall short of maxFailures, and strand the venue HALF_OPEN — silently
     * readmitting trades to somewhere that has already failed twice over.
     */
    public function testAFailedProbeAfterAnImmediateTripReopensTheCircuit(): void
    {
        $breaker = $this->breaker();
        $breaker->tripImmediately(self::EXCHANGE, 'Unwind failed: venue is down');
        $this->backdateOpenedAt(self::EXCHANGE, self::COOLDOWN_SECONDS + 1);
        $breaker->isAllowed(self::EXCHANGE);

        self::assertSame('HALF_OPEN', $this->state(self::EXCHANGE), 'fixture precondition');

        $breaker->recordFailure(self::EXCHANGE, 'still broken');

        self::assertSame('OPEN', $this->state(self::EXCHANGE));
        self::assertFalse($breaker->isAllowed(self::EXCHANGE));
    }

    public function testASuccessfulProbeAfterAnImmediateTripStillRecovers(): void
    {
        $breaker = $this->breaker();
        $breaker->tripImmediately(self::EXCHANGE, 'Unwind failed: venue is down');
        $this->backdateOpenedAt(self::EXCHANGE, self::COOLDOWN_SECONDS + 1);
        $breaker->isAllowed(self::EXCHANGE);

        $breaker->recordSuccess(self::EXCHANGE, 100);

        self::assertSame('CLOSED', $this->state(self::EXCHANGE));
        self::assertNull($this->cached(sprintf('cb_%s_failures', self::EXCHANGE)), 'the forced count is cleared too');
    }

    public function testAnImmediateTripOnlyAffectsItsOwnVenue(): void
    {
        $breaker = $this->breaker();

        $breaker->tripImmediately('binance', 'Unwind failed: venue is down');

        self::assertFalse($breaker->isAllowed('binance'));
        self::assertTrue($breaker->isAllowed('kraken'));
        self::assertNull($this->cached('cb_kraken_failures'));
    }

    // -------------------------------------------------------------- ISOLATION

    public function testCircuitsAreTrackedPerExchange(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure('binance', 'boom');
        $breaker->recordFailure('binance', 'boom');

        self::assertFalse($breaker->isAllowed('binance'));
        self::assertTrue($breaker->isAllowed('kraken'));
        self::assertNull($this->cached('cb_kraken_failures'));
    }

    public function testFailuresOnDifferentExchangesDoNotAccumulate(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure('binance', 'boom');
        $breaker->recordFailure('kraken', 'boom');

        self::assertTrue($breaker->isAllowed('binance'));
        self::assertTrue($breaker->isAllowed('kraken'));
        self::assertSame([], $this->logMessages(LogLevel::CRITICAL));
    }

    // ------------------------------------------------------- DEGRADED STORAGE

    /**
     * PSR-6 pools answer a rejected save with false rather than an exception, so a
     * backend the process cannot write to fails silently by default. That is the shape of
     * the worst version of this bug: the admin is paged, the critical is logged, and the
     * venue stays tradeable for the next process to check it. The breaker cannot fix an
     * unwritable pool, but it must not let one pass for a working one.
     */
    public function testATripThatCannotBePersistedIsReportedAsCritical(): void
    {
        $breaker = $this->breaker(cache: $this->unwritablePool());

        $breaker->tripImmediately(self::EXCHANGE, 'Unwind failed: venue is down');

        $degraded = array_filter(
            $this->logMessages(LogLevel::CRITICAL),
            static fn(string $message): bool => str_contains($message, 'cb_binance_state" could not be persisted')
        );

        self::assertNotEmpty($degraded, 'a breaker whose state did not persist must say so');
    }

    public function testAWorkingPoolReportsNoDegradation(): void
    {
        $this->breaker()->tripImmediately(self::EXCHANGE, 'Unwind failed: venue is down');

        foreach ($this->logMessages(LogLevel::CRITICAL) as $message) {
            self::assertStringNotContainsString('could not be persisted', $message);
        }
    }

    // ----------------------------------------------------------------- HELPERS

    private function breaker(
        int $maxFailures = self::MAX_FAILURES,
        int $maxLatencyMs = self::MAX_LATENCY_MS,
        int $cooldownSeconds = self::COOLDOWN_SECONDS,
        ?CacheItemPoolInterface $cache = null,
    ): TradingCircuitBreaker {
        return new TradingCircuitBreaker(
            $cache ?? $this->cache,
            $this->recordingTexter(),
            $this->logger,
            self::ADMIN_PHONE,
            $maxFailures,
            $maxLatencyMs,
            $cooldownSeconds,
        );
    }

    /**
     * A breaker whose circuit is already OPEN, with the trip artefacts left in the cache.
     */
    private function trippedBreaker(): TradingCircuitBreaker
    {
        $breaker = $this->breaker();

        for ($i = 0; $i < self::MAX_FAILURES; ++$i) {
            $breaker->recordFailure(self::EXCHANGE, 'boom');
        }

        self::assertSame('OPEN', $this->state(self::EXCHANGE), 'fixture precondition');

        return $breaker;
    }

    /**
     * A breaker that has tripped, sat out its cooldown, and been let through for a probe trade.
     */
    private function halfOpenBreaker(): TradingCircuitBreaker
    {
        $breaker = $this->trippedBreaker();
        $this->backdateOpenedAt(self::EXCHANGE, self::COOLDOWN_SECONDS + 1);
        $breaker->isAllowed(self::EXCHANGE);

        self::assertSame('HALF_OPEN', $this->state(self::EXCHANGE), 'fixture precondition');

        return $breaker;
    }

    /**
     * A pool that reads normally and rejects every write, the way a filesystem pool
     * behaves for a process that lacks permission on the pool directory.
     */
    private function unwritablePool(): CacheItemPoolInterface
    {
        return new class ($this->cache) implements CacheItemPoolInterface {
            public function __construct(private readonly CacheItemPoolInterface $inner) {}

            public function save(CacheItemInterface $item): bool { return false; }
            public function saveDeferred(CacheItemInterface $item): bool { return false; }
            public function commit(): bool { return false; }

            public function getItem(string $key): CacheItemInterface { return $this->inner->getItem($key); }
            public function getItems(array $keys = []): iterable { return $this->inner->getItems($keys); }
            public function hasItem(string $key): bool { return $this->inner->hasItem($key); }
            public function clear(): bool { return $this->inner->clear(); }
            public function deleteItem(string $key): bool { return $this->inner->deleteItem($key); }
            public function deleteItems(array $keys): bool { return $this->inner->deleteItems($keys); }
        };
    }

    private function recordingTexter(): TexterInterface
    {
        $texter = $this->createStub(TexterInterface::class);
        $texter->method('supports')->willReturn(true);
        $texter->method('send')->willReturnCallback(
            function (MessageInterface $message): ?SentMessage {
                $this->sentMessages[] = $message;

                return null;
            }
        );

        return $texter;
    }

    /**
     * Rewrites the "circuit opened at" marker so cooldown behaviour can be exercised
     * without sleeping or stubbing time().
     */
    private function backdateOpenedAt(string $exchange, int $secondsAgo): void
    {
        $key = sprintf('cb_%s_opened_at', $exchange);

        $this->cache->delete($key);
        $this->cache->get($key, fn() => time() - $secondsAgo);
    }

    /**
     * An absent state entry is CLOSED — that is what lets a pre-trade check on an
     * untripped venue avoid writing anything at all.
     */
    private function state(string $exchange): string
    {
        return $this->cached(sprintf('cb_%s_state', $exchange)) ?? 'CLOSED';
    }

    /**
     * The keys the pool actually holds. ArrayAdapter tracks misses by parking a null in
     * its value map, so merely reading a key leaves a footprint there; the expiry set
     * behind hasItem() is what separates a stored entry from a probed one.
     *
     * @return list<string>
     */
    private function storedKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->cache->getValues()),
            fn(string $key): bool => $this->cache->hasItem($key)
        ));
    }

    /**
     * Reads a key without the write-through side effect of CacheInterface::get().
     */
    private function cached(string $key): mixed
    {
        $item = $this->cache->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Captures the two PSR-3 levels the breaker actually uses. Stubbing warning()
     * and critical() directly also pins *which* level each event is reported at —
     * a routed-through log() double would let a severity downgrade slip past.
     */
    private function recordingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);

        $logger->method('warning')->willReturnCallback(
            function (string|\Stringable $message): void {
                $this->loggedMessages[LogLevel::WARNING][] = (string) $message;
            }
        );
        $logger->method('critical')->willReturnCallback(
            function (string|\Stringable $message): void {
                $this->loggedMessages[LogLevel::CRITICAL][] = (string) $message;
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
