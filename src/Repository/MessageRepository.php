<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * @return Message[]
     */
    public function findByConversation(Conversation $conversation, bool $excludeDeleted = true): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.conversation = :conv')
            ->setParameter('conv', $conversation)
            ->orderBy('m.createdAt', 'ASC')->addOrderBy('m.id', 'ASC');
        if ($excludeDeleted) {
            $qb->andWhere('m.deletedAt IS NULL');
        }
        return $qb->getQuery()->getResult();
    }

    public function findOneByConversationAndId(Conversation $conversation, int $messageId): ?Message
    {
        return $this->createQueryBuilder('m')
            ->where('m.conversation = :conv')->andWhere('m.id = :messageId')
            ->setParameter('conv', $conversation)->setParameter('messageId', $messageId)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Recherche de messages dans une conversation (contenu texte).
     *
     * @return Message[]
     */
    public function searchInConversation(Conversation $conversation, string $query, int $limit = 30): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }
        $qb = $this->createQueryBuilder('m')
            ->where('m.conversation = :conv')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.content LIKE :term')
            ->setParameter('conv', $conversation)
            ->setParameter('term', '%' . addcslashes($term, '%_') . '%')
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit);
        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<string, int> Map YYYY-MM-DD => count
     */
    public function countByDay(int $days = 30): array
    {
        $since = (new \DateTimeImmutable())->modify('-' . max(1, $days) . ' days')->format('Y-m-d 00:00:00');
        $sql = 'SELECT DATE(created_at) AS day, COUNT(*) AS cnt
                FROM message
                WHERE created_at >= :since
                  AND (deleted_at IS NULL)
                GROUP BY DATE(created_at)
                ORDER BY day ASC';

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, ['since' => $since]);
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['cnt'];
        }

        return $byDay;
    }
}
