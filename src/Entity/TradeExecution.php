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

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $buyOrderId = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $sellOrderId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    public ?string $buyFilledPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    public ?string $sellFilledPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    public ?string $actualProfitUSD = null;

    // COMPLETED, FAILED,
    // PARTIAL_BUY_UNWOUND / PARTIAL_SELL_UNWOUND             — one leg filled, position flattened
    // PARTIAL_BUY_UNWIND_FAILED / PARTIAL_SELL_UNWIND_FAILED — one leg filled, POSITION STILL OPEN
    #[ORM\Column(length: 30)]
    public ?string $status = null;

    #[ORM\Column]
    public ?int $executionTimeMs;

    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;
}
