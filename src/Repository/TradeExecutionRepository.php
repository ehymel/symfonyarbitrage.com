<?php

namespace App\Repository;

use App\Entity\TradeExecution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeExecution>
 *
 * @method TradeExecution|null find($id, $lockMode = null, $lockVersion = null)
 * @method TradeExecution|null findOneBy(array $criteria, array $orderBy = null)
 * @method TradeExecution[]    findAll()
 * @method TradeExecution[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TradeExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeExecution::class);
    }

    public function save(TradeExecution $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(TradeExecution $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
