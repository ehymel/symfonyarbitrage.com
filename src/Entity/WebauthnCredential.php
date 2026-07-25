<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\TrustPath;

#[ORM\Table(name: 'webauthn_credentials')]
#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
class WebauthnCredential extends CredentialRecord
{
    #[ORM\Id, ORM\GeneratedValue(strategy: "NONE"), ORM\Column(unique: true)]
    private(set) string $id;

    #[ORM\Column(nullable: true)]
    public ?string $name = null;

    #[ORM\Column(nullable: true)]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    public ?string $ipAddress = null;

    public function __construct(
        string $publicKeyCredentialId,
        string $type,
        array $transports,
        string $attestationType,
        TrustPath $trustPath,
        Uuid $aaguid,
        string $credentialPublicKey,
        string $userHandle,
        int $counter
    )
    {
        $this->id = Ulid::generate();
        parent::__construct($publicKeyCredentialId, $type, $transports, $attestationType, $trustPath, $aaguid, $credentialPublicKey, $userHandle, $counter);
    }
}
