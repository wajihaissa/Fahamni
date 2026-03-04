<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Conversations où l'utilisateur participe.
     *
     * @param bool $includeArchived Inclure les conversations archivées (ignoré si $archivedOnly = true)
     * @param bool $includeDeleted Inclure les conversations supprimées (isDeleted) — toujours false en liste pour ne pas les afficher
     * @param string|null $searchTerm Filtre optionnel (titre, nom/email de l'autre participant, contenu du dernier message)
     * @param bool $archivedOnly Si true, ne retourner que les conversations archivées
     * @return Conversation[]
     */
    public function findByParticipant(User $user, bool $includeArchived = false, bool $includeDeleted = false, ?string $searchTerm = null, bool $archivedOnly = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'p')
            ->where('p.id = :userId')
            ->setParameter('userId', $user->getId())
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC');

        if (!$includeDeleted) {
            $qb->andWhere('c.isDeleted = false OR c.isDeleted IS NULL');
        }
        if ($archivedOnly) {
            $qb->andWhere('c.isArchived = true');
        } elseif (!$includeArchived) {
            $qb->andWhere('c.isArchived = false');
        }

        if ($searchTerm !== null && $searchTerm !== '') {
            $term = '%' . addcslashes(trim($searchTerm), '%_') . '%';
            $qb->leftJoin('c.participants', 'pOther', 'WITH', 'pOther.id != :userId')
                ->leftJoin('c.messages', 'msg', 'WITH', 'msg.deletedAt IS NULL AND msg.content LIKE :searchTerm')
                ->andWhere(
                    'c.title LIKE :searchTerm OR pOther.FullName LIKE :searchTerm OR pOther.email LIKE :searchTerm OR msg.id IS NOT NULL'
                )
                ->setParameter('searchTerm', $term)
                ->groupBy('c.id');
        }

        return $qb->getQuery()->getResult();
    }

    public function findPrivateConversationBetween(User $user1, User $user2): ?Conversation
    {
        $id1 = $user1->getId();
        $id2 = $user2->getId();
        if ($id1 === $id2) {
            return null;
        }
        return $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'p1')
            ->innerJoin('c.participants', 'p2')
            ->where('c.isGroup = false')
            ->andWhere('p1.id = :id1')->andWhere('p2.id = :id2')
            ->setParameter('id1', $id1)->setParameter('id2', $id2)
            ->andWhere('c.isDeleted = false OR c.isDeleted IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByParticipant(int $id, User $user, bool $includeArchived = true): ?Conversation
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'p')
            ->where('c.id = :id')
            ->andWhere('p.id = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $user->getId())
            ->andWhere('c.isDeleted = false OR c.isDeleted IS NULL');
        if (!$includeArchived) {
            $qb->andWhere('c.isArchived = false');
        }
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Toutes les conversations pour l'admin, triées par dernier message.
     *
     * @return Conversation[]
     */
    public function findAllOrderedByLastMessage(): array
    {
        return $this->getQueryOrderedByLastMessage()->getResult();
    }

    /**
     * QueryBuilder des conversations pour l'admin (pour pagination KnpPaginator).
     */
    public function getQueryOrderedByLastMessage(): \Doctrine\ORM\Query
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->getQuery();
    }

    /**
     * Nombre de conversations créées par jour sur les N derniers jours.
     *
     * @return array<string, int> [ 'Y-m-d' => count, ... ]
     */
    public function countByDay(int $days = 30): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM conversation WHERE created_at >= :since GROUP BY DATE(created_at) ORDER BY day ASC';
        $since = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d 00:00:00');
        $result = $conn->executeQuery($sql, ['since' => $since]);
        $rows = $result->fetchAllAssociative();
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['cnt'];
        }
        return $byDay;
    }

    /**
     * Nombre de conversations de type groupe vs privées (pour graphique type doughnut).
     *
     * @return array{group: int, private: int}
     */
    public function countGroupVsPrivate(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $group = $conn->executeQuery('SELECT COUNT(*) FROM conversation WHERE is_group = 1')->fetchOne();
        $private = $conn->executeQuery('SELECT COUNT(*) FROM conversation WHERE is_group = 0 OR is_group IS NULL')->fetchOne();
        return ['group' => (int) $group, 'private' => (int) $private];
    }
}
