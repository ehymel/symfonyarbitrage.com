<?php

namespace App\Command;

use ccxt\coinbase;
use ccxt\ExchangeError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:test:coinbase',
    description: 'Test Coinbase connection.'
)]
class TestCoinbaseConnectionCommand extends Command
{
    public function __construct(
        #[Autowire(env: 'COINBASE_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'COINBASE_API_SECRET')] private readonly string $apiSecret,
    )
    {
        parent::__construct();
    }

    /**
     * @throws ExchangeError
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test Coinbase Connection');

        $exchange = new coinbase([
            'apiKey' => $this->apiKey,
            'secret' => $this->apiSecret,
            'enableRateLimit' => true,
        ]);

        // 1. Test View Permission
        $balance = $exchange->fetch_balance();
        $io->info('Connected! ETH Balance: ' . ($balance['ETH']['free'] ?? 0));

        // 2. Test Order Book Fetching
        $orderBook = $exchange->fetch_order_book('ETH/USDT');
        $io->info('Top Bid: '.$orderBook['bids'][0][0]);

        return Command::SUCCESS;
    }
}
