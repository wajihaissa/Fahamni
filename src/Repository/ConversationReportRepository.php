<?php

namespace App\Repository;

use App\Entity\ConversationReport;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConversationReport>
 */
class ConversationReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationReport::class);
    }

    /**
     * @return ConversationReport[]
     */
    public function findAllOrderedByCreatedAt(): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.conversation', 'c')
            ->innerJoin('r.reportedBy', 'u')
            ->addSelect('c', 'u')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ConversationReport[]
     */
    public function findByReportedBy(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.conversation', 'c')
            ->addSelect('c')
            ->where('r.reportedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
