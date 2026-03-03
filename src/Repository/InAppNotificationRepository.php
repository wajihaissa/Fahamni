<?php

namespace App\Repository;

use App\Entity\InAppNotification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InAppNotification>
 */
class InAppNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InAppNotification::class);
    }

    /**
     * @return array<int, InAppNotification>
     */
    public function findLatestForRecipient(User $recipient, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->setParameter('recipient', $recipient)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countUnreadForRecipient(User $recipient): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('recipient', $recipient)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByRecipientAndEventKey(User $recipient, string $eventKey): ?InAppNotification
    {
        return $this->findOneBy([
            'recipient' => $recipient,
            'eventKey' => $eventKey,
        ]);
    }

    public function markAllAsReadForRecipient(User $recipient, \DateTimeImmutable $readAt): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', ':isRead')
            ->set('n.readAt', ':readAt')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.isRead = :currentlyUnread')
            ->setParameter('isRead', true)
            ->setParameter('readAt', $readAt)
            ->setParameter('recipient', $recipient)
            ->setParameter('currentlyUnread', false)
            ->getQuery()
            ->execute();
    }
}
