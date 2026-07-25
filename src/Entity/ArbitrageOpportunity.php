<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ArbitrageOpportunity
{
    #[ORM\Id, ORM\Column(type: Types::BIGINT), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(length: 20)]
    public ?string $pair = null;

    #[ORM\Column(length: 50)]
    public ?string $symbol = null;

    #[ORM\Column(length: 50)]
    public ?string $buyExchange = null;

    #[ORM\Column(length: 50)]
    public ?string $sellExchange = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    public ?string $buyPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    public ?string $sellPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4)]
    public ?string $grossSpreadPct = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    public ?string $estimatedNetProfitUSD = null;

    #[ORM\Column]
    public ?\DateTimeImmutable $detectedAt = null;

}
