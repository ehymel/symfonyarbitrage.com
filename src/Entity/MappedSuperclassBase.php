<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\ManyToOne;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\MappedSuperclass]
class MappedSuperclassBase
{
    #[ORM\Column(nullable: true)]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $modifiedAt = null {
        set(?\DateTimeImmutable $value) {
            $this->modifiedAt = $value ?? new \DateTimeImmutable();
        }
    }

    #[ORM\ManyToOne]
    #[Gedmo\Blameable(on: 'create')]
    public ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[Gedmo\Blameable(on: 'update')]
    public ?User $modifiedBy = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    #[ManyToOne(targetEntity: User::class)]
    public ?User $deletedBy = null;
}
