<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 * *
 * @method WebauthnCredential|null find($id, $lockMode = null, $lockVersion = null)
 * @method WebauthnCredential|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method WebauthnCredential[]    findAll()
 * @method WebauthnCredential[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */

final class WebauthnCredentialRepository extends ServiceEntityRepository implements CredentialRecordRepositoryInterface, CanSaveCredentialRecord
{
    public function __construct(ManagerRegistry $registry, private readonly ObjectMapperInterface $objectMapper, private readonly RequestStack $requestStack)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    public function save(WebauthnCredential $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(WebauthnCredential $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?WebauthnCredential
    {
        return $this->findOneBy(['publicKeyCredentialId' => $publicKeyCredentialId]);
    }

    /**
     * @return CredentialRecord[]
     */
    public function findAllForUser(User $user): array
    {
        return $this->findBy(['userHandle' => $user->getUserIdentifier()]);
    }

    /**
     * @return CredentialRecord[]
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        return $this->findBy(['userHandle' => $publicKeyCredentialUserEntity->id]);
    }

    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        // recall that WebauthnCredential extends CredentialRecord!

        $id = $credentialRecord->publicKeyCredentialId;
        $webauthnCredential = $this->findOneBy(['publicKeyCredentialId' => $id]);

        if ($webauthnCredential) {
            // Update the signature counter to prevent cloned authenticator attacks
            $webauthnCredential->counter = $credentialRecord->counter;
        } else {
            $webauthnCredential = $this->objectMapper->map($credentialRecord, WebauthnCredential::class);
            $webauthnCredential->publicKeyCredentialId = $id;

            // Optional, added by ehymel to save extra info with saved credential record
            $request = $this->requestStack->getCurrentRequest();
            if ($request) {
                // Save the raw User-Agent string as the name
                $webauthnCredential->name = $request->headers->get('User-Agent');
                $webauthnCredential->ipAddress = $request->getClientIp();
            }
        }

        $this->save($webauthnCredential, true);
    }
}
