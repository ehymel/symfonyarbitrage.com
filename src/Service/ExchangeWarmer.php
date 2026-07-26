<?php

namespace App\Service;

use App\Service\ExchangeService\ExchangeServiceInterface;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;

use function React\Async\await;
use function React\Promise\all;

/**
 * Pulls venue market metadata into memory at boot.
 *
 * ccxt loads markets lazily, inside the first call that needs them — which for a
 * trading worker is the first live order. That puts a full markets fetch on the
 * critical path of a trade. Doing it once at startup moves the cost off the trade.
 *
 * Warming is best effort by design: a venue that cannot be reached at boot must not
 * stop the worker from starting, because the circuit breaker — not this class — owns
 * the decision about whether that venue is fit to trade.
 */
final readonly class ExchangeWarmer
{
    public function __construct(
        private ExchangeFactory $exchangeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Warms every venue wired into the application.
     */
    public function warmAll(): void
    {
        $this->warm(...$this->exchangeFactory->names());
    }

    /**
     * Warms the named venues concurrently. Never throws.
     */
    public function warm(string ...$venues): void
    {
        $warmups = [];

        foreach ($venues as $venue) {
            try {
                $warmups[$venue] = $this->recordOutcome($venue, $this->createVenue($venue)->warmUp());
            } catch (\Throwable $e) {
                $this->logger->error("Market pre-load skipped for {$venue}: " . $e->getMessage());
            }
        }

        if ($warmups === []) {
            return;
        }

        // All venues load together; boot costs one round trip, not one per venue.
        await(all($warmups));
    }

    private function createVenue(string $venue): ExchangeServiceInterface
    {
        return $this->exchangeFactory->create($venue);
    }

    /**
     * Collapses the warm-up to a promise that always fulfils, so one unreachable
     * venue cannot abandon the others mid-load.
     *
     * @param PromiseInterface<mixed> $warmUp
     * @return PromiseInterface<null>
     */
    private function recordOutcome(string $venue, PromiseInterface $warmUp): PromiseInterface
    {
        return $warmUp->then(
            function () use ($venue): void {
                $this->logger->info("Markets pre-loaded for {$venue}.");
            },
            function (\Throwable $e) use ($venue): void {
                // ccxt will retry the load lazily on first use; this is a slow start, not an outage.
                $this->logger->error("Market pre-load failed for {$venue}: " . $e->getMessage());
            },
        );
    }
}
