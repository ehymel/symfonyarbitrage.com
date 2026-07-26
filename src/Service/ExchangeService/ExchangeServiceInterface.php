<?php

namespace App\Service\ExchangeService;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface ExchangeServiceInterface
{
    public function getBalance(): array;

    public function getOrderBook(string $symbol = 'ETH/USDT', ?int $limit = null): array;

    public function executeMarketOrder(string $symbol, string $side, float $amount): array;
}
