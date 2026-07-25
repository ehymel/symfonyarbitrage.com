<?php

namespace App\Dto;

readonly class ArbitrageOpportunityDto
{
    public function __construct(
        public string $pair,
        public string $buyExchange,
        public string $sellExchange,
        public float $buyPrice,
        public float $sellPrice,
        public float $amount,
        public float $grossSpreadPct,
        public float $netProfitUsd
    ) {}
}
