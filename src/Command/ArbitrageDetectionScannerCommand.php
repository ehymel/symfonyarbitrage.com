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
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
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
    /**
     * Capital committed to a single opportunity when --size is not given.
     *
     * Deliberately small. This is the number that decides how much real money a bug in the
     * rest of the pipeline can move, so the default is the one that hurts least and every
     * increase is a decision someone made on purpose at the command line.
     */
    private const float DEFAULT_TARGET_AMOUNT_USD = 100.0;

    /**
     * Minimum net margin, as a percentage, for a spread to be worth executing.
     *
     * Net of both venues' taker fees, so this is profit and not spread: the combined fees
     * in ArbitrageEvaluator already run to about 0.66%, and anything left over is what this
     * threshold measures. Set low enough to be worth the risk of the trade, high enough
     * that the edge is not eaten by the slippage between quoting and filling.
     */
    private const float DEFAULT_MIN_NET_MARGIN_PCT = 0.35;

    /**
     * Below this, --min-margin is almost certainly a fraction typed where a percentage was
     * wanted — 0.0035 for 0.35% — which reads as no threshold at all.
     */
    private const float IMPLAUSIBLY_SMALL_MARGIN_PCT = 0.01;

//    private array $exchangesToScan = ['coinbase', 'kraken', 'binance'];
    private array $exchangesToScan = [
        'coinbase',
        'kraken',
        'cryptocom',
//        'gemini',   // awaiting api key
//        'binance',  // not available in Texas
//        'bitstamp',  // not available in Texas
    ];
    private array $tradingPairs = ['ETH/USDT', 'BTC/USDT', 'SOL/USDT', 'AVAX/USDT', 'DOGE/USDT'];

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

        $this->addOption(
            'size',
            's',
            InputOption::VALUE_REQUIRED,
            'Capital in USD to commit per opportunity. Keep it small while the pipeline is being proven out.',
            self::DEFAULT_TARGET_AMOUNT_USD
        );

        $this->addOption(
            'min-margin',
            'm',
            InputOption::VALUE_REQUIRED,
            'Minimum net profit to act on, as a percentage after both venues\' fees (default 0.35 = 0.35%). 0 takes every spread that clears its fees.',
            self::DEFAULT_MIN_NET_MARGIN_PCT
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \Throwable
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Resolved before anything is warmed or scanned. The evaluator rejects a
        // non-positive size, but it does so once per venue pair per cycle from inside the
        // loop's catch-all — four times a second, forever, with the run still "working".
        // A bad size is a mistake at the keyboard, so it stops here while someone is
        // still looking at the terminal.
        $targetAmountUsd = $this->resolveTargetAmount($input, $output);
        $minNetMarginPct = $this->resolveMinNetMargin($input, $output);

        if ($targetAmountUsd === null || $minNetMarginPct === null) {
            return Command::INVALID;
        }

        // Stated up front and unmissably: between them these two decide how much real money
        // the run can move and how thin an edge it will move it on. A supervised process
        // that quietly picked up the wrong pair of numbers is the failure worth spending a
        // line of output on.
        $output->writeln(sprintf(
            '🚀 Arbitrage Detection Engine Started... committing $%s per opportunity above %s%% net margin.',
            number_format($targetAmountUsd, 2),
            number_format($minNetMarginPct, 2)
        ));

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
                                $output->writeln("❌ Circuit breaker tripped for: " . $buyEx . " and " . $sellEx);
                                continue;
                            }

                            $opportunity = $this->evaluator->evaluate(
                                symbol: $pair,
                                buyExchangeName: $buyEx,
                                buyOrderBook: $orderBooks[$buyEx],
                                sellExchangeName: $sellEx,
                                sellOrderBook: $orderBooks[$sellEx],
                                targetAmountUsd: $targetAmountUsd, // --size, default $100
                                // Despite the name, the evaluator wants a fraction rather
                                // than a percentage — 0.0035 for 0.35%.
                                minNetMarginPct: $minNetMarginPct / 100.0
                            );

                            if ($opportunity) {
                                $this->handleOpportunityDetected($opportunity, $output);
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
     * Reads --size, or null when it does not describe a position anyone meant to take.
     *
     * Rejected rather than coerced. A bare (float) cast turns "1o0" into 0.0 and a stray
     * "100 USD" into 100.0 — one of those silently disables trading and the other silently
     * trades. Neither is a reading of the operator's intent worth guessing at when real
     * money is on the other end of it.
     */
    private function resolveTargetAmount(InputInterface $input, OutputInterface $output): ?float
    {
        $raw = $input->getOption('size');

        if (!is_numeric($raw)) {
            $output->writeln(sprintf(
                '<error>--size must be an amount in USD, got "%s".</error>',
                is_scalar($raw) ? (string) $raw : get_debug_type($raw)
            ));

            return null;
        }

        $size = (float) $raw;

        if ($size <= 0.0) {
            // The evaluator would throw on this anyway; catching it here means the message
            // says which option was wrong instead of surfacing as a scanner exception.
            $output->writeln(sprintf('<error>--size must be greater than zero, got %s.</error>', $size));

            return null;
        }

        return $size;
    }

    /**
     * Reads --min-margin as a percentage, or null when it is not one.
     *
     * Zero is allowed and means "take anything that clears its fees". What is not allowed
     * is the value that looks like zero without saying so: this option is a percentage, so
     * a fraction typed into it — 0.0035 meant as 0.35% — sets the bar six thousand times
     * lower than intended and turns the scanner loose on spreads that cannot pay for
     * themselves. That is the one mistake here that costs money rather than opportunities,
     * so it is named rather than accepted.
     */
    private function resolveMinNetMargin(InputInterface $input, OutputInterface $output): ?float
    {
        $raw = $input->getOption('min-margin');

        if (!is_numeric($raw)) {
            $output->writeln(sprintf(
                '<error>--min-margin must be a percentage, got "%s".</error>',
                is_scalar($raw) ? (string) $raw : get_debug_type($raw)
            ));

            return null;
        }

        $margin = (float) $raw;

        if ($margin < 0.0) {
            $output->writeln(sprintf(
                '<error>--min-margin cannot be negative, got %s. Use 0 to take every spread that clears its fees.</error>',
                $margin
            ));

            return null;
        }

        if ($margin > 0.0 && $margin < self::IMPLAUSIBLY_SMALL_MARGIN_PCT) {
            $output->writeln(sprintf(
                '<error>--min-margin is a percentage, so %s means %s%% — effectively no threshold at all. '
                . 'Did you mean %s? Use 0 to take every spread that clears its fees.</error>',
                $margin,
                $margin,
                $margin * 100
            ));

            return null;
        }

        return $margin;
    }

    /**
     * Decides whether the scan can carry on after failing to record an opportunity.
     *
     * @return bool true when the run must stop
     */
    private function haltAfterPersistenceFailure(OpportunityPersistenceFailed $failure, OutputInterface $output): bool {
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
    private function handleOpportunityDetected($dto, OutputInterface $output): void
    {
        $msg = sprintf("⚡ ARBITRAGE DETECTED! %s | Buy %s @ $%.2f | Sell %s @ $%.2f | Est. Profit: $%.4f",
            $dto->pair, $dto->buyExchange, $dto->buyPrice, $dto->sellExchange, $dto->sellPrice, $dto->netProfitUsd);

        $output->writeln($msg);
        $this->logger->info($msg);

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
