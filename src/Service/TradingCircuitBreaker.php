<?php

namespace App\Service;

use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Notification\Notification;

class TradingCircuitBreaker
{
    private const STATE_CLOSED = 'CLOSED';
    private const STATE_OPEN = 'OPEN';
    private const STATE_HALF_OPEN = 'HALF_OPEN';

    public function __construct(
        private CacheInterface $cache,
        private NotifierInterface $notifier,
        private LoggerInterface $logger,
        private int $maxFailures = 2,
        private int $maxLatencyMs = 450,
        private int $cooldownSeconds = 300 // 5-minute pause
    ) {}

    /**
     * Pre-trade check: Call this BEFORE placing any order.
     * @throws InvalidArgumentException
     */
    public function isAllowed(string $exchange): bool
    {
        $stateKey = sprintf('cb_%s_state', $exchange);
        $state = $this->cache->get($stateKey, fn() => self::STATE_CLOSED);

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            $openedAtKey = sprintf('cb_%s_opened_at', $exchange);
            $openedAt = $this->cache->get($openedAtKey, fn() => time());

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
     */
    public function recordSuccess(string $exchange, int $executionTimeMs): void
    {
        if ($executionTimeMs > $this->maxLatencyMs) {
            $this->recordFailure($exchange, sprintf("Latency spike detected: %dms", $executionTimeMs));
            return;
        }

        $stateKey = sprintf('cb_%s_state', $exchange);
        $currentState = $this->cache->get($stateKey, fn() => self::STATE_CLOSED);

        if ($currentState === self::STATE_HALF_OPEN) {
            // Probe trade succeeded! Reset back to normal
            $this->reset($exchange);
            $this->notify(sprintf("🟢 Circuit Breaker CLOSED for %s. Trading resumed.", $exchange));
        } else {
            // Reset failure counter on clean trade
            $this->cache->delete(sprintf('cb_%s_failures', $exchange));
        }
    }

    /**
     * Record execution errors or severe API failures.
     * @throws InvalidArgumentException
     */
    public function recordFailure(string $exchange, string $reason): void
    {
        $failKey = sprintf('cb_%s_failures', $exchange);
        $failures = $this->cache->get($failKey, fn() => 0) + 1;

        // Save updated failure count
        $this->cache->delete($failKey);
        $this->cache->get($failKey, fn() => $failures);

        $this->logger->warning(sprintf("Circuit breaker warning for %s: %s (Count: %d)", $exchange, $reason, $failures));

        if ($failures >= $this->maxFailures) {
            $this->trip($exchange, $reason);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function trip(string $exchange, string $reason): void
    {
        $this->transitionTo($exchange, self::STATE_OPEN);

        $openedAtKey = sprintf('cb_%s_opened_at', $exchange);
        $this->cache->delete($openedAtKey);
        $this->cache->get($openedAtKey, fn() => time());

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
        $this->cache->delete(sprintf('cb_%s_failures', $exchange));
        $this->cache->delete(sprintf('cb_%s_opened_at', $exchange));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function transitionTo(string $exchange, string $state): void
    {
        $stateKey = sprintf('cb_%s_state', $exchange);
        $this->cache->delete($stateKey);
        $this->cache->get($stateKey, fn() => $state);
    }

    private function notify(string $message): void
    {
        $notification = (new Notification($message, ['chat/telegram', 'sms']));
        // Sends instantly via configured Symfony Notifier bridges
         $this->notifier->send($notification);
    }
}
