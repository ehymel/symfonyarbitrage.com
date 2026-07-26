<?php

namespace App\MessageHandler;

use App\Entity\ArbitrageOpportunity;
use App\Entity\TradeExecution;
use App\Message\ExecuteArbitrageMessage;
use App\Service\ExchangeFactory;
use App\Service\ExchangeService\ExchangeServiceInterface;
use App\Service\TradingCircuitBreaker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;

use function React\Async\await;
use function React\Promise\all;

#[AsMessageHandler]
readonly class ExecuteArbitrageHandler
{
    public function __construct(
        private ExchangeFactory        $exchangeFactory,
        private TradingCircuitBreaker  $circuitBreaker,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger
    ) {}

    /**
     * @throws InvalidArgumentException|ORMException|TransportExceptionInterface
     */
    public function __invoke(ExecuteArbitrageMessage $message): void
    {
        $buyExchangeName = $message->getBuyExchange();
        $sellExchangeName = $message->getSellExchange();

        // 1. PRE-FLIGHT CHECK: Circuit Breaker Check
        if (!$this->circuitBreaker->isAllowed($buyExchangeName) || !$this->circuitBreaker->isAllowed($sellExchangeName)) {
            $this->logger->warning("Execution aborted by Circuit Breaker for opportunity {$message->getOpportunityId()}");
            return;
        }

        $buyExchange = $this->exchangeFactory->create($buyExchangeName);
        $sellExchange = $this->exchangeFactory->create($sellExchangeName);

        // 2. DISPATCH BOTH LEGS OVER THE NETWORK
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

        // 3. WAIT FOR BOTH TO SETTLE
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

        // 4. HANDLE EXECUTION OUTCOMES & RISK UNWINDING
        if (count($results) === 2) {
            // SUCCESS: Both orders executed simultaneously
            $this->circuitBreaker->recordSuccess($buyExchangeName, $executionTimeMs);
            $this->circuitBreaker->recordSuccess($sellExchangeName, $executionTimeMs);

            $this->logExecutionToRds($message, $results['buy'], $results['sell'], 'COMPLETED', $executionTimeMs);

        } elseif (isset($results['buy']) && isset($errors['sell'])) {
            // CRITICAL RISK: Partial Fill! BUY filled, but SELL failed.
            $this->circuitBreaker->recordFailure($sellExchangeName, "SELL order failed: " . $errors['sell']->getMessage());

            $this->logger->critical("PARTIAL FILL DETECTED! Unwinding position on {$buyExchangeName}...");
            $this->unwindPosition($buyExchange, $message->getSymbol(), 'sell', $message->getAmount());

            $this->logExecutionToRds($message, $results['buy'], null, 'PARTIAL_BUY_UNWOUND', $executionTimeMs);

        } elseif (isset($results['sell']) && isset($errors['buy'])) {
            // CRITICAL RISK: Partial Fill! SELL filled, but BUY failed.
            $this->circuitBreaker->recordFailure($buyExchangeName, "BUY order failed: " . $errors['buy']->getMessage());

            $this->logger->critical("PARTIAL FILL DETECTED! Unwinding position on {$sellExchangeName}...");
            $this->unwindPosition($sellExchange, $message->getSymbol(), 'buy', $message->getAmount());

            $this->logExecutionToRds($message, null, $results['sell'], 'PARTIAL_SELL_UNWOUND', $executionTimeMs);

        } else {
            // TOTAL FAILURE: Both orders failed
            $this->circuitBreaker->recordFailure($buyExchangeName, $errors['buy']->getMessage());
            $this->circuitBreaker->recordFailure($sellExchangeName, $errors['sell']->getMessage());

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
     */
    private function unwindPosition(ExchangeServiceInterface $exchange, string $symbol, string $side, float $amount): void
    {
        try {
            $exchange->executeMarketOrder($symbol, $side, $amount);
            $this->logger->info("Position successfully unwound via market {$side}.");
        } catch (\Throwable $e) {
            $this->logger->emergency("CRITICAL FAILURE: Emergency unwind failed! Manual intervention required! Error: " . $e->getMessage());
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

        // 2. Calculate actual realized profit if both filled
        $actualProfitUsd = null;
        if ($buyResult && $sellResult) {
            $buyFilled = (float) ($buyResult['price'] ?? $buyResult['average'] ?? $msg->getBuyPrice());
            $sellFilled = (float) ($sellResult['price'] ?? $sellResult['average'] ?? $msg->getSellPrice());
            $actualProfitUsd = number_format(($sellFilled - $buyFilled) * $msg->getAmount(), 4, '.', '');
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
