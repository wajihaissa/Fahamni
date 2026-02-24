<?php

namespace App\Controller;

use App\Entity\RatingTutor;
use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\User;
use App\Repository\RatingTutorRepository;
use App\Repository\ReservationRepository;
use App\Repository\SeanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CalendarController extends AbstractController
{
    private const STATUS_PENDING = Reservation::STATUS_PENDING;
    private const STATUS_ACCEPTED = Reservation::STATUS_ACCEPTED;
    private const STATUS_PAID = Reservation::STATUS_PAID;

    #[Route('/calendar', name: 'app_calendar')]
    public function index(
        ReservationRepository $reservationRepository,
        SeanceRepository $seanceRepository,
        RatingTutorRepository $ratingTutorRepository
    ): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $calendarEvents = [];
        $user = $this->getUser();
        $now = new \DateTimeImmutable();
        $isStudent = $this->isGranted('ROLE_ETUDIANT');
        $isTutor = $this->isGranted('ROLE_TUTOR') || $this->isGranted('ROLE_TUTEUR');
        $canViewOwnReservations = $isStudent || $isTutor;
        $studentRatingsByTutorId = [];

        if ($user instanceof User) {
            if ($isStudent) {
                $ratings = $ratingTutorRepository->findBy(['etudiant' => $user]);

                foreach ($ratings as $rating) {
                    if (!$rating instanceof RatingTutor) {
                        continue;
                    }

                    $ratedTutorId = $rating->getTuteur()?->getId();

                    if ($ratedTutorId !== null) {
                        $studentRatingsByTutorId[$ratedTutorId] = $rating;
                    }
                }
            }

            if ($canViewOwnReservations) {
                $reservations = $reservationRepository->createQueryBuilder('r')
                    ->innerJoin('r.seance', 's')->addSelect('s')
                    ->leftJoin('s.tuteur', 't')->addSelect('t')
                    ->andWhere('r.participant = :participant')
                    ->setParameter('participant', $user)
                    ->orderBy('s.startAt', 'ASC')
                    ->getQuery()
                    ->getResult();

                foreach ($reservations as $reservation) {
                    if (!$reservation instanceof Reservation || !$reservation->getSeance()) {
                        continue;
                    }

                    $seance = $reservation->getSeance();
                    $startAt = $seance->getStartAt();

                    if (!$startAt) {
                        continue;
                    }

                    $reservationStatus = (int) ($reservation->getStatus() ?? self::STATUS_PENDING);
                    $isValidated = in_array($reservationStatus, [self::STATUS_ACCEPTED, self::STATUS_PAID], true);
                    $isPast = $startAt < $now;
                    $tuteur = $seance->getTuteur();
                    $tuteurId = $tuteur?->getId();
                    $existingRating = $isStudent && $tuteurId !== null
                        ? ($studentRatingsByTutorId[$tuteurId] ?? null)
                        : null;

                    $statusLabel = match ($reservationStatus) {
                        self::STATUS_PAID => 'Payee',
                        self::STATUS_ACCEPTED => 'Acceptee',
                        default => 'En attente',
                    };
                    $statusKey = match ($reservationStatus) {
                        self::STATUS_PAID => 'paid',
                        self::STATUS_ACCEPTED => 'accepted',
                        default => 'pending',
                    };

                    $calendarEvents[] = [
                        'date' => $startAt->format('Y-m-d'),
                        'time' => $startAt->format('H:i'),
                        'title' => $seance->getMatiere() ?? 'Seance',
                        'subtitle' => 'Tuteur: ' . ($tuteur?->getFullName() ?? 'N/A'),
                        'status' => $statusLabel,
                        'statusKey' => $statusKey,
                        'kindKey' => 'reservation',
                        'kindLabel' => 'Reservation',
                        'seanceId' => null,
                        'canMove' => false,
                        'tutorId' => $tuteurId,
                        'tutorName' => $tuteur?->getFullName(),
                        'canRate' => $isStudent && $isValidated && $isPast && $tuteurId !== null,
                        'ratingNote' => $existingRating instanceof RatingTutor ? $existingRating->getNote() : null,
                        'ratingComment' => $existingRating instanceof RatingTutor ? $existingRating->getCommentaire() : null,
                        'reviewSummary' => null,
                        'dateTime' => $startAt->format(DATE_ATOM),
                    ];
                }
            }

            if ($isTutor) {
                $seances = $seanceRepository->createQueryBuilder('s')
                    ->andWhere('s.tuteur = :tuteur')
                    ->setParameter('tuteur', $user)
                    ->orderBy('s.startAt', 'ASC')
                    ->getQuery()
                    ->getResult();

                $reviewSummaryBySeanceId = $this->buildReviewSummaryBySeance(
                    $seances,
                    $user,
                    $reservationRepository,
                    $ratingTutorRepository
                );

                foreach ($seances as $seance) {
                    if (!$seance instanceof Seance || !$seance->getStartAt()) {
                        continue;
                    }

                    $seanceId = $seance->getId();
                    $reviewSummary = $seanceId !== null
                        ? ($reviewSummaryBySeanceId[$seanceId] ?? $this->buildEmptyReviewSummary())
                        : $this->buildEmptyReviewSummary();

                    $calendarEvents[] = [
                        'date' => $seance->getStartAt()->format('Y-m-d'),
                        'time' => $seance->getStartAt()->format('H:i'),
                        'title' => $seance->getMatiere() ?? 'Seance',
                        'subtitle' => 'Votre seance',
                        'status' => 'Programmee',
                        'statusKey' => 'planned',
                        'kindKey' => 'session',
                        'kindLabel' => 'Seance publiee',
                        'seanceId' => $seanceId,
                        'canMove' => true,
                        'tutorId' => null,
                        'tutorName' => null,
                        'canRate' => false,
                        'ratingNote' => null,
                        'ratingComment' => null,
                        'reviewSummary' => $reviewSummary,
                        'dateTime' => $seance->getStartAt()->format(DATE_ATOM),
                    ];
                }
            }
        }

        usort(
            $calendarEvents,
            static fn(array $a, array $b): int => strcmp((string) $a['dateTime'], (string) $b['dateTime'])
        );

        $nowIso = (new \DateTimeImmutable())->format(DATE_ATOM);
        $upcomingSessions = array_values(array_filter(
            $calendarEvents,
            static fn(array $event): bool => (string) $event['dateTime'] >= $nowIso
        ));
        $upcomingReservations = array_values(array_filter(
            $upcomingSessions,
            static fn(array $event): bool => (string) ($event['kindKey'] ?? '') === 'reservation'
        ));
        $upcomingPublishedSessions = array_values(array_filter(
            $upcomingSessions,
            static fn(array $event): bool => (string) ($event['kindKey'] ?? '') === 'session'
        ));

        return $this->render('front/reservation/calendar.html.twig', [
            'controller_name' => 'CalendarController',
            'user' => $this->getUser(),
            'calendarEvents' => $calendarEvents,
            'upcomingSessions' => array_slice($upcomingSessions, 0, 3),
            'upcomingReservations' => array_slice($upcomingReservations, 0, 3),
            'upcomingPublishedSessions' => array_slice($upcomingPublishedSessions, 0, 3),
        ]);
    }

    #[Route('/calendar/seance/{id}/move', name: 'app_calendar_move_seance', methods: ['POST'])]
    public function movePublishedSeanceDate(
        Seance $seance,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $isTutor = $this->isGranted('ROLE_TUTOR') || $this->isGranted('ROLE_TUTEUR');
        if (!$isTutor) {
            return $this->json(['message' => 'Acces refuse.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifie.'], Response::HTTP_FORBIDDEN);
        }

        if ($seance->getTuteur()?->getId() !== $user->getId()) {
            return $this->json(['message' => 'Vous ne pouvez modifier que vos seances.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $token = (string) ($payload['_token'] ?? '');
        if (!$this->isCsrfTokenValid('calendar_move_seance', $token)) {
            return $this->json(['message' => 'CSRF token invalide.'], Response::HTTP_FORBIDDEN);
        }

        $dateRaw = trim((string) ($payload['date'] ?? ''));
        if ($dateRaw === '') {
            return $this->json(['message' => 'Date cible obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $targetDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
        if (!$targetDate instanceof \DateTimeImmutable) {
            return $this->json(['message' => 'Format de date invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $startAt = $seance->getStartAt();
        if (!$startAt instanceof \DateTimeInterface) {
            return $this->json(['message' => 'Seance sans date de debut.'], Response::HTTP_BAD_REQUEST);
        }

        $currentStartAt = $startAt instanceof \DateTimeImmutable
            ? $startAt
            : \DateTimeImmutable::createFromInterface($startAt);

        $updatedStartAt = $currentStartAt->setDate(
            (int) $targetDate->format('Y'),
            (int) $targetDate->format('m'),
            (int) $targetDate->format('d')
        );

        $seance->setStartAt($updatedStartAt);
        $seance->setUpdatedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json([
            'message' => 'Date de la seance mise a jour.',
            'date' => $updatedStartAt->format('Y-m-d'),
            'time' => $updatedStartAt->format('H:i'),
            'dateTime' => $updatedStartAt->format(DATE_ATOM),
        ]);
    }

    #[Route('/calendar/tuteur/{id}/rate', name: 'app_calendar_rate_tutor', methods: ['POST'])]
    public function rateTutorFromCalendar(
        User $tuteur,
        Request $request,
        EntityManagerInterface $entityManager,
        RatingTutorRepository $ratingTutorRepository,
        ReservationRepository $reservationRepository,
        ValidatorInterface $validator
    ): JsonResponse {
        if (!$this->isGranted('ROLE_ETUDIANT')) {
            return $this->json(['message' => 'Action reservee a l\'etudiant.'], Response::HTTP_FORBIDDEN);
        }

        $etudiant = $this->getUser();
        if (!$etudiant instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifie.'], Response::HTTP_FORBIDDEN);
        }

        if ($etudiant->getId() === $tuteur->getId()) {
            return $this->json(['message' => 'Vous ne pouvez pas vous noter vous-meme.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isTutorUser($tuteur)) {
            return $this->json(['message' => 'Ce compte n\'est pas un tuteur.'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $token = (string) ($payload['_token'] ?? '');
        if (!$this->isCsrfTokenValid('calendar_rate_tutor', $token)) {
            return $this->json(['message' => 'CSRF token invalide.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->studentHasAcceptedPastReservationWithTutor($etudiant, $tuteur, $reservationRepository)) {
            return $this->json(
                ['message' => 'Vous pouvez noter ce tuteur uniquement apres une seance acceptee deja passee.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $noteRaw = $payload['note'] ?? null;
        $note = is_numeric($noteRaw) ? (int) $noteRaw : null;
        $commentaire = trim((string) ($payload['commentaire'] ?? ''));

        $rating = $ratingTutorRepository->findOneBy([
            'etudiant' => $etudiant,
            'tuteur' => $tuteur,
        ]);

        if (!$rating instanceof RatingTutor) {
            $rating = new RatingTutor();
            $rating
                ->setEtudiant($etudiant)
                ->setTuteur($tuteur)
                ->setCreatedAt(new \DateTimeImmutable());

            $entityManager->persist($rating);
        }

        $rating
            ->setNote($note)
            ->setCommentaire($commentaire !== '' ? $commentaire : null)
            ->setUpdatedAt(new \DateTimeImmutable());

        $violations = $validator->validate($rating);
        if (count($violations) > 0) {
            return $this->json(['message' => $violations[0]->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entityManager->flush();

        return $this->json([
            'message' => 'Note envoyee au tuteur avec succes.',
            'note' => $rating->getNote(),
            'commentaire' => $rating->getCommentaire(),
        ]);
    }

    private function isTutorUser(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array('ROLE_TUTOR', $roles, true) || in_array('ROLE_TUTEUR', $roles, true);
    }

    private function studentHasAcceptedPastReservationWithTutor(
        User $etudiant,
        User $tuteur,
        ReservationRepository $reservationRepository
    ): bool {
        $acceptedPastCount = (int) $reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.seance', 's')
            ->andWhere('r.participant = :participant')
            ->andWhere('s.tuteur = :tuteur')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('s.startAt < :now')
            ->setParameter('participant', $etudiant)
            ->setParameter('tuteur', $tuteur)
            ->setParameter('statuses', [self::STATUS_ACCEPTED, self::STATUS_PAID])
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return $acceptedPastCount > 0;
    }

    /**
     * @param array<int, mixed> $seances
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildReviewSummaryBySeance(
        array $seances,
        User $tuteur,
        ReservationRepository $reservationRepository,
        RatingTutorRepository $ratingTutorRepository
    ): array {
        $seanceIds = [];
        foreach ($seances as $seance) {
            if (!$seance instanceof Seance || $seance->getId() === null) {
                continue;
            }

            $seanceIds[] = $seance->getId();
        }

        if ($seanceIds === []) {
            return [];
        }

        $acceptedReservations = $reservationRepository->createQueryBuilder('r')
            ->innerJoin('r.seance', 's')->addSelect('s')
            ->leftJoin('r.participant', 'p')->addSelect('p')
            ->andWhere('s.id IN (:seanceIds)')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('seanceIds', $seanceIds)
            ->setParameter('statuses', [self::STATUS_ACCEPTED, self::STATUS_PAID])
            ->getQuery()
            ->getResult();

        /** @var array<int, array<int, User>> $participantsBySeance */
        $participantsBySeance = [];
        /** @var array<int, true> $participantIds */
        $participantIds = [];

        foreach ($acceptedReservations as $reservation) {
            if (!$reservation instanceof Reservation || !$reservation->getSeance()) {
                continue;
            }

            $seanceId = $reservation->getSeance()->getId();
            $participant = $reservation->getParticipant();
            $participantId = $participant?->getId();

            if ($seanceId === null || !$participant instanceof User || $participantId === null) {
                continue;
            }

            if (!isset($participantsBySeance[$seanceId])) {
                $participantsBySeance[$seanceId] = [];
            }

            $participantsBySeance[$seanceId][$participantId] = $participant;
            $participantIds[$participantId] = true;
        }

        /** @var array<int, RatingTutor> $ratingsByStudentId */
        $ratingsByStudentId = [];

        if ($participantIds !== []) {
            $ratings = $ratingTutorRepository->createQueryBuilder('rt')
                ->leftJoin('rt.etudiant', 'e')->addSelect('e')
                ->andWhere('rt.tuteur = :tuteur')
                ->andWhere('rt.etudiant IN (:etudiants)')
                ->setParameter('tuteur', $tuteur)
                ->setParameter('etudiants', array_keys($participantIds))
                ->getQuery()
                ->getResult();

            foreach ($ratings as $rating) {
                if (!$rating instanceof RatingTutor) {
                    continue;
                }

                $studentId = $rating->getEtudiant()?->getId();
                if ($studentId !== null) {
                    $ratingsByStudentId[$studentId] = $rating;
                }
            }
        }

        $summaries = [];

        foreach ($seanceIds as $seanceId) {
            $reviews = [];
            $distributionCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $sum = 0;

            foreach ($participantsBySeance[$seanceId] ?? [] as $studentId => $participant) {
                $rating = $ratingsByStudentId[$studentId] ?? null;
                if (!$rating instanceof RatingTutor) {
                    continue;
                }

                $note = (int) ($rating->getNote() ?? 0);
                if ($note < 1 || $note > 5) {
                    continue;
                }

                $distributionCounts[$note]++;
                $sum += $note;

                $reviewedAt = $rating->getUpdatedAt() ?? $rating->getCreatedAt();
                $commentaire = trim((string) ($rating->getCommentaire() ?? ''));

                $reviews[] = [
                    'studentName' => $participant->getFullName() ?? 'Etudiant',
                    'note' => $note,
                    'commentaire' => $commentaire !== '' ? $commentaire : null,
                    'reviewedAt' => $reviewedAt?->format(DATE_ATOM),
                ];
            }

            usort(
                $reviews,
                static fn(array $a, array $b): int => strcmp((string) ($b['reviewedAt'] ?? ''), (string) ($a['reviewedAt'] ?? ''))
            );

            $count = count($reviews);
            $distribution = [];

            foreach ([5, 4, 3, 2, 1] as $stars) {
                $countByStars = $distributionCounts[$stars] ?? 0;
                $percentage = $count > 0 ? (int) round(($countByStars / $count) * 100) : 0;

                $distribution[] = [
                    'stars' => $stars,
                    'label' => $stars . ' etoiles',
                    'count' => $countByStars,
                    'percentage' => $percentage,
                ];
            }

            $comments = array_values(array_filter(
                $reviews,
                static fn(array $review): bool => trim((string) ($review['commentaire'] ?? '')) !== ''
            ));

            $summaries[$seanceId] = [
                'count' => $count,
                'average' => $count > 0 ? round($sum / $count, 2) : null,
                'distribution' => $distribution,
                'comments' => $comments,
                'reviews' => $reviews,
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEmptyReviewSummary(): array
    {
        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $distribution[] = [
                'stars' => $stars,
                'label' => $stars . ' etoiles',
                'count' => 0,
                'percentage' => 0,
            ];
        }

        return [
            'count' => 0,
            'average' => null,
            'distribution' => $distribution,
            'comments' => [],
            'reviews' => [],
        ];
    }
}
