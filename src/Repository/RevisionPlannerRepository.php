<?php

namespace App\Repository;

use App\Entity\RevisionPlanner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RevisionPlanner>
 */
class RevisionPlannerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RevisionPlanner::class);
    }

    /**
     * @return array<int, RevisionPlanner>
     */
    public function findRecentForStudent(User $student, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.student = :student')
            ->setParameter('student', $student)
            ->orderBy('p.updatedAt', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}

