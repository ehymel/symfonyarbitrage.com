<?php

namespace App\Command;

use App\Service\ExchangeService\CoinbaseExchangeService;
use App\Service\ExchangeService\GeminiExchangeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:gemini',
    description: 'Test Gemini connection.'
)]
class TestGeminiConnectionCommand extends Command
{
    public function __construct(private readonly GeminiExchangeService $exchange)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test Gemini Connection');

        // 1. Test View Permission
        $balance = $this->exchange->getBalance();
        $io->info('Connected! ETH Balance: ' . ($balance['ETH']['free'] ?? 0));

        // 2. Test Order Book Fetching
        $orderBook = $this->exchange->getOrderBook('ETH/USDT');
        $io->info('Top Bid: '.$orderBook['bids'][0][0]);

        return Command::SUCCESS;
    }
}
