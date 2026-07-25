<?php

namespace App\Command;

use App\Entity\ArbitrageOpportunity;
use App\Message\ExecuteArbitrageMessage;
use App\Service\ArbitrageEvaluator;
use App\Service\ExchangeFactory;
use App\Service\TradingCircuitBreaker;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    public function __construct(
        private ExchangeFactory $exchangeFactory,
        private ArbitrageEvaluator $evaluator,
        private TradingCircuitBreaker $circuitBreaker,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("🚀 Arbitrage Detection Engine Started...");

        // Initialize CCXT instances
        $instances = [];
        foreach ($this->exchangesToScan as $name) {
            $instances[$name] = $this->exchangeFactory->create($name);
        }

        // Infinite polling loop
        while (true) {
            foreach ($this->tradingPairs as $pair) {
                try {
                    $orderBooks = $this->fetchOrderBooksConcurrently($instances, $pair);

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

                } catch (\Throwable $e) {
                    $this->logger->error("Scanner exception: " . $e->getMessage());
                }
            }

            // Sleep 250ms to prevent exceeding HTTP API rate limits
            usleep(250000);
        }

        return Command::SUCCESS;
    }

    /**
     * Fetches order books simultaneously from all configured exchanges.
     */
    private function fetchOrderBooksConcurrently(array $instances, string $symbol): array
    {
        $promises = [];

        foreach ($instances as $name => $exchange) {
            $promises[$name] = new Promise(function () use (&$promises, $exchange, $name, $symbol) {
                try {
                    // CCXT order book fetch call
                    $book = $exchange->fetch_order_book($symbol, 10); // Fetch top 10 order book levels
                    $promises[$name]->resolve($book);
                } catch (\Throwable $e) {
                    $promises[$name]->reject($e);
                }
            });
        }

        $settled = Utils::settle($promises)->wait();
        $orderBooks = [];

        foreach ($settled as $name => $result) {
            if ($result['state'] === 'fulfilled') {
                $orderBooks[$name] = $result['value'];
            }
        }

        return $orderBooks;
    }

    /**
     * Persists opportunity to RDS and dispatches Messenger execution job.
     * @throws ExceptionInterface
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

        $this->em->persist($opp);
        $this->em->flush(); // Flushes and populates generated ID

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
