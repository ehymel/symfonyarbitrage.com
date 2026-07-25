<?php

namespace App\Repository;

use App\Entity\ArbitrageOpportunity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArbitrageOpportunity>
 *
 * @method ArbitrageOpportunity|null find($id, $lockMode = null, $lockVersion = null)
 * @method ArbitrageOpportunity|null findOneBy(array $criteria, array $orderBy = null)
 * @method ArbitrageOpportunity[]    findAll()
 * @method ArbitrageOpportunity[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ArbitrageOpportunityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArbitrageOpportunity::class);
    }

    public function save(ArbitrageOpportunity $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ArbitrageOpportunity $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
