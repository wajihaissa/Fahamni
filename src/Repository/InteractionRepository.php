<?php

namespace App\Repository;

use App\Entity\Interaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Interaction>
 */
class InteractionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Interaction::class);
    }

    /**
     * Compte le nombre de likes pour un article
     */
    public function countLikes($blogId): int
    {
        return $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.blog = :blogId')
            ->andWhere('i.reaction = 1')
            ->setParameter('blogId', $blogId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère tous les commentaires d'un article
     * @return Interaction[] Returns an array of Interaction objects
     */
    public function findCommentsByBlog($blogId): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.blog = :blogId')
            ->andWhere('i.comment IS NOT NULL')
            ->andWhere('i.isFlagged = :notFlagged')
            ->andWhere('i.isDeletedByAdmin = :notArchived')
            ->setParameter('blogId', $blogId)
            ->setParameter('notFlagged', false)
            ->setParameter('notArchived', false)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre de commentaires pour un article
     */
    public function countComments($blogId): int
    {
        return $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.blog = :blogId')
            ->andWhere('i.comment IS NOT NULL')
            ->andWhere('i.isFlagged = :notFlagged')
            ->andWhere('i.isDeletedByAdmin = :notArchived')
            ->setParameter('blogId', $blogId)
            ->setParameter('notFlagged', false)
            ->setParameter('notArchived', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Notifications non lues pour un utilisateur (interactions sur ses articles par d'autres)
     * @return Interaction[]
     */
    public function findUnreadNotifications(User $user, bool $onlyUnread = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.blog', 'b')
            ->where('b.publisher = :user')
            ->andWhere('i.innteractor != :user')
            ->andWhere('i.isFlagged = :notFlagged')
            ->andWhere('i.isDeletedByAdmin = :notArchived')
            ->setParameter('user', $user)
            ->setParameter('notFlagged', false)
            ->setParameter('notArchived', false)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(50);

        if ($onlyUnread) {
            $qb->andWhere('i.isNotifRead = :notRead')
               ->setParameter('notRead', false);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Nombre de notifications non lues
     */
    public function countUnreadNotifications(User $user): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->join('i.blog', 'b')
            ->where('b.publisher = :user')
            ->andWhere('i.innteractor != :user')
            ->andWhere('i.isNotifRead = :notRead')
            ->andWhere('i.isFlagged = :notFlagged')
            ->andWhere('i.isDeletedByAdmin = :notArchived')
            ->setParameter('user', $user)
            ->setParameter('notRead', false)
            ->setParameter('notFlagged', false)
            ->setParameter('notArchived', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Commentaires signales en attente de modération (non archivés)
     * @return Interaction[]
     */
    public function findFlaggedComments(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.isFlagged = :flagged')
            ->andWhere('i.comment IS NOT NULL')
            ->andWhere('i.isDeletedByAdmin = :notArchived')
            ->setParameter('flagged', true)
            ->setParameter('notArchived', false)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de commentaires signales en attente (non archivés)
     */
    public function countFlaggedComments(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.isFlagged = :flagged')
            ->andWhere('i.comment IS NOT NULL')
            ->andWhere('i.isDeletedByAdmin = :notArchived')
            ->setParameter('flagged', true)
            ->setParameter('notArchived', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Commentaires archivés comme preuve (supprimés par l'admin mais conservés)
     * @return Interaction[]
     */
    public function findArchivedComments(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.isFlagged = :flagged')
            ->andWhere('i.comment IS NOT NULL')
            ->andWhere('i.isDeletedByAdmin = :archived')
            ->setParameter('flagged', true)
            ->setParameter('archived', true)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
