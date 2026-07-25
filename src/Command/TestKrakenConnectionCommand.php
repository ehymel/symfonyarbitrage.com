<?php

namespace App\Command;

use App\Service\ExchangeService\KrakenExchangeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:kraken',
    description: 'Test Kraken connection.'
)]
class TestKrakenConnectionCommand extends Command
{
    public function __construct(private readonly KrakenExchangeService $exchange)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test Kraken Connection');

        // 1. Test View Permission
        $balance = $this->exchange->getBalance();
        $io->info('Connected! ETH Balance: ' . ($balance['ETH']['free'] ?? 0));

        // 2. Test Order Book Fetching
        $orderBook = $this->exchange->getOrderBook('ETH/USDT');
        $io->info('Top Bid: '.$orderBook['bids'][0][0]);

        return Command::SUCCESS;
    }
}
