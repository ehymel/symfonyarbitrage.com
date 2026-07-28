<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;
use Psr\Log\LoggerInterface;

class TradingCircuitBreaker
{
    private const string STATE_CLOSED = 'CLOSED';
    private const string STATE_OPEN = 'OPEN';
    private const string STATE_HALF_OPEN = 'HALF_OPEN';

    /**
     * A PSR-6 item pool rather than the contracts' CacheInterface, because the latter has
     * no way to read without writing: get() persists whatever its callback returns, so
     * every pre-trade check on an untripped venue was creating a cache entry. The scanner
     * only ever reads this state, and it checks two venues per pair several times a
     * second — enough that a pool it lacked write permission on buried the log, and
     * enough contention to be worth avoiding even where the writes succeed.
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly TexterInterface $texter,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'ADMIN_PHONE_NUMBER')]
        private readonly string          $adminPhoneNumber,
        private readonly int             $maxFailures = 2,
        private readonly int             $maxLatencyMs = 450,    // may need to be adjusted after making buy/sell orders truly concurrent in ExecuteArbitrageHandler
        private readonly int             $cooldownSeconds = 300, // 5-minute pause
    ) {}

    /**
     * Pre-trade check: Call this BEFORE placing any order.
     *
     * The common case — a venue nobody has tripped — reads one key and writes nothing.
     * An absent state entry *is* CLOSED, so there is no default worth persisting.
     *
     * @throws InvalidArgumentException
     */
    public function isAllowed(string $exchange): bool
    {
        $state = $this->read(sprintf('cb_%s_state', $exchange)) ?? self::STATE_CLOSED;

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            $openedAtKey = sprintf('cb_%s_opened_at', $exchange);
            $openedAt = $this->read($openedAtKey);

            if ($openedAt === null) {
                // An open circuit whose marker has gone missing cannot be dated, and an
                // undateable block is no evidence the venue recovered — so the clock
                // restarts here rather than being assumed to have run out. Writing it
                // down is what makes that terminate: left in memory, every subsequent
                // call would restart the cooldown too and the venue would stay dark.
                $openedAt = time();
                $this->write($openedAtKey, $openedAt);
            }

            // Check if cooldown period has elapsed
            if ((time() - $openedAt) > $this->cooldownSeconds) {
                $this->transitionTo($exchange, self::STATE_HALF_OPEN);
                return true; // Allow probe trade
            }

            return false; // Circuit is still OPEN; trip trading!
        }

        // In HALF_OPEN state, allow execution for probe trade
        return true;
    }

    /**
     * Record API execution metrics.
     * @throws InvalidArgumentException
     * @throws TransportExceptionInterface
     */
    public function recordSuccess(string $exchange, int $executionTimeMs): void
    {
        if ($executionTimeMs > $this->maxLatencyMs) {
            $this->recordFailure($exchange, sprintf("Latency spike detected: %dms", $executionTimeMs));
            return;
        }

        $currentState = $this->read(sprintf('cb_%s_state', $exchange)) ?? self::STATE_CLOSED;

        if ($currentState === self::STATE_HALF_OPEN) {
            // Probe trade succeeded! Reset back to normal
            $this->reset($exchange);
            $this->notify(sprintf("🟢 Circuit Breaker CLOSED for %s. Trading resumed.", $exchange));
        } else {
            // Reset failure counter on clean trade
            $this->cache->deleteItem(sprintf('cb_%s_failures', $exchange));
        }
    }

    /**
     * Record execution errors or severe API failures.
     * @throws InvalidArgumentException|TransportExceptionInterface
     */
    public function recordFailure(string $exchange, string $reason): void
    {
        $failKey = sprintf('cb_%s_failures', $exchange);
        $failures = ($this->read($failKey) ?? 0) + 1;
        $this->write($failKey, $failures);

        $this->logger->warning(sprintf("Circuit breaker warning for %s: %s (Count: %d)", $exchange, $reason, $failures));

        if ($failures >= $this->maxFailures) {
            $this->trip($exchange, $reason);
        }
    }

    /**
     * Opens the circuit at once, without waiting for the failure threshold.
     *
     * For events severe enough that one is already too many — a venue refusing an
     * emergency unwind, say. Letting it take another speculative trade while a counter
     * catches up is not a risk worth running.
     *
     * @throws InvalidArgumentException|TransportExceptionInterface
     */
    public function tripImmediately(string $exchange, string $reason): void
    {
        // The counter is forced to the threshold as well as the circuit being opened.
        // Without this, a failed probe after the cooldown would find a count of 1, fall
        // short of maxFailures, and leave the venue HALF_OPEN — quietly readmitting
        // trades to somewhere that has already proven it cannot be trusted.
        $this->write(sprintf('cb_%s_failures', $exchange), $this->maxFailures);

        $this->trip($exchange, $reason);
    }

    /**
     * @throws InvalidArgumentException|TransportExceptionInterface
     */
    private function trip(string $exchange, string $reason): void
    {
        $this->transitionTo($exchange, self::STATE_OPEN);
        $this->write(sprintf('cb_%s_opened_at', $exchange), time());

        $msg = sprintf("🚨 CIRCUIT BREAKER TRIPPED on %s! Reason: %s. Trading paused for %ds.", $exchange, $reason, $this->cooldownSeconds);
        $this->logger->critical($msg);
        $this->notify($msg);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function reset(string $exchange): void
    {
        $this->transitionTo($exchange, self::STATE_CLOSED);
        $this->cache->deleteItems([
            sprintf('cb_%s_failures', $exchange),
            sprintf('cb_%s_opened_at', $exchange),
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function transitionTo(string $exchange, string $state): void
    {
        $this->write(sprintf('cb_%s_state', $exchange), $state);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function read(string $key): mixed
    {
        $item = $this->cache->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * A rejected save is reported rather than ignored. PSR-6 pools answer with false
     * instead of throwing, so a backend the process cannot write to — the wrong owner on
     * a filesystem pool, an unreachable Redis — would otherwise let trip() page the admin
     * and log its critical while the venue quietly stayed tradeable for whoever checked
     * next. Deliberately does not throw: the handler's guardBreaker() already treats a
     * degraded breaker as survivable, and the ledger row matters more than this write.
     *
     * @throws InvalidArgumentException
     */
    private function write(string $key, mixed $value): void
    {
        if (!$this->cache->save($this->cache->getItem($key)->set($value))) {
            $this->logger->critical(sprintf(
                'Circuit breaker state "%s" could not be persisted; the breaker is degraded and its decisions will not be seen by other processes.',
                $key
            ));
        }
    }

    /**
     * @throws TransportExceptionInterface
     */
    private function notify(string $message): void
    {
        $sms = new SmsMessage(phone: $this->adminPhoneNumber, subject: $message);

        $this->texter->send($sms);
    }
}
