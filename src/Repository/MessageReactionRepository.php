<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\MessageReaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageReaction>
 */
class MessageReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageReaction::class);
    }

    public function findOneByMessageAndReactor(Message $message, User $reactor): ?MessageReaction
    {
        return $this->createQueryBuilder('r')
            ->where('r.message = :message')
            ->andWhere('r.reactor = :reactor')
            ->setParameter('message', $message)
            ->setParameter('reactor', $reactor)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByMessageAndReactorAndEmoji(Message $message, User $reactor, int $emoji): ?MessageReaction
    {
        return $this->createQueryBuilder('r')
            ->where('r.message = :message')
            ->andWhere('r.reactor = :reactor')
            ->andWhere('r.emoji = :emoji')
            ->setParameter('message', $message)
            ->setParameter('reactor', $reactor)
            ->setParameter('emoji', $emoji)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
