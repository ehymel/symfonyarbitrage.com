<?php

namespace App\MessageHandler;

use App\Entity\ArbitrageOpportunity;
use App\Entity\TradeExecution;
use App\Message\ExecuteArbitrageMessage;
use App\Service\ExchangeFactory;
use App\Service\ExchangeService\ExchangeServiceInterface;
use App\Service\FundingVerdict;
use App\Service\TradeFundingGuard;
use App\Service\TradingCircuitBreaker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

use function React\Async\await;
use function React\Promise\all;

#[AsMessageHandler]
readonly class ExecuteArbitrageHandler
{
    public function __construct(
        private ExchangeFactory        $exchangeFactory,
        private TradingCircuitBreaker  $circuitBreaker,
        private TradeFundingGuard      $fundingGuard,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
        private TexterInterface        $texter,
        #[Autowire(env: 'ADMIN_PHONE_NUMBER')] private string $adminPhoneNumber,
    ) {}

    /**
     * @throws InvalidArgumentException|ORMException|\Throwable
     */
    public function __invoke(ExecuteArbitrageMessage $message): void
    {
        $buyExchangeName = $message->getBuyExchange();
        $sellExchangeName = $message->getSellExchange();

        // 1. PRE-FLIGHT CHECK: Circuit Breaker Check
        // The one breaker call left unguarded, and deliberately so — see guardBreaker().
        // Nothing has been traded yet, so an exception here aborts with no position open
        // and no record owed. Failing loudly and letting Messenger retry is the correct
        // response to "we cannot tell whether this venue is safe to trade".
        if (!$this->circuitBreaker->isAllowed($buyExchangeName) || !$this->circuitBreaker->isAllowed($sellExchangeName)) {
            $this->logger->warning("Execution aborted by Circuit Breaker for opportunity {$message->getOpportunityId()}");
            return;
        }

        $buyExchange = $this->exchangeFactory->create($buyExchangeName);
        $sellExchange = $this->exchangeFactory->create($sellExchangeName);

        // 2. PRE-FLIGHT CHECK: Funding on both venues
        // An exchange only reports an underfunded account by rejecting the order, which here
        // would mean discovering it with the other leg already filled — a partial fill, an
        // emergency unwind and a realized loss, on a trade that was never going to work. Two
        // concurrent balance reads beforehand make that a skipped opportunity instead.
        //
        // Deliberately outside the timing window opened below: the round trip is ours, not
        // the venues', and billing it to the breaker's latency budget would trip venues for
        // a call they answered promptly.
        //
        // Either leg failing cancels both. A buy with no exit is not an arbitrage, and
        // neither is an exit with nothing to sell — the same reasoning as the breaker gate.
        $funding = $this->fundingGuard->clearLegs(
            $buyExchange,
            $buyExchangeName,
            $sellExchange,
            $sellExchangeName,
            $message->getSymbol(),
            $message->getAmount(),
            $message->getBuyPrice()
        );

        if (!$funding->bothLegsCleared()) {
            // Only an unreadable balance is charged to its venue. Being short is our own
            // funding problem and says nothing about the exchange, whereas a private
            // endpoint that will not answer is exactly what the breaker exists to notice.
            // A list rather than a venue-keyed map: both legs can route to the same venue,
            // and that circuit is owed both reports.
            foreach ([[$buyExchangeName, $funding->buy], [$sellExchangeName, $funding->sell]] as [$venue, $verdict]) {
                if ($verdict === FundingVerdict::Unknown) {
                    $this->reportFailure($venue, 'Balance check failed before execution');
                }
            }

            $blocked = [];

            if ($funding->buy !== FundingVerdict::Sufficient) {
                $blocked[] = "{$buyExchangeName} cannot fund the buy leg";
            }

            if ($funding->sell !== FundingVerdict::Sufficient) {
                $blocked[] = "{$sellExchangeName} cannot cover the sell leg";
            }

            $this->logger->warning(sprintf(
                'Execution aborted for opportunity %s: %s.',
                $message->getOpportunityId(),
                implode(' and ', $blocked)
            ));

            return;
        }

        // 3. DISPATCH BOTH LEGS OVER THE NETWORK
        // ccxt's async client returns an already-running promise driven by a
        // non-blocking React HTTP browser, so the SELL request goes on the wire while
        // the BUY is still awaiting its response — genuinely concurrent, not interleaved
        // bookkeeping around two blocking calls.
        $startTime = microtime(true);

        $promises = [
            'buy' => $this->settle($buyExchange->executeMarketOrderAsync(
                $message->getSymbol(),
                'buy',
                $message->getAmount()
            )),
            'sell' => $this->settle($sellExchange->executeMarketOrderAsync(
                $message->getSymbol(),
                'sell',
                $message->getAmount()
            )),
        ];

        $results = [];
        $errors = [];

        // 4. WAIT FOR BOTH TO SETTLE
        // Both legs are already in flight; this just runs the event loop until each has
        // an outcome. Neither leg can be abandoned while its order is still live.
        $settled = await(all($promises));

        foreach ($settled as $key => $result) {
            if ($result['state'] === 'fulfilled') {
                $results[$key] = $result['value'];
            } else {
                $errors[$key] = $result['reason'];
            }
        }

        $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // 5. HANDLE EXECUTION OUTCOMES & RISK UNWINDING
        if (count($results) === 2) {
            // SUCCESS: Both orders executed simultaneously
            $this->reportSuccess($buyExchangeName, $executionTimeMs);
            $this->reportSuccess($sellExchangeName, $executionTimeMs);

            $this->logExecutionToRds($message, $results['buy'], $results['sell'], 'COMPLETED', $executionTimeMs);

        } elseif (isset($results['buy']) && isset($errors['sell'])) {
            // CRITICAL RISK: Partial Fill! BUY filled, but SELL failed.
            $this->reportFailure($sellExchangeName, "SELL order failed: " . $errors['sell']->getMessage());

            $this->logger->critical("PARTIAL FILL DETECTED! Unwinding position on {$buyExchangeName}...");
            $unwind = $this->unwindPosition($buyExchange, $buyExchangeName, 'sell', $message);

            // The reversing order is the sell side of what actually happened, so it goes in
            // the sell slot: the row then carries the realized loss on the round trip rather
            // than an empty leg and a null P&L.
            $this->logExecutionToRds(
                $message,
                $results['buy'],
                $unwind,
                $unwind === null ? 'PARTIAL_BUY_UNWIND_FAILED' : 'PARTIAL_BUY_UNWOUND',
                $executionTimeMs
            );

        } elseif (isset($results['sell']) && isset($errors['buy'])) {
            // CRITICAL RISK: Partial Fill! SELL filled, but BUY failed.
            $this->reportFailure($buyExchangeName, "BUY order failed: " . $errors['buy']->getMessage());

            $this->logger->critical("PARTIAL FILL DETECTED! Unwinding position on {$sellExchangeName}...");
            $unwind = $this->unwindPosition($sellExchange, $sellExchangeName, 'buy', $message);

            $this->logExecutionToRds(
                $message,
                $unwind,
                $results['sell'],
                $unwind === null ? 'PARTIAL_SELL_UNWIND_FAILED' : 'PARTIAL_SELL_UNWOUND',
                $executionTimeMs
            );

        } else {
            // TOTAL FAILURE: Both orders failed
            $this->reportFailure($buyExchangeName, $errors['buy']->getMessage());
            $this->reportFailure($sellExchangeName, $errors['sell']->getMessage());

            $this->logExecutionToRds($message, null, null, 'FAILED', $executionTimeMs);
        }
    }

    /**
     * Converts a leg into a promise that always fulfils, carrying its outcome.
     *
     * React's all() rejects the moment any input rejects, which here would mean
     * returning while the other leg's order is still live on the venue — leaving a
     * position nobody knows about. Settling first means a failed leg can never
     * abandon its partner.
     *
     * @param PromiseInterface<array> $leg
     * @return PromiseInterface<array{state: string, value?: array, reason?: \Throwable}>
     */
    private function settle(PromiseInterface $leg): PromiseInterface
    {
        return $leg->then(
            static fn (array $order): array => ['state' => 'fulfilled', 'value' => $order],
            static fn (\Throwable $e): array => ['state' => 'rejected', 'reason' => $e],
        );
    }

    /**
     * Emergency Unwind: Immediately market-reverses an orphaned trade to flatten position.
     *
     * Returning the reversing order rather than nothing is what lets the caller tell a
     * flattened position from an open one — the two used to be indistinguishable in the
     * ledger — and gives it the fill needed to record the realized loss.
     *
     * Deliberately does NOT consult the circuit breaker. An unwind reduces risk rather
     * than taking it, and the venue holding the position may well have just been tripped
     * by the same incident that created it — on a same-venue trade, by the leg failure a
     * few lines above. Gating this call could strand an open position behind a cooldown,
     * which is the opposite of what the breaker is for. The funding check is skipped for the
     * same reason, and is moot besides: the fill being reversed has itself just delivered
     * whatever the reversing order spends.
     *
     * @return array|null the reversing order, or null if the position is STILL OPEN
     */
    private function unwindPosition(
        ExchangeServiceInterface $exchange,
        string $venue,
        string $side,
        ExecuteArbitrageMessage $message,
    ): ?array {
        try {
            $order = $exchange->executeMarketOrder($message->getSymbol(), $side, $message->getAmount());
            $this->logger->info("Position successfully unwound via market {$side}.");

            return $order;
        } catch (\Throwable $e) {
            $this->logger->emergency("CRITICAL FAILURE: Emergency unwind failed! Manual intervention required! Error: " . $e->getMessage());

            // Worst outcome in the system: an unhedged position nobody is watching. A log
            // line alone leaves it sitting until someone reads a mailbox, so page the admin
            // on the same channel the circuit breaker already uses.
            //
            // Order matters. The human is raised first, then the venue is stopped, and each
            // step is isolated so a failure in either cannot prevent the ledger row that
            // records the open position — that row is the last thing standing between an
            // orphaned trade and nobody ever knowing about it.
            $this->pageAdmin(sprintf(
                '🚨 UNWIND FAILED on %s! %s %s left OPEN (opportunity %s). Manual intervention required!',
                $venue,
                $message->getAmount(),
                $message->getSymbol(),
                $message->getOpportunityId()
            ));

            $this->quarantineVenue($venue, $e);

            return null;
        }
    }

    private function reportSuccess(string $venue, int $executionTimeMs): void
    {
        $this->guardBreaker(
            fn() => $this->circuitBreaker->recordSuccess($venue, $executionTimeMs),
            "recording success for {$venue}"
        );
    }

    private function reportFailure(string $venue, string $reason): void
    {
        $this->guardBreaker(
            fn() => $this->circuitBreaker->recordFailure($venue, $reason),
            "recording failure for {$venue}"
        );
    }

    /**
     * The breaker is bookkeeping, and bookkeeping must never outrank the trade.
     *
     * Every one of its methods touches the cache, and any of them can reach the SMS
     * transport when a circuit trips or recovers — so all of them can throw at precisely
     * the moment things are already going wrong. Unguarded, a cache blip during a partial
     * fill would abort this handler before the unwind ran and before the ledger row was
     * written, turning a recoverable incident into an orphaned position nobody knows
     * about. A degraded breaker is a problem; losing the trade record is worse.
     */
    private function guardBreaker(callable $update, string $context): void
    {
        try {
            $update();
        } catch (\Throwable $e) {
            $this->logger->error("Circuit breaker update failed while {$context}: " . $e->getMessage());
        }
    }

    /**
     * Takes the venue out of service immediately after it refused an emergency order.
     * Somewhere that will not accept a risk-reducing order has no business receiving
     * risk-taking ones, and one such refusal is already one too many — hence an outright
     * trip rather than a counter increment that would let the next trade through.
     *
     * Only failure is reported. A successful unwind says nothing about the venue's health
     * and recording one would reset the counter the leg failure just incremented.
     *
     * Guarded like every other breaker call — see guardBreaker().
     */
    private function quarantineVenue(string $venue, \Throwable $cause): void
    {
        $this->guardBreaker(
            fn() => $this->circuitBreaker->tripImmediately($venue, 'Unwind failed: ' . $cause->getMessage()),
            "tripping {$venue} after a refused unwind"
        );
    }

    /**
     * Sends an SMS to the on-call admin.
     *
     * Transport failures are swallowed on purpose: this only runs when a position is
     * already open, and an SMS outage must not stop the ledger row that records it.
     */
    private function pageAdmin(string $message): void
    {
        try {
            $this->texter->send(new SmsMessage(phone: $this->adminPhoneNumber, subject: $message));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to page admin about an open position: ' . $e->getMessage());
        }
    }

    /**
     * Records transactional execution details into RDS.
     * @throws ORMException
     */
    private function logExecutionToRds(
        ExecuteArbitrageMessage $msg,
        ?array $buyResult,
        ?array $sellResult,
        string $status,
        int $latencyMs
    ): void {
        // 1. Fetch reference to parent opportunity
        $opportunity = $this->em->getReference(ArbitrageOpportunity::class, $msg->getOpportunityId());

        // 2. Calculate actual realized profit if both legs produced a fill
        $actualProfitUsd = null;
        if ($buyResult && $sellResult) {
            // The quote is only a legitimate fallback for a trade that executed as quoted.
            // On an unwound partial fill one of these legs is the reversing order, which the
            // quote never described — falling back to it there would turn a realized loss
            // into a fabricated profit, so leave the P&L unknown instead.
            $quotedFallbackApplies = $status === 'COMPLETED';

            $buyFilled = $buyResult['price'] ?? $buyResult['average']
                ?? ($quotedFallbackApplies ? $msg->getBuyPrice() : null);
            $sellFilled = $sellResult['price'] ?? $sellResult['average']
                ?? ($quotedFallbackApplies ? $msg->getSellPrice() : null);

            if ($buyFilled !== null && $sellFilled !== null) {
                $actualProfitUsd = number_format(
                    ((float) $sellFilled - (float) $buyFilled) * $msg->getAmount(),
                    4,
                    '.',
                    ''
                );
            }
        }

        // 3. Populate TradeExecution matching RDS schema
        $execution = new TradeExecution();
        $execution->opportunity = $opportunity;
        $execution->buyOrderId = $buyResult['id'] ?? null;
        $execution->sellOrderId = $sellResult['id'] ?? null;
        $execution->buyFilledPrice = (isset($buyResult['price']) ? (string)$buyResult['price'] : null);
        $execution->sellFilledPrice = (isset($sellResult['price']) ? (string)$sellResult['price'] : null);
        $execution->actualProfitUSD = $actualProfitUsd;
        $execution->status = $status;
        $execution->executionTimeMs = $latencyMs;
        $execution->createdAt = new \DateTimeImmutable("now");

        $this->em->persist($execution);
        $this->em->flush();
    }
}
