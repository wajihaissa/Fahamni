<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\MessageReport;
use App\Entity\User;
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
            ->leftJoin('m.attachments', 'a')
            ->addSelect('c', 's', 'a')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Signalements non lus (pour les notifications admin).
     *
     * @return MessageReport[]
     */
    public function findUnreadOrderedByCreatedAt(): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.message', 'm')
            ->innerJoin('r.reportedBy', 'u')
            ->addSelect('m', 'u')
            ->leftJoin('m.conversation', 'c')
            ->leftJoin('m.sender', 's')
            ->addSelect('c', 's')
            ->where('r.readAt IS NULL')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MessageReport[]
     */
    public function findByReportedBy(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.message', 'm')
            ->addSelect('m')
            ->leftJoin('m.conversation', 'c')
            ->addSelect('c')
            ->where('r.reportedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByMessageAndReportedBy(Message $message, User $user): ?MessageReport
    {
        return $this->createQueryBuilder('r')
            ->where('r.message = :message')
            ->andWhere('r.reportedBy = :user')
            ->setParameter('message', $message)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * IDs des messages déjà signalés par l'utilisateur dans cette conversation (pour l'affichage).
     *
     * @return int[]
     */
    public function findReportedMessageIdsByUserInConversation(User $user, Conversation $conversation): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.message) AS messageId')
            ->innerJoin('r.message', 'm')
            ->where('r.reportedBy = :user')
            ->andWhere('m.conversation = :conversation')
            ->setParameter('user', $user)
            ->setParameter('conversation', $conversation)
            ->getQuery()
            ->getSingleColumnResult();
        return array_map('intval', $result);
    }

    /**
     * Nombre de signalements de messages par jour sur les N derniers jours.
     *
     * @return array<string, int> [ 'Y-m-d' => count, ... ]
     */
    public function countByDay(int $days = 30): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM message_report WHERE created_at >= :since GROUP BY DATE(created_at) ORDER BY day ASC';
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
