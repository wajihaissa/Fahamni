<?php

namespace App\Repository;

use App\Entity\Matiere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Matiere>
 */
class MatiereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Matiere::class);
    }

    //    /**
    //     * @return Matiere[] Returns an array of Matiere objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('m')
    //            ->andWhere('m.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('m.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Matiere
    //    {
    //        return $this->createQueryBuilder('m')
    //            ->andWhere('m.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
  
    /**
     * Finds subjects that belong to a specific category
     */
    public function findByCategory($category)
    {
        return $this->createQueryBuilder('m')
            ->join('m.categories', 'c') // 'categories' must match the property name in Matiere.php
            ->andWhere('c.id = :id')
            ->setParameter('id', $category->getId())
            ->orderBy('m.id', 'DESC') // Optional: Newest first
            ->getQuery()
            ->getResult();
    }

    /**
     * Search by term (title/desc) and optionally filter by category
     */
    public function search(string $term = null, $category = null)
    {
        $qb = $this->createQueryBuilder('m')
            ->orderBy('m.id', 'DESC');

        // 1. If a search term exists
        if ($term) {
            $qb->andWhere('m.titre LIKE :term OR m.description LIKE :term')
               ->setParameter('term', '%' . $term . '%');
        }

        // 2. If a category filter is active
        if ($category) {
            $qb->join('m.categories', 'c')
               ->andWhere('c.id = :catId')
               ->setParameter('catId', $category->getId());
        }

        return $qb->getQuery()->getResult();
    }

}
