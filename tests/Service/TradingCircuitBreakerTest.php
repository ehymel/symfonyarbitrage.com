<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TradingCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

    // ----------------------------------------------------------------- HELPERS

    private function breaker(
        int $maxFailures = self::MAX_FAILURES,
        int $maxLatencyMs = self::MAX_LATENCY_MS,
        int $cooldownSeconds = self::COOLDOWN_SECONDS,
    ): TradingCircuitBreaker {
        return new TradingCircuitBreaker(
            $this->cache,
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

    private function state(string $exchange): ?string
    {
        return $this->cached(sprintf('cb_%s_state', $exchange));
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
