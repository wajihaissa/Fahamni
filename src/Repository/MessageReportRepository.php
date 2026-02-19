<?php

namespace App\Repository;

use App\Entity\MessageReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageReport>
 */
class MessageReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageReport::class);
    }

    /**
     * @return MessageReport[]
     */
    public function findAllOrderedByCreatedAt(): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.message', 'm')
            ->innerJoin('r.reportedBy', 'u')
            ->addSelect('m', 'u')
            ->leftJoin('m.conversation', 'c')
            ->leftJoin('m.sender', 's')
            ->addSelect('c', 's')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
