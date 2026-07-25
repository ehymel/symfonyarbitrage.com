<?php

namespace App\Message;

class ExecuteArbitrageMessage
{
    public function __construct(
        private string $opportunityId,
        private string $symbol,           // e.g., 'ETH/USDT'
        private string $buyExchange,       // e.g., 'coinbase'
        private string $sellExchange,      // e.g., 'kraken'
        private float $buyPrice,
        private float $sellPrice,
        private float $amount             // Target quantity
    ) {}

    public function getOpportunityId(): string { return $this->opportunityId; }
    public function getSymbol(): string { return $this->symbol; }
    public function getBuyExchange(): string { return $this->buyExchange; }
    public function getSellExchange(): string { return $this->sellExchange; }
    public function getBuyPrice(): float { return $this->buyPrice; }
    public function getSellPrice(): float { return $this->sellPrice; }
    public function getAmount(): float { return $this->amount; }
}
