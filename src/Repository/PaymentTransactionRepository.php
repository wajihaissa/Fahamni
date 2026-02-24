<?php

namespace App\Repository;

use App\Entity\PaymentTransaction;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentTransaction>
 */
class PaymentTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentTransaction::class);
    }

    public function findLatestPendingByReservation(Reservation $reservation): ?PaymentTransaction
    {
        return $this->createQueryBuilder('pt')
            ->andWhere('pt.reservation = :reservation')
            ->andWhere('pt.status = :status')
            ->setParameter('reservation', $reservation)
            ->setParameter('status', PaymentTransaction::STATUS_PENDING)
            ->orderBy('pt.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestByReservation(Reservation $reservation): ?PaymentTransaction
    {
        return $this->createQueryBuilder('pt')
            ->andWhere('pt.reservation = :reservation')
            ->setParameter('reservation', $reservation)
            ->orderBy('pt.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
