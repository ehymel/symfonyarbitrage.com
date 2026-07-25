<?php

namespace App\Entity;

use App\Repository\LoginRepository;
use Doctrine\ORM\Mapping as ORM;
use Stringable;

#[ORM\Entity(repositoryClass: LoginRepository::class)]
#[ORM\Table(name: 'login')]
class Login implements Stringable
{
    public function __construct(User $user, string $ipAddress) {
        $this->loginTime = new \DateTimeImmutable('now');
        $this->user = $user;
        $this->ipAddress = $ipAddress;
    }

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'logins')]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $user = null;

    #[ORM\Column]
    public ?string $ipAddress = null;

    #[ORM\Column]
    public ?\DateTimeImmutable $loginTime = null;

    public function __toString(): string
    {
        return $this->loginTime?->format('n/j/y H:i:s').' from '.$this->ipAddress;
    }
}
