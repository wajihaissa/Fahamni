<?php

namespace App\Repository;

use App\Entity\QuizResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizResult>
 */
class QuizResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizResult::class);
    }

    /**
     * @return array<int, array{
     *     userId:int,
     *     fullName:string,
     *     email:string,
     *     totalScore:int,
     *     averagePercentage:float,
     *     attempts:int,
     *     passedCount:int,
     *     lastCompletedAt:\DateTimeInterface|null
     * }>
     */
    public function findLeaderboard(int $limit = 10, ?int $quizId = null): array
    {
        $qb = $this->createQueryBuilder('result')
            ->select('user.id AS userId')
            ->addSelect('user.FullName AS fullName')
            ->addSelect('user.email AS email')
            ->addSelect('SUM(result.score) AS totalScore')
            ->addSelect('AVG(result.percentage) AS averagePercentage')
            ->addSelect('COUNT(result.id) AS attempts')
            ->addSelect('SUM(CASE WHEN result.passed = true THEN 1 ELSE 0 END) AS passedCount')
            ->addSelect('MAX(result.completedAt) AS lastCompletedAt')
            ->innerJoin('result.user', 'user')
            ->groupBy('user.id, user.FullName, user.email')
            ->orderBy('totalScore', 'DESC')
            ->addOrderBy('averagePercentage', 'DESC')
            ->addOrderBy('attempts', 'DESC')
            ->addOrderBy('lastCompletedAt', 'DESC')
            ->setMaxResults($limit);

        if ($quizId !== null) {
            $qb
                ->innerJoin('result.quiz', 'quiz')
                ->andWhere('quiz.id = :quizId')
                ->setParameter('quizId', $quizId);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static function (array $row): array {
            $lastCompletedAt = $row['lastCompletedAt'] ?? null;
            if (is_string($lastCompletedAt) && $lastCompletedAt !== '') {
                try {
                    $lastCompletedAt = new \DateTimeImmutable($lastCompletedAt);
                } catch (\Exception) {
                    $lastCompletedAt = null;
                }
            }

            return [
                'userId' => (int) $row['userId'],
                'fullName' => (string) $row['fullName'],
                'email' => (string) $row['email'],
                'totalScore' => (int) $row['totalScore'],
                'averagePercentage' => round((float) $row['averagePercentage'], 2),
                'attempts' => (int) $row['attempts'],
                'passedCount' => (int) $row['passedCount'],
                'lastCompletedAt' => $lastCompletedAt instanceof \DateTimeInterface ? $lastCompletedAt : null,
            ];
        }, $rows);
    }

//    /**
//     * @return QuizResult[] Returns an array of QuizResult objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('q')
//            ->andWhere('q.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('q.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?QuizResult
//    {
//        return $this->createQueryBuilder('q')
//            ->andWhere('q.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
