<?php

namespace App\Entity;

use App\Repository\TradeExecutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeExecutionRepository::class)]
class TradeExecution
{
    #[ORM\Id, ORM\Column(type: Types::BIGINT), ORM\GeneratedValue]
    private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ArbitrageOpportunity::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?ArbitrageOpportunity $opportunity = null;

    #[ORM\Column(length: 100)]
    public ?string $buyOrderId = null;

    #[ORM\Column(length: 100)]
    public ?string $sellOrderId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    public ?string $buyFilledPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    public ?string $sellFilledPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    public ?string $actualProfitUSD = null;

    #[ORM\Column(length: 30)]
    public ?string $status = null;  // COMPLETED, PARTIAL_BUY_UNWOUND, PARTIAL_SELL_UNWOUND, FAILED

    #[ORM\Column]
    public ?int $executionTimeMs;

    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;
}
