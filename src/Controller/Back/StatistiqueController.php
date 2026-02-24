<?php

namespace App\Controller\Back;

use App\Entity\PaymentTransaction;
use App\Entity\RatingTutor;
use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/statistique', name: 'admin_statistique_')]
final class StatistiqueController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $periodDays = $this->resolvePeriodDays((string) $request->query->get('period', '90'));
        $now = new \DateTimeImmutable('now');
        $periodStart = $now->setTime(0, 0)->modify(sprintf('-%d days', $periodDays));

        $reservationRepository = $entityManager->getRepository(Reservation::class);
        $seanceRepository = $entityManager->getRepository(Seance::class);
        $paymentRepository = $entityManager->getRepository(PaymentTransaction::class);
        $ratingRepository = $entityManager->getRepository(RatingTutor::class);
        $userRepository = $entityManager->getRepository(User::class);

        $totalUsers = (int) $userRepository->count([]);

        $totalSeances = (int) $seanceRepository->count([]);
        $activeSeances = (int) $seanceRepository->count(['status' => 1]);
        $draftSeances = (int) $seanceRepository->count(['status' => 0]);
        $upcomingSeances = (int) $entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Seance::class, 's')
            ->andWhere('s.startAt >= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();

        $totalReservations = (int) $reservationRepository->count([]);
        $pendingReservations = (int) $reservationRepository->count(['status' => Reservation::STATUS_PENDING]);
        $acceptedReservations = (int) $reservationRepository->count(['status' => Reservation::STATUS_ACCEPTED]);
        $paidReservations = (int) $reservationRepository->count(['status' => Reservation::STATUS_PAID]);
        $acceptedOrPaidReservations = $acceptedReservations + $paidReservations;
        $canceledReservations = (int) $entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Reservation::class, 'r')
            ->andWhere('r.cancellAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
        $reservationsInPeriod = (int) $entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Reservation::class, 'r')
            ->andWhere('r.reservedAt >= :start')
            ->setParameter('start', $periodStart)
            ->getQuery()
            ->getSingleScalarResult();

        $reservationConversionRate = $totalReservations > 0
            ? round(($acceptedOrPaidReservations / $totalReservations) * 100, 1)
            : 0.0;

        $totalTransactions = (int) $paymentRepository->count([]);
        $paidTransactionsCount = (int) $paymentRepository->count(['status' => PaymentTransaction::STATUS_PAID]);
        $paymentSuccessRate = $totalTransactions > 0
            ? round(($paidTransactionsCount / $totalTransactions) * 100, 1)
            : 0.0;

        $currency = $this->resolveMainCurrency($entityManager);
        $totalRevenueCents = (int) $entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(pt.amountCents), 0)')
            ->from(PaymentTransaction::class, 'pt')
            ->andWhere('pt.status = :paidStatus')
            ->setParameter('paidStatus', PaymentTransaction::STATUS_PAID)
            ->getQuery()
            ->getSingleScalarResult();
        $periodRevenueCents = (int) $entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(pt.amountCents), 0)')
            ->from(PaymentTransaction::class, 'pt')
            ->andWhere('pt.status = :paidStatus')
            ->andWhere('pt.paidAt IS NOT NULL')
            ->andWhere('pt.paidAt >= :start')
            ->setParameter('paidStatus', PaymentTransaction::STATUS_PAID)
            ->setParameter('start', $periodStart)
            ->getQuery()
            ->getSingleScalarResult();
        $averageTicketCents = $paidTransactionsCount > 0
            ? (int) round($totalRevenueCents / $paidTransactionsCount)
            : 0;

        $totalRatings = (int) $ratingRepository->count([]);
        $ratingsInPeriodCount = (int) $entityManager->createQueryBuilder()
            ->select('COUNT(rt.id)')
            ->from(RatingTutor::class, 'rt')
            ->andWhere('rt.createdAt >= :start')
            ->setParameter('start', $periodStart)
            ->getQuery()
            ->getSingleScalarResult();
        $avgGlobalRatingRaw = (string) $entityManager->createQueryBuilder()
            ->select('COALESCE(AVG(rt.note), 0)')
            ->from(RatingTutor::class, 'rt')
            ->getQuery()
            ->getSingleScalarResult();
        $avgGlobalRating = round((float) $avgGlobalRatingRaw, 2);

        $monthlyBuckets = $this->createMonthlyBuckets($now, 6);
        $monthKeys = array_keys($monthlyBuckets);
        $firstMonthKey = $monthKeys[0] ?? $now->format('Y-m');
        $monthlyStart = new \DateTimeImmutable($firstMonthKey . '-01 00:00:00');

        $reservationsForTimeline = $reservationRepository->createQueryBuilder('r')
            ->andWhere('r.reservedAt >= :start')
            ->setParameter('start', $monthlyStart)
            ->getQuery()
            ->getResult();

        foreach ($reservationsForTimeline as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }

            $reservedAt = $reservation->getReservedAt();
            if (!$reservedAt instanceof \DateTimeImmutable) {
                continue;
            }

            $key = $reservedAt->format('Y-m');
            if (!isset($monthlyBuckets[$key])) {
                continue;
            }

            $monthlyBuckets[$key]['reservations']++;
            $status = (int) ($reservation->getStatus() ?? Reservation::STATUS_PENDING);
            if (in_array($status, [Reservation::STATUS_ACCEPTED, Reservation::STATUS_PAID], true)) {
                $monthlyBuckets[$key]['acceptedPaid']++;
            }
            if ($status === Reservation::STATUS_PAID) {
                $monthlyBuckets[$key]['paid']++;
            }
            if ($reservation->getCancellAt() instanceof \DateTimeImmutable) {
                $monthlyBuckets[$key]['canceled']++;
            }
        }

        $paymentsForTimeline = $paymentRepository->createQueryBuilder('pt')
            ->andWhere('pt.status = :paidStatus')
            ->andWhere('pt.paidAt IS NOT NULL')
            ->andWhere('pt.paidAt >= :start')
            ->setParameter('paidStatus', PaymentTransaction::STATUS_PAID)
            ->setParameter('start', $monthlyStart)
            ->getQuery()
            ->getResult();

        foreach ($paymentsForTimeline as $payment) {
            if (!$payment instanceof PaymentTransaction) {
                continue;
            }

            $paidAt = $payment->getPaidAt();
            if (!$paidAt instanceof \DateTimeImmutable) {
                continue;
            }

            $key = $paidAt->format('Y-m');
            if (!isset($monthlyBuckets[$key])) {
                continue;
            }

            $monthlyBuckets[$key]['revenueCents'] += (int) ($payment->getAmountCents() ?? 0);
        }

        $monthlyRows = array_values($monthlyBuckets);
        $maxMonthlyReservations = max(1, ...array_map(
            static fn(array $row): int => (int) ($row['reservations'] ?? 0),
            $monthlyRows
        ));
        $maxMonthlyRevenueCents = max(1, ...array_map(
            static fn(array $row): int => (int) ($row['revenueCents'] ?? 0),
            $monthlyRows
        ));

        foreach ($monthlyRows as &$row) {
            $reservationsCount = (int) ($row['reservations'] ?? 0);
            $revenueCents = (int) ($row['revenueCents'] ?? 0);
            $acceptedPaidCount = (int) ($row['acceptedPaid'] ?? 0);

            $row['reservationsPct'] = (int) round(($reservationsCount / $maxMonthlyReservations) * 100);
            $row['revenuePct'] = (int) round(($revenueCents / $maxMonthlyRevenueCents) * 100);
            $row['revenueFormatted'] = $this->formatMoney($revenueCents, $currency);
            $row['acceptanceRate'] = $reservationsCount > 0
                ? round(($acceptedPaidCount / $reservationsCount) * 100, 1)
                : 0.0;
        }
        unset($row);

        $reservationDistribution = [
            [
                'label' => 'En attente',
                'count' => $pendingReservations,
                'key' => 'pending',
            ],
            [
                'label' => 'Acceptees',
                'count' => $acceptedReservations,
                'key' => 'accepted',
            ],
            [
                'label' => 'Payees',
                'count' => $paidReservations,
                'key' => 'paid',
            ],
            [
                'label' => 'Annulees',
                'count' => $canceledReservations,
                'key' => 'canceled',
            ],
        ];
        foreach ($reservationDistribution as &$distributionItem) {
            $distributionItem['pct'] = $totalReservations > 0
                ? round((((int) $distributionItem['count']) / $totalReservations) * 100, 1)
                : 0.0;
        }
        unset($distributionItem);

        $topSubjectRows = $entityManager->createQueryBuilder()
            ->select('COALESCE(s.matiere, :fallbackSubject) AS subject')
            ->addSelect('COUNT(r.id) AS reservationsCount')
            ->addSelect('SUM(CASE WHEN r.status = :paidStatus THEN 1 ELSE 0 END) AS paidCount')
            ->addSelect('SUM(CASE WHEN r.status = :acceptedStatus OR r.status = :paidStatus THEN 1 ELSE 0 END) AS acceptedPaidCount')
            ->from(Reservation::class, 'r')
            ->innerJoin('r.seance', 's')
            ->groupBy('s.matiere')
            ->orderBy('reservationsCount', 'DESC')
            ->setMaxResults(6)
            ->setParameter('fallbackSubject', 'Non renseignee')
            ->setParameter('acceptedStatus', Reservation::STATUS_ACCEPTED)
            ->setParameter('paidStatus', Reservation::STATUS_PAID)
            ->getQuery()
            ->getArrayResult();

        $topSubjects = [];
        foreach ($topSubjectRows as $row) {
            $reservationsCount = (int) ($row['reservationsCount'] ?? 0);
            $paidCount = (int) ($row['paidCount'] ?? 0);
            $acceptedPaidCount = (int) ($row['acceptedPaidCount'] ?? 0);

            $topSubjects[] = [
                'subject' => trim((string) ($row['subject'] ?? 'Non renseignee')),
                'reservationsCount' => $reservationsCount,
                'paidCount' => $paidCount,
                'acceptedPaidCount' => $acceptedPaidCount,
                'paymentRate' => $reservationsCount > 0 ? round(($paidCount / $reservationsCount) * 100, 1) : 0.0,
            ];
        }

        $topTutorRows = $entityManager->createQueryBuilder()
            ->select('IDENTITY(s.tuteur) AS tutorId')
            ->addSelect('COUNT(r.id) AS reservationsCount')
            ->addSelect('SUM(CASE WHEN r.status = :paidStatus THEN 1 ELSE 0 END) AS paidCount')
            ->from(Reservation::class, 'r')
            ->innerJoin('r.seance', 's')
            ->groupBy('s.tuteur')
            ->orderBy('reservationsCount', 'DESC')
            ->setMaxResults(8)
            ->setParameter('paidStatus', Reservation::STATUS_PAID)
            ->getQuery()
            ->getArrayResult();

        $tutorIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['tutorId'] ?? 0),
            $topTutorRows
        )));

        $tutorNameById = [];
        if ($tutorIds !== []) {
            $tutors = $userRepository->findBy(['id' => $tutorIds]);
            foreach ($tutors as $tutor) {
                if (!$tutor instanceof User || $tutor->getId() === null) {
                    continue;
                }
                $tutorNameById[(int) $tutor->getId()] = (string) ($tutor->getFullName() ?? $tutor->getEmail() ?? ('User #' . $tutor->getId()));
            }
        }

        $tutorRatings = [];
        if ($tutorIds !== []) {
            $ratingRows = $entityManager->createQueryBuilder()
                ->select('IDENTITY(rt.tuteur) AS tutorId')
                ->addSelect('AVG(rt.note) AS avgRating')
                ->addSelect('COUNT(rt.id) AS ratingsCount')
                ->from(RatingTutor::class, 'rt')
                ->andWhere('rt.tuteur IN (:tutorIds)')
                ->setParameter('tutorIds', $tutorIds)
                ->groupBy('rt.tuteur')
                ->getQuery()
                ->getArrayResult();

            foreach ($ratingRows as $ratingRow) {
                $tutorId = (int) ($ratingRow['tutorId'] ?? 0);
                if ($tutorId <= 0) {
                    continue;
                }

                $tutorRatings[$tutorId] = [
                    'avgRating' => round((float) ($ratingRow['avgRating'] ?? 0), 2),
                    'ratingsCount' => (int) ($ratingRow['ratingsCount'] ?? 0),
                ];
            }
        }

        $tutorSeancesCount = [];
        if ($tutorIds !== []) {
            $seanceRows = $entityManager->createQueryBuilder()
                ->select('IDENTITY(s.tuteur) AS tutorId')
                ->addSelect('COUNT(s.id) AS seancesCount')
                ->from(Seance::class, 's')
                ->andWhere('s.tuteur IN (:tutorIds)')
                ->setParameter('tutorIds', $tutorIds)
                ->groupBy('s.tuteur')
                ->getQuery()
                ->getArrayResult();

            foreach ($seanceRows as $seanceRow) {
                $tutorId = (int) ($seanceRow['tutorId'] ?? 0);
                if ($tutorId <= 0) {
                    continue;
                }

                $tutorSeancesCount[$tutorId] = (int) ($seanceRow['seancesCount'] ?? 0);
            }
        }

        $topTutors = [];
        foreach ($topTutorRows as $row) {
            $tutorId = (int) ($row['tutorId'] ?? 0);
            if ($tutorId <= 0) {
                continue;
            }

            $reservationsCount = (int) ($row['reservationsCount'] ?? 0);
            $paidCount = (int) ($row['paidCount'] ?? 0);
            $ratingData = $tutorRatings[$tutorId] ?? ['avgRating' => 0.0, 'ratingsCount' => 0];

            $topTutors[] = [
                'id' => $tutorId,
                'name' => $tutorNameById[$tutorId] ?? ('Tuteur #' . $tutorId),
                'reservationsCount' => $reservationsCount,
                'paidCount' => $paidCount,
                'conversionRate' => $reservationsCount > 0 ? round(($paidCount / $reservationsCount) * 100, 1) : 0.0,
                'avgRating' => (float) ($ratingData['avgRating'] ?? 0.0),
                'ratingsCount' => (int) ($ratingData['ratingsCount'] ?? 0),
                'seancesCount' => (int) ($tutorSeancesCount[$tutorId] ?? 0),
            ];
        }

        return $this->render('back/statistique/index.html.twig', [
            'periodDays' => $periodDays,
            'periodStart' => $periodStart,
            'availablePeriods' => [30, 90, 180, 365],
            'currency' => strtoupper($currency),

            'totalUsers' => $totalUsers,
            'totalSeances' => $totalSeances,
            'activeSeances' => $activeSeances,
            'draftSeances' => $draftSeances,
            'upcomingSeances' => $upcomingSeances,

            'totalReservations' => $totalReservations,
            'pendingReservations' => $pendingReservations,
            'acceptedReservations' => $acceptedReservations,
            'paidReservations' => $paidReservations,
            'acceptedOrPaidReservations' => $acceptedOrPaidReservations,
            'canceledReservations' => $canceledReservations,
            'reservationsInPeriod' => $reservationsInPeriod,
            'reservationConversionRate' => $reservationConversionRate,

            'totalTransactions' => $totalTransactions,
            'paidTransactionsCount' => $paidTransactionsCount,
            'paymentSuccessRate' => $paymentSuccessRate,
            'totalRevenueCents' => $totalRevenueCents,
            'periodRevenueCents' => $periodRevenueCents,
            'averageTicketCents' => $averageTicketCents,
            'totalRevenueFormatted' => $this->formatMoney($totalRevenueCents, $currency),
            'periodRevenueFormatted' => $this->formatMoney($periodRevenueCents, $currency),
            'averageTicketFormatted' => $this->formatMoney($averageTicketCents, $currency),

            'totalRatings' => $totalRatings,
            'ratingsInPeriodCount' => $ratingsInPeriodCount,
            'avgGlobalRating' => $avgGlobalRating,

            'monthlyRows' => $monthlyRows,
            'reservationDistribution' => $reservationDistribution,
            'topSubjects' => $topSubjects,
            'topTutors' => $topTutors,
        ]);
    }

    private function resolvePeriodDays(string $value): int
    {
        $allowed = [30, 90, 180, 365];
        $days = (int) trim($value);

        return in_array($days, $allowed, true) ? $days : 90;
    }

    /**
     * @return array<string, array<string, int|string|float>>
     */
    private function createMonthlyBuckets(\DateTimeImmutable $now, int $months): array
    {
        $months = max(1, $months);
        $startMonth = $now->setDate(
            (int) $now->format('Y'),
            (int) $now->format('m'),
            1
        )->setTime(0, 0)->modify(sprintf('-%d months', $months - 1));

        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $monthDate = $startMonth->modify(sprintf('+%d months', $i));
            $key = $monthDate->format('Y-m');
            $buckets[$key] = [
                'key' => $key,
                'label' => $monthDate->format('M Y'),
                'reservations' => 0,
                'acceptedPaid' => 0,
                'paid' => 0,
                'canceled' => 0,
                'revenueCents' => 0,
            ];
        }

        return $buckets;
    }

    private function resolveMainCurrency(EntityManagerInterface $entityManager): string
    {
        $rows = $entityManager->createQueryBuilder()
            ->select('pt.currency AS currencyCode')
            ->addSelect('COUNT(pt.id) AS txCount')
            ->from(PaymentTransaction::class, 'pt')
            ->andWhere('pt.currency IS NOT NULL')
            ->groupBy('pt.currency')
            ->orderBy('txCount', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        $currency = strtolower((string) ($rows[0]['currencyCode'] ?? 'tnd'));

        if ($currency === '' || $currency === 'dtn') {
            return 'tnd';
        }

        return $currency;
    }

    private function formatMoney(int $amountCents, string $currency): string
    {
        $amount = $amountCents / 100;

        return number_format($amount, 2, ',', ' ') . ' ' . strtoupper($currency);
    }
}
