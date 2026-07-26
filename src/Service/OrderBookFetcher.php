<?php

namespace App\Service;

use App\Service\ExchangeService\ExchangeServiceInterface;
use Psr\Log\LoggerInterface;

use function React\Async\await;
use function React\Promise\all;

/**
 * Reads the same symbol from several venues at once.
 *
 * A cross-exchange spread is only meaningful if both sides are observed at roughly the
 * same instant: fetching venues one after another means the second book is already
 * staler than the first by a full round trip, and the spread you act on is partly an
 * artefact of that skew. Holding every read open concurrently keeps the snapshots close
 * together and makes a scan cost one round trip rather than one per venue.
 */
final readonly class OrderBookFetcher
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * A venue that fails to answer is omitted rather than fatal — the round simply has
     * nothing to compare it against.
     *
     * @param array<string, ExchangeServiceInterface> $venues keyed by venue name
     * @return array<string, array> books by venue name, in the order given; failed venues are absent
     */
    public function fetchConcurrently(array $venues, string $symbol, ?int $limit = null): array
    {
        if ($venues === []) {
            return [];
        }

        $reads = [];

        foreach ($venues as $name => $venue) {
            // Every read is dispatched before anything is awaited, so they overlap.
            $reads[$name] = $venue->getOrderBookAsync($symbol, $limit)->then(
                static fn (array $book): array => $book,
                function (\Throwable $e) use ($name, $symbol): null {
                    // Debug, not error: the scanner runs several times a second and a
                    // transient miss is unremarkable. A venue that is genuinely down
                    // surfaces through the circuit breaker, not through this log.
                    $this->logger->debug(
                        "Order book unavailable from {$name} for {$symbol}: " . $e->getMessage()
                    );

                    return null;
                },
            );
        }

        $books = await(all($reads));

        return array_filter($books, static fn (?array $book): bool => $book !== null);
    }
}
