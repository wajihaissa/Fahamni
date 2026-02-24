<?php

namespace App\Repository;

use App\Entity\Blog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Blog>
 */
class BlogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Blog::class);
    }

    /**
     * Articles du tuteur dont le statut a change (accepte/rejete) et pas encore lu
     * @return Blog[]
     */
    public function findStatusNotifications(User $user, bool $onlyUnread = false): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.publisher = :user')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['published', 'rejected'])
            ->orderBy('b.createdAt', 'DESC');

        if ($onlyUnread) {
            $qb->andWhere('b.isStatusNotifRead = false');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Nombre d'articles avec notification de statut non lue
     */
    public function countStatusNotifications(User $user): int
    {
        return $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.publisher = :user')
            ->andWhere('b.isStatusNotifRead = false')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['published', 'rejected'])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
