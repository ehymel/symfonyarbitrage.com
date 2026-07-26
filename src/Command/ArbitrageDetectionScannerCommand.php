<?php

namespace App\Command;

use App\Entity\ArbitrageOpportunity;
use App\Exception\OpportunityPersistenceFailed;
use App\Message\ExecuteArbitrageMessage;
use App\Service\AdminAlerter;
use App\Service\ArbitrageEvaluator;
use App\Service\ExchangeFactory;
use App\Service\ExchangeWarmer;
use App\Service\OrderBookFetcher;
use App\Service\TradingCircuitBreaker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:arbitrage:scan',
    description: 'Runs continuous asynchronous order book scanner to detect cross-exchange arbitrage.'
)]
class ArbitrageDetectionScannerCommand extends Command
{
//    private array $exchangesToScan = ['coinbase', 'kraken', 'binance'];
    private array $exchangesToScan = ['coinbase', 'kraken'];
    private array $tradingPairs = ['ETH/USDT', 'BTC/USDT', 'SOL/USDT'];

    /** Set once a run has already paged about unrecordable opportunities. */
    private bool $writeFailureAlerted = false;

    public function __construct(
        private readonly ExchangeFactory        $exchangeFactory,
        private readonly ExchangeWarmer         $exchangeWarmer,
        private readonly OrderBookFetcher       $orderBookFetcher,
        private readonly ArbitrageEvaluator     $evaluator,
        private readonly TradingCircuitBreaker  $circuitBreaker,
        private readonly MessageBusInterface    $bus,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        private readonly AdminAlerter           $adminAlerter,
        /** Pause after a write failure that left the connection usable. */
        private readonly int                    $writeFailureBackoffSeconds = 5,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Stop after this many scan cycles. 0 runs until interrupted.',
            0
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("🚀 Arbitrage Detection Engine Started...");
        $this->writeFailureAlerted = false;

        // Initialize CCXT instances
        $instances = [];
        foreach ($this->exchangesToScan as $name) {
            $instances[$name] = $this->exchangeFactory->create($name);
        }

        // Pre-load market metadata for every venue at once, so the first scan iteration
        // is not paying for a markets fetch it will never need again.
        $this->exchangeWarmer->warm(...$this->exchangesToScan);
        $output->writeln("📈 Markets pre-loaded for: " . implode(', ', $this->exchangesToScan));

        // Polling loop — unbounded by default, bounded by --limit for smoke tests and
        // supervised one-shot runs.
        $limit = max(0, (int) $input->getOption('limit'));

        for ($cycle = 1; $limit === 0 || $cycle <= $limit; ++$cycle) {
            foreach ($this->tradingPairs as $pair) {
                try {
                    // Fetch top 10 order book levels from every venue simultaneously
                    $orderBooks = $this->orderBookFetcher->fetchConcurrently($instances, $pair, 10);

                    // Cross-compare all exchange combinations (e.g. Coinbase vs Kraken, Kraken vs Binance)
                    foreach ($this->exchangesToScan as $buyEx) {
                        foreach ($this->exchangesToScan as $sellEx) {
                            if ($buyEx === $sellEx) continue;

                            if (!isset($orderBooks[$buyEx]) || !isset($orderBooks[$sellEx])) {
                                continue;
                            }

                            // Pre-flight check via Circuit Breaker
                            if (!$this->circuitBreaker->isAllowed($buyEx) || !$this->circuitBreaker->isAllowed($sellEx)) {
                                continue;
                            }

                            $opportunity = $this->evaluator->evaluate(
                                symbol: $pair,
                                buyExchangeName: $buyEx,
                                buyOrderBook: $orderBooks[$buyEx],
                                sellExchangeName: $sellEx,
                                sellOrderBook: $orderBooks[$sellEx],
                                targetAmountUsd: 100.0, // Configure position size
                                minNetMarginPct: 0.0035  // 0.35% minimum profit
                            );

                            if ($opportunity) {
                                $this->handleOpportunityDetected($opportunity);
                            }
                        }
                    }

                } catch (OpportunityPersistenceFailed $e) {
                    // A detected opportunity that cannot be recorded is also one that will
                    // never be executed, so this is handled apart from the routine failures
                    // below rather than logged and forgotten.
                    if ($this->haltAfterPersistenceFailure($e, $output)) {
                        return Command::FAILURE;
                    }
                } catch (\Throwable $e) {
                    $this->logger->error("Scanner exception: " . $e->getMessage());
                }
            }

            // Sleep 250ms to prevent exceeding HTTP API rate limits — but not on the way
            // out of the final cycle, where there is nothing left to pace.
            if ($limit === 0 || $cycle < $limit) {
                usleep(250000);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Decides whether the scan can carry on after failing to record an opportunity.
     *
     * @return bool true when the run must stop
     */
    private function haltAfterPersistenceFailure(
        OpportunityPersistenceFailed $failure,
        OutputInterface $output
    ): bool {
        $reason = $failure->getMessage();
        $this->logger->critical($reason);

        // Doctrine closes the entity manager after most write failures, and a closed
        // manager never reopens by itself — every later flush would throw the same way.
        // Carrying on would mean scanning a market it can no longer record, logging an
        // error four times a second forever. Stop instead, and let the supervisor bring
        // the process back with a fresh connection.
        if (!$this->em->isOpen()) {
            $output->writeln('<error>Entity manager closed after a write failure — stopping.</error>');
            $this->adminAlerter->alert(
                '🚨 Arbitrage scanner STOPPED: cannot write to the database',
                "The scanner detected an opportunity, failed to record it, and Doctrine has closed the "
                . "entity manager — every subsequent write would fail the same way.\n\n"
                . "The process has stopped and will not resume without a restart. No opportunities are "
                . "being detected or executed until it does.\n\n"
                . "Reason: {$reason}"
            );

            return true;
        }

        // The connection survived, so this may well be transient — a deadlock or a lock
        // timeout. Worth continuing, but not at full speed into a database that is
        // already struggling, and not paging again for every opportunity that follows.
        if (!$this->writeFailureAlerted) {
            $this->writeFailureAlerted = true;
            $this->adminAlerter->alert(
                '⚠️ Arbitrage scanner cannot record opportunities',
                "A detected opportunity could not be written to the database. The connection is still "
                . "open, so the scanner has backed off and is still running, but any opportunity it "
                . "finds meanwhile goes unrecorded and unexecuted.\n\n"
                . "Further failures in this run will be logged rather than alerted.\n\n"
                . "Reason: {$reason}"
            );
        }

        sleep($this->writeFailureBackoffSeconds);

        return false;
    }

    /**
     * Persists opportunity to RDS and dispatches Messenger execution job.
     * @throws ExceptionInterface|OpportunityPersistenceFailed
     */
    private function handleOpportunityDetected($dto): void
    {
        $this->logger->info(sprintf(
            "⚡ ARBITRAGE DETECTED! %s | Buy %s @ $%.2f | Sell %s @ $%.2f | Est. Profit: $%.4f",
            $dto->pair, $dto->buyExchange, $dto->buyPrice, $dto->sellExchange, $dto->sellPrice, $dto->netProfitUsd
        ));

        // 1. Record to RDS
        $opp = new ArbitrageOpportunity();
        $opp->pair = $dto->pair;
        $opp->buyExchange = $dto->buyExchange;
        $opp->sellExchange = $dto->sellExchange;
        $opp->buyPrice = (string)$dto->buyPrice;
        $opp->sellPrice = (string)$dto->sellPrice;
        $opp->grossSpreadPct = (string)$dto->grossSpreadPct;
        $opp->estimatedNetProfitUSD = (string)$dto->netProfitUsd;
        $opp->detectedAt = new \DateTimeImmutable("now");

        try {
            $this->em->persist($opp);
            $this->em->flush(); // Flushes and populates generated ID
        } catch (\Throwable $e) {
            throw new OpportunityPersistenceFailed(
                sprintf(
                    'Could not record %s opportunity (buy %s, sell %s): %s',
                    $dto->pair,
                    $dto->buyExchange,
                    $dto->sellExchange,
                    $e->getMessage()
                ),
                previous: $e
            );
        }

        // 2. Dispatch job to Execution Queue
        $this->bus->dispatch(new ExecuteArbitrageMessage(
            opportunityId: $opp->id,
            symbol: $dto->pair,
            buyExchange: $dto->buyExchange,
            sellExchange: $dto->sellExchange,
            buyPrice: $dto->buyPrice,
            sellPrice: $dto->sellPrice,
            amount: $dto->amount
        ));
    }
}
