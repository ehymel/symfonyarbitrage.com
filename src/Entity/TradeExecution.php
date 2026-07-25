<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TradeExecution
{
    #[ORM\Id, ORM\Column(type: Types::BIGINT), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AribitrageOpportunity::class)]
    public ?AribitrageOpportunity $opportunity = null;

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

    #[ORM\Column(length: 20)]
    public ?string $status = null;  // PENDING, COMPLETED, PARTIAL_FILL, FAILED

    #[ORM\Column]
    public ?int $executionTime_ms;

    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;
}
