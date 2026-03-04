<?php

namespace App\Repository;

use App\Entity\Conversation;
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
     * Signalements non lus (pour les notifications admin).
     *
     * @return ConversationReport[]
     */
    public function findUnreadOrderedByCreatedAt(): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.conversation', 'c')
            ->innerJoin('r.reportedBy', 'u')
            ->addSelect('c', 'u')
            ->where('r.readAt IS NULL')
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

    public function findOneByConversationAndReportedBy(Conversation $conversation, User $user): ?ConversationReport
    {
        return $this->createQueryBuilder('r')
            ->where('r.conversation = :conversation')
            ->andWhere('r.reportedBy = :user')
            ->setParameter('conversation', $conversation)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Nombre de signalements de conversations par jour sur les N derniers jours.
     *
     * @return array<string, int> [ 'Y-m-d' => count, ... ]
     */
    public function countByDay(int $days = 30): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM conversation_report WHERE created_at >= :since GROUP BY DATE(created_at) ORDER BY day ASC';
        $since = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d 00:00:00');
        $result = $conn->executeQuery($sql, ['since' => $since]);
        $rows = $result->fetchAllAssociative();
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['cnt'];
        }
        return $byDay;
    }
}
