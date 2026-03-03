<?php

namespace App\Controller;

use App\Entity\RatingTutor;
use App\Entity\Reservation;
use App\Entity\RevisionPlanner;
use App\Entity\Seance;
use App\Entity\User;
use App\Repository\RatingTutorRepository;
use App\Repository\ReservationRepository;
use App\Repository\RevisionPlannerRepository;
use App\Repository\SeanceRepository;
use App\Service\EmailService;
use App\Service\OpenAIService;
use App\Service\OpenAiMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SeanceRevisionController extends AbstractController
{
    private const STATUS_PENDING = Reservation::STATUS_PENDING;
    private const STATUS_ACCEPTED = Reservation::STATUS_ACCEPTED;
    private const STATUS_PAID = Reservation::STATUS_PAID;

    #[Route('/seance/revision', name: 'app_seance_revision', methods: ['GET'])]
    public function index(
        Request $request,
        SeanceRepository $seanceRepository,
        ReservationRepository $reservationRepository,
        OpenAiMatchingService $openAiMatchingService
    ): Response
    {
        $legacyRevisionTarget = strtolower(trim((string) $request->query->get('revision', '')));
        if (in_array($legacyRevisionTarget, ['seance', 'seances'], true)) {
            return $this->redirectToRoute('admin_seance_index');
        }

        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $isStudent = $this->isStudentRole();
        $isTutor = $this->isTutorRole();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if (!$isStudent && !$isTutor) {
            if ($isAdmin) {
                return $this->redirectToRoute('admin_dashboard');
            }

            throw $this->createAccessDeniedException('Acces reserve aux etudiants et tuteurs.');
        }

        $allSeances = $seanceRepository->createQueryBuilder('s')
            ->leftJoin('s.tuteur', 't')->addSelect('t')
            ->orderBy('s.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        $searchQuery = trim((string) $request->query->get('search', ''));
        $searchApplied = $searchQuery !== '';
        $searchMode = strtolower(trim((string) $request->query->get('mode', 'simple')));
        if (!in_array($searchMode, ['simple', 'recommendation'], true)) {
            $searchMode = 'simple';
        }

        if (!$isStudent && $searchMode === 'recommendation') {
            $searchMode = 'simple';
        }

        $showRecommendations = $isStudent && $searchMode === 'recommendation';
        $totalSeancesCount = count($allSeances);
        $allSeancesForMatching = $allSeances;
        $filteredSeances = $this->filterSeancesBySimpleSearch($allSeances, $searchQuery);
        $filteredSeancesCount = count($filteredSeances);

        $studentSeancesPerPage = 3;
        $studentSeancesPage = max(1, (int) $request->query->get('page', 1));
        $studentSeancesTotalPages = max(1, (int) ceil($filteredSeancesCount / $studentSeancesPerPage));

        if ($studentSeancesPage > $studentSeancesTotalPages) {
            $studentSeancesPage = $studentSeancesTotalPages;
        }

        $studentSeancesOffset = ($studentSeancesPage - 1) * $studentSeancesPerPage;
        $allSeancesPaginated = array_slice($filteredSeances, $studentSeancesOffset, $studentSeancesPerPage);

        $mySeances = [];
        $otherSeances = [];

        foreach ($filteredSeances as $seance) {
            if (!$seance instanceof Seance) {
                continue;
            }

            if ($seance->getTuteur()?->getId() === $user->getId()) {
                $mySeances[] = $seance;
            } else {
                $otherSeances[] = $seance;
            }
        }

        $myReservations = $reservationRepository->createQueryBuilder('r')
            ->innerJoin('r.seance', 's')->addSelect('s')
            ->leftJoin('s.tuteur', 't')->addSelect('t')
            ->andWhere('r.participant = :participant')
            ->setParameter('participant', $user)
            ->orderBy('s.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        $acceptedReservations = array_values(array_filter(
            $myReservations,
            static fn($reservation): bool => $reservation instanceof Reservation
                && in_array(
                    (int) ($reservation->getStatus() ?? self::STATUS_PENDING),
                    [self::STATUS_ACCEPTED, self::STATUS_PAID],
                    true
                )
        ));

        $reservationBySeance = [];
        $reservationIdBySeance = [];
        foreach ($myReservations as $reservation) {
            if (!$reservation instanceof Reservation || !$reservation->getSeance()) {
                continue;
            }

            $seanceId = $reservation->getSeance()->getId();
            if ($seanceId === null) {
                continue;
            }

            $reservationBySeance[$seanceId] = $reservation->getStatus();
            $reservationIdBySeance[$seanceId] = $reservation->getId();
        }

        $reservationsOnMySeances = [];

        if ($isTutor) {
            $reservationsOnMySeances = $reservationRepository->createQueryBuilder('r')
                ->innerJoin('r.seance', 's')->addSelect('s')
                ->leftJoin('r.participant', 'p')->addSelect('p')
                ->leftJoin('s.tuteur', 't')->addSelect('t')
                ->andWhere('s.tuteur = :tuteur')
                ->setParameter('tuteur', $user)
                ->orderBy('s.startAt', 'ASC')
                ->getQuery()
                ->getResult();

        }

        $aiMatching = null;
        if ($showRecommendations) {
            $aiRawRecommendations = $openAiMatchingService->recommendForStudent(
                $user,
                $allSeancesForMatching,
                $myReservations,
                $request->query->getBoolean('refresh_ia')
            );
            $aiMatching = $this->hydrateAiRecommendations($aiRawRecommendations, $allSeancesForMatching);
        }

        return $this->render('seance_revision/index.html.twig', [
            'isStudent' => $isStudent,
            'isTutor' => $isTutor,
            'allSeances' => $filteredSeances,
            'allSeancesPaginated' => $allSeancesPaginated,
            'studentSeancesPage' => $studentSeancesPage,
            'studentSeancesTotalPages' => $studentSeancesTotalPages,
            'mySeances' => $mySeances,
            'otherSeances' => $otherSeances,
            'myReservations' => $myReservations,
            'acceptedReservations' => $acceptedReservations,
            'reservationBySeance' => $reservationBySeance,
            'reservationIdBySeance' => $reservationIdBySeance,
            'reservationsOnMySeances' => $reservationsOnMySeances,
            'statusPending' => self::STATUS_PENDING,
            'statusAccepted' => self::STATUS_ACCEPTED,
            'statusPaid' => self::STATUS_PAID,
            'aiMatching' => $aiMatching,
            'searchMode' => $searchMode,
            'showRecommendations' => $showRecommendations,
            'searchQuery' => $searchQuery,
            'searchApplied' => $searchApplied,
            'totalSeancesCount' => $totalSeancesCount,
            'filteredSeancesCount' => $filteredSeancesCount,
        ]);
    }

    #[Route('/seance/revision/planner', name: 'app_seance_revision_planner', methods: ['GET'])]
    public function plannerPage(Request $request, RevisionPlannerRepository $revisionPlannerRepository): Response
    {
        $this->ensurePlannerAccessRole();

        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $historyPlanners = $revisionPlannerRepository->findRecentForStudent($user, 20);
        $selectedPlanner = null;
        $selectedPlannerId = max(0, (int) $request->query->get('planner', 0));

        if ($selectedPlannerId > 0) {
            $candidate = $revisionPlannerRepository->findOneBy(['id' => $selectedPlannerId, 'student' => $user]);
            if ($candidate instanceof RevisionPlanner) {
                $selectedPlanner = $candidate;
            }
        } elseif ($historyPlanners !== []) {
            $selectedPlanner = $historyPlanners[0];
        }

        $defaultDifficulty = 'medium';
        $defaultExamDate = '';
        $defaultDailySessions = 2;
        $defaultReminderTime = '19:00';
        $defaultIncludeWeekend = false;
        $defaultFocusSubject = '';
        $editingPlannerId = null;

        if ($selectedPlanner instanceof RevisionPlanner) {
            $defaultDifficulty = $this->normalizeDifficultyLevel((string) ($selectedPlanner->getDifficultyLevel() ?? 'medium'));
            $defaultExamDate = $selectedPlanner->getExamDate()?->format('Y-m-d') ?? '';
            $defaultDailySessions = max(1, min(4, (int) ($selectedPlanner->getDailySessions() ?? 2)));
            $defaultReminderTime = trim((string) ($selectedPlanner->getReminderTime() ?? '19:00'));
            $defaultIncludeWeekend = (bool) $selectedPlanner->isIncludeWeekend();
            $defaultFocusSubject = trim((string) ($selectedPlanner->getFocusSubject() ?? ''));
            $editingPlannerId = $selectedPlanner->getId();
        }

        if ($defaultExamDate !== '') {
            $defaultDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $defaultExamDate);
            if ($defaultDate instanceof \DateTimeImmutable && $defaultDate <= new \DateTimeImmutable('today')) {
                $defaultExamDate = '';
            }
        }

        return $this->render('seance_revision/planner_form.html.twig', [
            'defaultDailySessions' => $defaultDailySessions,
            'defaultReminderTime' => $defaultReminderTime,
            'defaultDifficultyLevel' => $defaultDifficulty,
            'defaultExamDate' => $defaultExamDate,
            'defaultIncludeWeekend' => $defaultIncludeWeekend,
            'defaultFocusSubject' => $defaultFocusSubject,
            'editingPlannerId' => $editingPlannerId,
            'historyPlanners' => $historyPlanners,
        ]);
    }

    #[Route('/seance/revision/planner/result', name: 'app_seance_revision_planner_result', methods: ['POST'])]
    public function plannerResult(
        Request $request,
        ReservationRepository $reservationRepository,
        RevisionPlannerRepository $revisionPlannerRepository,
        EntityManagerInterface $entityManager,
        OpenAIService $openAIService,
        OpenAiMatchingService $openAiMatchingService
    ): Response {
        $this->ensurePlannerAccessRole();

        if (!$this->isCsrfTokenValid('planner_generate', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $plannerId = max(0, (int) $request->request->get('plannerId', 0));
        $existingPlanner = null;
        if ($plannerId > 0) {
            $candidate = $revisionPlannerRepository->findOneBy(['id' => $plannerId, 'student' => $user]);
            if ($candidate instanceof RevisionPlanner) {
                $existingPlanner = $candidate;
            }
        }

        $focusSubject = trim((string) $request->request->get('focusSubject', ''));
        $difficultyLevel = $this->normalizeDifficultyLevel((string) $request->request->get('difficultyLevel', 'medium'));
        $payload = [
            'examDate' => trim((string) $request->request->get('examDate', '')),
            'dailySessions' => max(1, min(4, (int) $request->request->get('dailySessions', 2))),
            'includeWeekend' => $request->request->getBoolean('includeWeekend'),
            'reminderTime' => trim((string) $request->request->get('reminderTime', '19:00')),
            'focusSubject' => $focusSubject,
            'difficultyLevel' => $difficultyLevel,
        ];

        $payload = $this->buildPlannerPayloadWithFocusSubject($payload, $user, $reservationRepository);
        $plannerData = $this->generatePlannerDataWithFallback($user, $payload, $openAIService, $openAiMatchingService);
        $plannerData = $this->enrichPlannerDataForContext($plannerData, $payload);

        $planner = $existingPlanner instanceof RevisionPlanner ? $existingPlanner : new RevisionPlanner();
        $isNewPlanner = $planner->getId() === null;
        $examDate = $this->resolveExamDateForPlanner($payload, $plannerData);
        $planEntries = isset($plannerData['planEntries']) && is_array($plannerData['planEntries'])
            ? array_values($plannerData['planEntries'])
            : [];

        $planner
            ->setStudent($user)
            ->setExamDate($examDate)
            ->setFocusSubject($focusSubject !== '' ? $focusSubject : null)
            ->setDifficultyLevel($difficultyLevel)
            ->setDailySessions((int) $payload['dailySessions'])
            ->setIncludeWeekend((bool) $payload['includeWeekend'])
            ->setReminderTime((string) ($payload['reminderTime'] ?? '19:00'))
            ->setPlanData($plannerData)
            ->setProgressData([])
            ->setTotalEntries(count($planEntries))
            ->setCompletedEntries(0)
            ->setCompletionRate(0.0)
            ->setUpdatedAt(new \DateTimeImmutable());

        if ($isNewPlanner) {
            $planner->setCreatedAt(new \DateTimeImmutable());
            $entityManager->persist($planner);
        }

        $entityManager->flush();
        $plannerDays = $this->buildPlannerDaysForView($planEntries, []);
        $historyPlanners = $revisionPlannerRepository->findRecentForStudent($user, 20);

        return $this->render('seance_revision/planner_result.html.twig', [
            'planner' => $planner,
            'plannerData' => $plannerData,
            'plannerDays' => $plannerDays,
            'historyPlanners' => $historyPlanners,
            'requestData' => $this->buildPlannerRequestData($planner),
        ]);
    }

    #[Route('/seance/revision/planner/history/{id}', name: 'app_seance_revision_planner_show', methods: ['GET'])]
    public function showPlannerResult(
        RevisionPlanner $planner,
        RevisionPlannerRepository $revisionPlannerRepository
    ): Response {
        $this->ensurePlannerAccessRole();

        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertPlannerOwnedByStudent($planner, $user);

        $planData = $planner->getPlanData();
        $payload = [
            'examDate' => $planner->getExamDate()?->format('Y-m-d') ?? '',
            'focusSubject' => (string) ($planner->getFocusSubject() ?? ''),
            'difficultyLevel' => (string) ($planner->getDifficultyLevel() ?? 'medium'),
        ];
        $planData = $this->enrichPlannerDataForContext($planData, $payload);
        $plannerDays = $this->buildPlannerDaysForView(
            isset($planData['planEntries']) && is_array($planData['planEntries']) ? $planData['planEntries'] : [],
            $planner->getProgressData() ?? []
        );
        $historyPlanners = $revisionPlannerRepository->findRecentForStudent($user, 20);

        return $this->render('seance_revision/planner_result.html.twig', [
            'planner' => $planner,
            'plannerData' => $planData,
            'plannerDays' => $plannerDays,
            'historyPlanners' => $historyPlanners,
            'requestData' => $this->buildPlannerRequestData($planner),
        ]);
    }

    #[Route('/seance/revision/planner/{id}/progress', name: 'app_seance_revision_planner_progress', methods: ['POST'])]
    public function updatePlannerProgress(
        RevisionPlanner $planner,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $this->ensurePlannerAccessRole();

        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertPlannerOwnedByStudent($planner, $user);

        if (!$this->isCsrfTokenValid('planner_progress_' . $planner->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $planEntries = (array) ($planner->getPlanData()['planEntries'] ?? []);
        $validEntryIds = $this->extractPlannerEntryIds($planEntries);
        $submitted = $request->request->all();
        $rawCompletedEntries = $submitted['completedEntries'] ?? [];
        $completedEntriesInput = is_array($rawCompletedEntries) ? $rawCompletedEntries : [];

        $progressMap = [];
        foreach ($completedEntriesInput as $entryId) {
            $entryIdNormalized = trim((string) $entryId);
            if ($entryIdNormalized === '' || !isset($validEntryIds[$entryIdNormalized])) {
                continue;
            }
            $progressMap[$entryIdNormalized] = true;
        }

        $totalEntries = count($validEntryIds);
        $completedEntries = count($progressMap);
        $completionRate = $totalEntries > 0
            ? round(($completedEntries / $totalEntries) * 100, 1)
            : 0.0;

        $planner
            ->setProgressData($progressMap)
            ->setTotalEntries($totalEntries)
            ->setCompletedEntries($completedEntries)
            ->setCompletionRate($completionRate)
            ->setUpdatedAt(new \DateTimeImmutable());

        $entityManager->flush();
        $this->addFlash('success', 'Progression mise a jour.');

        return $this->redirectToRoute('app_seance_revision_planner_show', ['id' => $planner->getId()]);
    }

    #[Route('/seance/revision/planner/{id}/export.pdf', name: 'app_seance_revision_planner_export_pdf', methods: ['GET'])]
    public function exportPlannerPdf(RevisionPlanner $planner): Response
    {
        $this->ensurePlannerAccessRole();

        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertPlannerOwnedByStudent($planner, $user);

        $plannerDays = $this->buildPlannerDaysForView(
            (array) ($planner->getPlanData()['planEntries'] ?? []),
            $planner->getProgressData() ?? []
        );
        $pdfContent = $this->buildPlannerPdfContent($planner, $plannerDays);
        $filename = sprintf('planner-revision-%d.pdf', (int) ($planner->getId() ?? 0));

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        ));

        return $response;
    }

    #[Route('/seance/revision/planner/ai-generate', name: 'app_seance_revision_planner_ai_generate', methods: ['POST'])]
    public function generateAiPlanner(
        Request $request,
        ReservationRepository $reservationRepository,
        OpenAIService $openAIService,
        OpenAiMatchingService $openAiMatchingService
    ): Response {
        $this->ensurePlannerAccessRole();

        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($payload['difficultyLevel'])) {
            $payload['difficultyLevel'] = 'medium';
        }

        $payload = $this->buildPlannerPayloadWithFocusSubject($payload, $user, $reservationRepository);
        $plannerData = $this->generatePlannerDataWithFallback($user, $payload, $openAIService, $openAiMatchingService);
        $plannerData = $this->enrichPlannerDataForContext($plannerData, $payload);

        return $this->json($plannerData);
    }

    #[Route('/seance/revision/seance/ajouter', name: 'app_seance_revision_add_seance', methods: ['POST'])]
    public function addSeance(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->ensureTutorRole();

        if (!$this->isCsrfTokenValid('add_seance', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $seance = new Seance();
        $seance->setTuteur($user);
        $seance->setCreatedAt(new \DateTimeImmutable());
        $seance->setStatus(1);

        $errors = $this->applySeanceDataFromRequest($seance, $request);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_seance_revision');
        }

        $entityManager->persist($seance);
        $entityManager->flush();

        $this->addFlash('success', 'Seance ajoutee.');

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/seance/revision/seance/{id}/modifier', name: 'app_seance_revision_edit_seance', methods: ['POST'])]
    public function editSeance(Seance $seance, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->ensureTutorRole();

        if (!$this->isCsrfTokenValid('edit_seance_' . $seance->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertTutorOwnsSeance($seance, $user);

        $errors = $this->applySeanceDataFromRequest($seance, $request);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_seance_revision');
        }

        $seance->setUpdatedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', 'Seance modifiee.');

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/seance/revision/seance/{id}/supprimer', name: 'app_seance_revision_delete_seance', methods: ['POST'])]
    public function deleteSeance(Seance $seance, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->ensureTutorRole();

        if (!$this->isCsrfTokenValid('delete_seance_' . $seance->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertTutorOwnsSeance($seance, $user);

        $entityManager->remove($seance);
        $entityManager->flush();

        $this->addFlash('success', 'Seance supprimee.');

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/seance/revision/seance/{id}/reserver', name: 'app_seance_revision_reserve', methods: ['POST'])]
    public function reserveSeance(
        Seance $seance,
        Request $request,
        EntityManagerInterface $entityManager,
        ReservationRepository $reservationRepository,
        EmailService $emailService
    ): Response {
        $this->ensureReservationActorRole();

        if (!$this->isCsrfTokenValid('reserve_seance_' . $seance->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        if ($seance->getTuteur()?->getId() === $user->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas reserver votre propre seance.');

            return $this->redirectToRoute('app_seance_revision');
        }

        $alreadyReserved = (int) $reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.seance = :seance')
            ->andWhere('r.participant = :participant')
            ->setParameter('seance', $seance)
            ->setParameter('participant', $user)
            ->getQuery()
            ->getSingleScalarResult();

        if ($alreadyReserved > 0) {
            $this->addFlash('info', 'Vous avez deja reserve cette seance.');

            return $this->redirectToRoute('app_seance_revision');
        }

        $currentReservationsCount = (int) $reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.seance = :seance')
            ->setParameter('seance', $seance)
            ->getQuery()
            ->getSingleScalarResult();

        if ($currentReservationsCount >= (int) $seance->getMaxParticipants()) {
            $this->addFlash('error', 'Cette seance est complete.');

            return $this->redirectToRoute('app_seance_revision');
        }

        $reservation = new Reservation();
        $reservation
            ->setSeance($seance)
            ->setParticipant($user)
            ->setReservedAt(new \DateTimeImmutable())
            ->setStatus(self::STATUS_PENDING);

        $entityManager->persist($reservation);
        $entityManager->flush();

        $reservationEmailError = null;
        $reservationMailSent = $this->sendReservationCreatedEmailIfNeeded(
            $reservation,
            $entityManager,
            $emailService,
            $reservationEmailError
        );

        if ($reservationMailSent) {
            $this->addFlash('success', 'Reservation ajoutee (statut En attente). Email de confirmation envoye.');
        } else {
            $this->addFlash(
                'warning',
                'Reservation ajoutee (statut En attente). Email de confirmation non envoye.'
                . ($reservationEmailError ? ' Raison: ' . $reservationEmailError : '')
            );
        }

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/seance/revision/reservation/{id}/annuler', name: 'app_seance_revision_cancel_reservation', methods: ['POST'])]
    public function cancelReservation(Reservation $reservation, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->ensureReservationActorRole();

        if (!$this->isCsrfTokenValid('cancel_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertParticipantOwnsReservation($reservation, $user);
        if ((int) ($reservation->getStatus() ?? self::STATUS_PENDING) === self::STATUS_PAID) {
            $this->addFlash('warning', 'Reservation deja payee: annulation desactivee (refund non implemente).');

            return $this->redirectToRoute('app_seance_revision');
        }

        $entityManager->remove($reservation);
        $entityManager->flush();

        $this->addFlash('success', 'Reservation annulee.');

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/seance/revision/tuteur/{id}/noter', name: 'app_seance_revision_rate_tutor', methods: ['POST'])]
    public function rateTutor(
        User $tuteur,
        Request $request,
        EntityManagerInterface $entityManager,
        RatingTutorRepository $ratingTutorRepository,
        ReservationRepository $reservationRepository,
        ValidatorInterface $validator
    ): Response {
        $this->ensureStudentRole();

        if (!$this->isCsrfTokenValid('rate_tutor_' . $tuteur->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $etudiant = $this->getAuthenticatedUser();

        if (!$etudiant instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        if ($etudiant->getId() === $tuteur->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas vous noter vous-meme.');

            return $this->redirectToRoute('app_seance_revision');
        }

        if (!$this->isTutorUser($tuteur)) {
            $this->addFlash('error', 'Ce compte n\'est pas un tuteur.');

            return $this->redirectToRoute('app_seance_revision');
        }

        if (!$this->studentHasAcceptedPastReservationWithTutor($etudiant, $tuteur, $reservationRepository)) {
            $this->addFlash('error', 'Vous pouvez noter ce tuteur uniquement apres une reservation acceptee deja passee.');

            return $this->redirectToRoute('app_seance_revision');
        }

        $rating = $ratingTutorRepository->findOneBy([
            'etudiant' => $etudiant,
            'tuteur' => $tuteur,
        ]);

        $isNewRating = false;

        if (!$rating instanceof RatingTutor) {
            $rating = new RatingTutor();
            $rating
                ->setEtudiant($etudiant)
                ->setTuteur($tuteur)
                ->setCreatedAt(new \DateTimeImmutable());
            $isNewRating = true;
        }

        $noteRaw = $request->request->get('note');
        $note = is_numeric($noteRaw) ? (int) $noteRaw : null;
        $commentaire = trim((string) $request->request->get('commentaire', ''));

        $rating
            ->setNote($note)
            ->setCommentaire($commentaire !== '' ? $commentaire : null)
            ->setUpdatedAt(new \DateTimeImmutable());

        $violations = $validator->validate($rating);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('error', $violation->getMessage());
            }

            return $this->redirectToRoute('app_seance_revision');
        }

        if ($isNewRating) {
            $entityManager->persist($rating);
        }

        try {
            $entityManager->flush();
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible d\'enregistrer la note.');

            return $this->redirectToRoute('app_seance_revision');
        }

        $this->addFlash('success', $isNewRating ? 'Note enregistree.' : 'Note mise a jour.');

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/seance/revision/reservation/{id}/accepter', name: 'app_seance_revision_accept_reservation', methods: ['POST'])]
    public function acceptReservation(
        Reservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ): Response
    {
        $this->ensureTutorRole();

        if (!$this->isCsrfTokenValid('accept_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertTutorOwnsReservation($reservation, $user);

        if ((int) ($reservation->getStatus() ?? self::STATUS_PENDING) === self::STATUS_PAID) {
            $this->addFlash('info', 'Reservation deja payee.');

            return $this->redirectToRoute('app_seance_revision');
        }

        $reservation->setStatus(self::STATUS_ACCEPTED);
        $entityManager->flush();

        $acceptanceEmailError = null;
        $acceptanceMailSent = $this->sendReservationAcceptedEmailIfNeeded(
            $reservation,
            $entityManager,
            $emailService,
            $acceptanceEmailError
        );

        if ($acceptanceMailSent) {
            $this->addFlash('success', 'Reservation acceptee. Email envoye a l\'etudiant.');
        } else {
            $this->addFlash(
                'warning',
                'Reservation acceptee. Email d\'acceptation non envoye.'
                . ($acceptanceEmailError ? ' Raison: ' . $acceptanceEmailError : '')
            );
        }

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/seance/revision/reservation/{id}/modifier', name: 'app_seance_revision_edit_reservation', methods: ['POST'])]
    public function editReservation(
        Reservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ): Response
    {
        $this->ensureTutorRole();

        if (!$this->isCsrfTokenValid('edit_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertTutorOwnsReservation($reservation, $user);

        $status = (int) $request->request->get('status', self::STATUS_PENDING);
        $isAlreadyPaid = (int) ($reservation->getStatus() ?? self::STATUS_PENDING) === self::STATUS_PAID;
        if ($isAlreadyPaid) {
            $status = self::STATUS_PAID;
        } elseif (!in_array($status, [self::STATUS_PENDING, self::STATUS_ACCEPTED], true)) {
            $status = self::STATUS_PENDING;
        }

        $notes = trim((string) $request->request->get('notes', ''));
        $previousStatus = (int) $reservation->getStatus();

        $reservation->setStatus($status);
        $reservation->setNotes($notes !== '' ? $notes : null);

        $entityManager->flush();

        $justAccepted = $previousStatus !== self::STATUS_ACCEPTED && $status === self::STATUS_ACCEPTED;
        if ($justAccepted) {
            $acceptanceEmailError = null;
            $acceptanceMailSent = $this->sendReservationAcceptedEmailIfNeeded(
                $reservation,
                $entityManager,
                $emailService,
                $acceptanceEmailError
            );

            if (!$acceptanceMailSent) {
                $this->addFlash(
                    'warning',
                    'Reservation acceptee. Email d\'acceptation non envoye.'
                    . ($acceptanceEmailError ? ' Raison: ' . $acceptanceEmailError : '')
                );
            }
        }

        $this->addFlash('success', 'Reservation modifiee.');

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/seance/revision/reservation/{id}/supprimer', name: 'app_seance_revision_delete_reservation', methods: ['POST'])]
    public function deleteReservation(Reservation $reservation, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->ensureTutorRole();

        if (!$this->isCsrfTokenValid('delete_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getAuthenticatedUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertTutorOwnsReservation($reservation, $user);
        if ((int) ($reservation->getStatus() ?? self::STATUS_PENDING) === self::STATUS_PAID) {
            $this->addFlash('warning', 'Suppression bloquee: reservation deja payee.');

            return $this->redirectToRoute('app_seance_revision');
        }

        $entityManager->remove($reservation);
        $entityManager->flush();

        $this->addFlash('success', 'Reservation supprimee.');

        return $this->redirectToRoute('app_seance_revision');
    }

    private function ensureTutorRole(): void
    {
        if (!$this->isTutorRole()) {
            throw $this->createAccessDeniedException('Action reservee au tuteur.');
        }
    }

    private function ensureStudentRole(): void
    {
        if (!$this->isStudentRole()) {
            throw $this->createAccessDeniedException('Action reservee a l\'etudiant.');
        }
    }

    private function ensurePlannerAccessRole(): void
    {
        if (!$this->isStudentRole() && !$this->isTutorRole()) {
            throw $this->createAccessDeniedException('Action reservee aux etudiants et tuteurs.');
        }
    }

    private function ensureReservationActorRole(): void
    {
        if (!$this->isStudentRole() && !$this->isTutorRole()) {
            throw $this->createAccessDeniedException('Action reservee aux etudiants et tuteurs.');
        }
    }

    private function isTutorRole(): bool
    {
        return $this->isGranted('ROLE_TUTOR') || $this->isGranted('ROLE_TUTEUR');
    }

    private function isStudentRole(): bool
    {
        return $this->isGranted('ROLE_ETUDIANT');
    }

    private function isTutorUser(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array('ROLE_TUTOR', $roles, true) || in_array('ROLE_TUTEUR', $roles, true);
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function assertTutorOwnsSeance(Seance $seance, User $user): void
    {
        if ($seance->getTuteur()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas modifier cette seance.');
        }
    }

    private function assertTutorOwnsReservation(Reservation $reservation, User $user): void
    {
        $seance = $reservation->getSeance();

        if (!$seance || $seance->getTuteur()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas modifier cette reservation.');
        }
    }

    private function assertParticipantOwnsReservation(Reservation $reservation, User $user): void
    {
        if ($reservation->getParticipant()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas annuler cette reservation.');
        }
    }

    private function sendReservationCreatedEmailIfNeeded(
        Reservation $reservation,
        EntityManagerInterface $entityManager,
        EmailService $emailService,
        ?string &$errorMessage = null
    ): bool {
        if ($reservation->getConfirmationEmailSentAt() instanceof \DateTimeImmutable) {
            return true;
        }

        try {
            $emailService->sendReservationCreatedEmail($reservation);
            $reservation->setConfirmationEmailSentAt(new \DateTimeImmutable());
            $entityManager->flush();
            $errorMessage = null;

            return true;
        } catch (\Throwable $exception) {
            $errorMessage = $exception->getMessage();

            return false;
        }
    }

    private function sendReservationAcceptedEmailIfNeeded(
        Reservation $reservation,
        EntityManagerInterface $entityManager,
        EmailService $emailService,
        ?string &$errorMessage = null
    ): bool {
        if ($reservation->getAcceptanceEmailSentAt() instanceof \DateTimeImmutable) {
            return true;
        }

        try {
            $emailService->sendReservationAcceptedEmail($reservation);
            $reservation->setAcceptanceEmailSentAt(new \DateTimeImmutable());
            $entityManager->flush();
            $errorMessage = null;

            return true;
        } catch (\Throwable $exception) {
            $errorMessage = $exception->getMessage();

            return false;
        }
    }

    private function studentHasAcceptedPastReservationWithTutor(
        User $etudiant,
        User $tuteur,
        ReservationRepository $reservationRepository
    ): bool {
        $acceptedCount = (int) $reservationRepository->createQueryBuilder('r')
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

        return $acceptedCount > 0;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function buildPlannerPayloadWithFocusSubject(
        array $payload,
        User $student,
        ReservationRepository $reservationRepository
    ): array {
        $subjectStats = $this->loadAcceptedReservationSubjectStats($student, $reservationRepository);
        $focusSubject = trim((string) ($payload['focusSubject'] ?? ''));

        if ($focusSubject !== '') {
            if (!isset($subjectStats[$focusSubject])) {
                $subjectStats[$focusSubject] = 0;
            }
            $subjectStats[$focusSubject] += 3;
        }

        $subjects = [];
        if (isset($payload['subjects']) && is_array($payload['subjects'])) {
            foreach ($payload['subjects'] as $subject) {
                $subjectName = trim((string) $subject);
                if ($subjectName === '') {
                    continue;
                }
                if (!in_array($subjectName, $subjects, true)) {
                    $subjects[] = $subjectName;
                }
            }
        }

        if ($focusSubject !== '' && !in_array($focusSubject, $subjects, true)) {
            $subjects[] = $focusSubject;
        }

        if ($subjects === []) {
            $subjects = array_keys($subjectStats);
        }

        if ($subjects === []) {
            $subjects = ['Revision generale', 'Exercices pratiques', 'Annales'];
        }

        $difficultyLevel = $this->normalizeDifficultyLevel((string) ($payload['difficultyLevel'] ?? 'medium'));
        $examDate = $this->resolveExamDateForPlanner($payload, []);
        $today = new \DateTimeImmutable('today');
        $daysUntilExam = max(1, (int) $today->diff($examDate)->format('%a'));

        $payload['subjects'] = $subjects;
        $payload['subjects_stats'] = $subjectStats;
        $payload['difficultyLevel'] = $difficultyLevel;
        $payload['daysUntilExam'] = $daysUntilExam;

        return $payload;
    }

    /**
     * @return array<string, int>
     */
    private function loadAcceptedReservationSubjectStats(
        User $student,
        ReservationRepository $reservationRepository
    ): array {
        $acceptedReservations = $reservationRepository->createQueryBuilder('r')
            ->innerJoin('r.seance', 's')->addSelect('s')
            ->andWhere('r.participant = :participant')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('participant', $student)
            ->setParameter('statuses', [self::STATUS_ACCEPTED, self::STATUS_PAID])
            ->orderBy('s.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        $subjectStats = [];
        foreach ($acceptedReservations as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }

            $seance = $reservation->getSeance();
            $subject = trim((string) ($seance?->getMatiere() ?? ''));
            if ($subject === '') {
                continue;
            }

            if (!isset($subjectStats[$subject])) {
                $subjectStats[$subject] = 0;
            }

            $subjectStats[$subject] += 1;
        }

        return $subjectStats;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function generatePlannerDataWithFallback(
        User $student,
        array $payload,
        OpenAIService $openAIService,
        OpenAiMatchingService $openAiMatchingService
    ): array {
        $plannerData = $openAIService->generateRevisionPlanner($student, $payload);
        $hasPlannerEntries = isset($plannerData['planEntries'])
            && is_array($plannerData['planEntries'])
            && count($plannerData['planEntries']) > 0;
        $hasNoError = !isset($plannerData['error']) || $plannerData['error'] === null;

        if ($hasPlannerEntries && $hasNoError) {
            return $plannerData;
        }

        return $openAiMatchingService->generateRevisionPlanner($student, $payload);
    }

    /**
     * @param array<int, mixed> $planEntries
     * @param array<string, bool> $progressData
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPlannerDaysForView(array $planEntries, array $progressData = []): array
    {
        $normalizedProgressData = [];
        foreach ($progressData as $entryId => $isDone) {
            $entryIdNormalized = trim((string) $entryId);
            if ($entryIdNormalized === '' || !$isDone) {
                continue;
            }
            $normalizedProgressData[$entryIdNormalized] = true;
        }

        $groupedByDay = [];
        foreach (array_values($planEntries) as $globalIndex => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $day = trim((string) ($entry['day'] ?? ''));
            if ($day === '') {
                continue;
            }

            if (!isset($groupedByDay[$day])) {
                $groupedByDay[$day] = [];
            }

            $groupedByDay[$day][] = ['entry' => $entry, 'globalIndex' => $globalIndex];
        }

        if ($groupedByDay === []) {
            return [];
        }

        ksort($groupedByDay);
        $plannerDays = [];

        foreach ($groupedByDay as $day => $entries) {
            $cursorMinutes = 8 * 60 + 30;
            $normalizedEntries = [];

            foreach ($entries as $dayIndex => $entryPack) {
                if (!is_array($entryPack) || !isset($entryPack['entry']) || !is_array($entryPack['entry'])) {
                    continue;
                }
                $entry = $entryPack['entry'];
                $durationMin = max(30, (int) ($entry['durationMin'] ?? 60));
                $startClock = $this->minutesToClock($cursorMinutes);
                $endClock = $this->minutesToClock($cursorMinutes + $durationMin);
                $cursorMinutes += $durationMin + 20;

                $priorityRaw = trim((string) ($entry['priority'] ?? 'Normale'));
                $priorityNormalized = mb_strtolower($priorityRaw) === 'haute' ? 'Haute' : 'Normale';
                $entryId = $this->buildPlannerEntryId($day, (int) $dayIndex, $entry);

                $normalizedEntries[] = [
                    'entryId' => $entryId,
                    'subject' => trim((string) ($entry['subject'] ?? 'Revision generale')),
                    'phase' => trim((string) ($entry['phase'] ?? 'Consolidation')),
                    'durationMin' => $durationMin,
                    'priority' => $priorityNormalized,
                    'startClock' => $startClock,
                    'endClock' => $endClock,
                    'isCompleted' => isset($normalizedProgressData[$entryId]),
                ];
            }

            try {
                $dayDate = new \DateTimeImmutable($day);
                $shortLabel = $dayDate->format('d/m');
                $fullLabel = $dayDate->format('d/m/Y');
            } catch (\Throwable) {
                $shortLabel = $day;
                $fullLabel = $day;
            }

            $plannerDays[] = [
                'isoDate' => $day,
                'shortLabel' => $shortLabel,
                'fullLabel' => $fullLabel,
                'entries' => $normalizedEntries,
            ];
        }

        return $plannerDays;
    }

    private function minutesToClock(int $minutes): string
    {
        $minutesInDay = 24 * 60;
        $normalized = ($minutes % $minutesInDay + $minutesInDay) % $minutesInDay;

        return sprintf('%02d:%02d', intdiv($normalized, 60), $normalized % 60);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function buildPlannerEntryId(string $day, int $dayIndex, array $entry): string
    {
        return hash('sha256', implode('|', [
            $day,
            (string) $dayIndex,
            trim((string) ($entry['subject'] ?? '')),
            trim((string) ($entry['phase'] ?? '')),
            (string) ((int) ($entry['durationMin'] ?? 0)),
            trim((string) ($entry['priority'] ?? '')),
        ]));
    }

    /**
     * @param array<int, mixed> $planEntries
     *
     * @return array<string, true>
     */
    private function extractPlannerEntryIds(array $planEntries): array
    {
        $groupedByDay = [];
        foreach (array_values($planEntries) as $globalIndex => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $day = trim((string) ($entry['day'] ?? ''));
            if ($day === '') {
                continue;
            }

            if (!isset($groupedByDay[$day])) {
                $groupedByDay[$day] = [];
            }
            $groupedByDay[$day][] = ['entry' => $entry, 'globalIndex' => $globalIndex];
        }

        ksort($groupedByDay);
        $validIds = [];
        foreach ($groupedByDay as $day => $entries) {
            foreach ($entries as $dayIndex => $entryPack) {
                if (!is_array($entryPack) || !isset($entryPack['entry']) || !is_array($entryPack['entry'])) {
                    continue;
                }
                $entryId = $this->buildPlannerEntryId($day, (int) $dayIndex, $entryPack['entry']);
                $validIds[$entryId] = true;
            }
        }

        return $validIds;
    }

    private function normalizeDifficultyLevel(string $difficultyLevel): string
    {
        $normalized = mb_strtolower(trim($difficultyLevel));

        return match ($normalized) {
            'easy', 'facile' => 'easy',
            'hard', 'difficile' => 'hard',
            default => 'medium',
        };
    }

    /**
     * @param array<string, mixed> $plannerData
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function enrichPlannerDataForContext(array $plannerData, array $payload): array
    {
        $focusSubject = trim((string) ($payload['focusSubject'] ?? ''));
        $difficulty = $this->normalizeDifficultyLevel((string) ($payload['difficultyLevel'] ?? 'medium'));
        $difficultyLabel = match ($difficulty) {
            'easy' => 'facile',
            'hard' => 'difficile',
            default => 'moyen',
        };

        $examDate = $this->resolveExamDateForPlanner($payload, $plannerData);
        $today = new \DateTimeImmutable('today');
        $daysUntilExam = max(1, (int) $today->diff($examDate)->format('%a'));

        $existingTips = [];
        foreach ((array) ($plannerData['tips'] ?? []) as $tip) {
            $tipText = trim((string) $tip);
            if ($tipText !== '') {
                $existingTips[] = $tipText;
            }
        }

        $contextTips = [];
        if ($focusSubject !== '') {
            $contextTips[] = sprintf(
                'Pour %s, commence chaque jour par 20 minutes de rappel actif puis enchaine avec des exercices cibles.',
                $focusSubject
            );
        }

        if ($daysUntilExam <= 3) {
            $contextTips[] = 'Il reste peu de jours: priorise annales corrigees, erreurs frequentes et fiches resumees.';
        } elseif ($daysUntilExam <= 7) {
            $contextTips[] = 'A une semaine de l\'examen: alterne apprentissage, exercices et mini-simulations chronometrees.';
        } else {
            $contextTips[] = 'Planifie des revisions progressives: 60% pratique, 30% consolidation, 10% recap rapide.';
        }

        $contextTips[] = match ($difficulty) {
            'easy' => 'Niveau facile: valide les bases puis augmente progressivement la difficulte des exercices.',
            'hard' => 'Niveau difficile: decoupe chaque chapitre en micro-objectifs et termine chaque jour par une auto-evaluation.',
            default => 'Niveau moyen: combine rappel actif, exercices appliques et correction des erreurs dans la meme session.',
        };

        $mergedTips = [];
        foreach (array_merge($contextTips, $existingTips) as $tipText) {
            $normalizedTip = trim((string) $tipText);
            if ($normalizedTip === '' || in_array($normalizedTip, $mergedTips, true)) {
                continue;
            }
            if (mb_strlen($normalizedTip) > 200) {
                $normalizedTip = rtrim(mb_substr($normalizedTip, 0, 200)) . '...';
            }
            $mergedTips[] = $normalizedTip;
            if (count($mergedTips) >= 5) {
                break;
            }
        }

        $plannerData['tips'] = $mergedTips;
        $plannerData['difficultyLevel'] = $difficulty;
        $plannerData['daysUntilExam'] = $daysUntilExam;

        $existingSummary = trim((string) ($plannerData['summary'] ?? ''));
        $summaryParts = [];
        if ($focusSubject !== '') {
            $summaryParts[] = sprintf('Focus matiere: %s.', $focusSubject);
        }
        $summaryParts[] = sprintf('Niveau %s sur %d jour(s) avant examen.', $difficultyLabel, $daysUntilExam);
        if ($existingSummary !== '') {
            $summaryParts[] = $existingSummary;
        }
        $plannerData['summary'] = implode(' ', $summaryParts);

        if (!isset($plannerData['planEntries']) || !is_array($plannerData['planEntries'])) {
            $plannerData['planEntries'] = [];
        }
        if (!isset($plannerData['reminders']) || !is_array($plannerData['reminders'])) {
            $plannerData['reminders'] = [];
        }
        if (!isset($plannerData['metrics']) || !is_array($plannerData['metrics'])) {
            $plannerData['metrics'] = [
                'totalMinutes' => 0,
                'totalHours' => 0,
                'intensityScore' => 0,
                'revisionDays' => 0,
            ];
        }

        return $plannerData;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $plannerData
     */
    private function resolveExamDateForPlanner(array $payload, array $plannerData): \DateTimeImmutable
    {
        $payloadExamDate = trim((string) ($payload['examDate'] ?? ''));
        if ($payloadExamDate !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $payloadExamDate);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }

            try {
                return new \DateTimeImmutable($payloadExamDate);
            } catch (\Throwable) {
            }
        }

        $plannerExamDate = trim((string) ($plannerData['examDate'] ?? ''));
        if ($plannerExamDate !== '') {
            try {
                return new \DateTimeImmutable($plannerExamDate);
            } catch (\Throwable) {
            }
        }

        return new \DateTimeImmutable('tomorrow');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlannerRequestData(RevisionPlanner $planner): array
    {
        return [
            'examDate' => $planner->getExamDate()?->format('Y-m-d') ?? '',
            'dailySessions' => (int) ($planner->getDailySessions() ?? 2),
            'includeWeekend' => (bool) $planner->isIncludeWeekend(),
            'reminderTime' => (string) ($planner->getReminderTime() ?? '19:00'),
            'focusSubject' => (string) ($planner->getFocusSubject() ?? ''),
            'difficultyLevel' => $this->normalizeDifficultyLevel((string) ($planner->getDifficultyLevel() ?? 'medium')),
        ];
    }

    private function assertPlannerOwnedByStudent(RevisionPlanner $planner, User $student): void
    {
        if ($planner->getStudent()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas acceder a ce planner.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $plannerDays
     */
    private function buildPlannerPdfContent(RevisionPlanner $planner, array $plannerDays): string
    {
        $difficulty = $this->normalizeDifficultyLevel((string) ($planner->getDifficultyLevel() ?? 'medium'));
        $difficultyLabel = match ($difficulty) {
            'easy' => 'Facile',
            'hard' => 'Difficile',
            default => 'Moyen',
        };

        $lines = [
            'Planner de revision - Fahamni',
            '----------------------------------------',
            'ID planner: ' . (string) ($planner->getId() ?? 0),
            'Date examen: ' . ($planner->getExamDate()?->format('d/m/Y') ?? 'N/A'),
            'Matiere focus: ' . ((string) ($planner->getFocusSubject() ?: 'Non specifiee')),
            'Niveau: ' . $difficultyLabel,
            sprintf(
                'Progression: %d/%d sessions (%.1f%%)',
                (int) ($planner->getCompletedEntries() ?? 0),
                (int) ($planner->getTotalEntries() ?? 0),
                (float) ($planner->getCompletionRate() ?? 0.0)
            ),
            '',
        ];

        foreach ($plannerDays as $dayIndex => $day) {
            if (!is_array($day)) {
                continue;
            }

            $dayLabel = trim((string) ($day['fullLabel'] ?? ($day['isoDate'] ?? '')));
            $lines[] = 'Jour ' . (string) ($dayIndex + 1) . ' - ' . ($dayLabel !== '' ? $dayLabel : 'N/A');

            $entries = (array) ($day['entries'] ?? []);
            if ($entries === []) {
                $lines[] = '  - Aucune session';
                $lines[] = '';
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $isCompleted = (bool) ($entry['isCompleted'] ?? false);
                $status = $isCompleted ? '[x]' : '[ ]';
                $startClock = trim((string) ($entry['startClock'] ?? '--:--'));
                $endClock = trim((string) ($entry['endClock'] ?? '--:--'));
                $subject = trim((string) ($entry['subject'] ?? 'Revision'));
                $phase = trim((string) ($entry['phase'] ?? 'Consolidation'));
                $duration = max(0, (int) ($entry['durationMin'] ?? 0));
                $priority = trim((string) ($entry['priority'] ?? 'Normale'));

                $lines[] = sprintf(
                    '  %s %s-%s | %s | %s | %d min | %s',
                    $status,
                    $startClock,
                    $endClock,
                    $subject !== '' ? $subject : 'Revision',
                    $phase !== '' ? $phase : 'Consolidation',
                    $duration,
                    $priority !== '' ? $priority : 'Normale'
                );
            }
            $lines[] = '';
        }

        if (count($lines) === 8) {
            $lines[] = 'Aucune session disponible.';
        }

        $linesPerPage = 44;
        $pages = array_chunk($lines, $linesPerPage);
        if ($pages === []) {
            $pages = [['Planner vide']];
        }

        return $this->buildSimplePdf($pages);
    }

    /**
     * @param array<int, array<int, string>> $pages
     */
    private function buildSimplePdf(array $pages): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $fontObjectId = 3;
        $objects[$fontObjectId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pageObjectIds = [];
        foreach (array_values($pages) as $pageIndex => $pageLines) {
            $pageObjectId = 4 + ($pageIndex * 2);
            $contentObjectId = $pageObjectId + 1;
            $pageObjectIds[] = $pageObjectId;

            $stream = $this->buildPdfPageStream($pageLines);
            $objects[$pageObjectId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>',
                $fontObjectId,
                $contentObjectId
            );
            $objects[$contentObjectId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $kids, count($pageObjectIds));

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $objectId => $objectBody) {
            $offsets[$objectId] = strlen($pdf);
            $pdf .= $objectId . " 0 obj\n" . $objectBody . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObjectId = (int) max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObjectId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $maxObjectId; $i++) {
            $offset = $offsets[$i] ?? 0;
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }

        $pdf .= "trailer\n<< /Size " . ($maxObjectId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    /**
     * @param array<int, string> $lines
     */
    private function buildPdfPageStream(array $lines): string
    {
        $commands = [
            'BT',
            '/F1 11 Tf',
            '50 800 Td',
        ];

        $lineIndex = 0;
        foreach ($lines as $line) {
            if ($lineIndex > 0) {
                $commands[] = '0 -16 Td';
            }
            $commands[] = '(' . $this->escapePdfText($line) . ') Tj';
            $lineIndex++;
        }
        $commands[] = 'ET';

        return implode("\n", $commands) . "\n";
    }

    private function escapePdfText(string $value): string
    {
        $normalized = trim($value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($ascii) && $ascii !== '') {
            $normalized = $ascii;
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]/', ' ', $normalized) ?? $normalized;
        $normalized = str_replace('\\', '\\\\', $normalized);
        $normalized = str_replace('(', '\\(', $normalized);
        $normalized = str_replace(')', '\\)', $normalized);

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function applySeanceDataFromRequest(Seance $seance, Request $request): array
    {
        $errors = [];

        $matiere = trim((string) $request->request->get('matiere', ''));
        $description = trim((string) $request->request->get('description', ''));
        $startAtRaw = trim((string) $request->request->get('startAt', ''));
        $durationMin = (int) $request->request->get('durationMin', 0);
        $maxParticipants = (int) $request->request->get('maxParticipants', 0);
        $status = (int) $request->request->get('status', $seance->getStatus() ?? 1);

        if ($matiere === '') {
            $errors[] = 'La matiere est obligatoire.';
        }

        if ($durationMin < 15) {
            $errors[] = 'La duree doit etre superieure ou egale a 15 minutes.';
        }

        if ($maxParticipants < 1) {
            $errors[] = 'Le nombre max de participants doit etre superieur a 0.';
        }

        if ($status < 0) {
            $status = 0;
        }

        $startAt = null;
        if ($startAtRaw === '') {
            $errors[] = 'La date de debut est obligatoire.';
        } else {
            $startAt = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $startAtRaw);

            if (!$startAt) {
                try {
                    $startAt = new \DateTimeImmutable($startAtRaw);
                } catch (\Exception) {
                    $errors[] = 'Le format de la date de debut est invalide.';
                }
            }
        }

        if ($errors !== [] || !$startAt instanceof \DateTimeImmutable) {
            return $errors;
        }

        $seance->setMatiere($matiere);
        $seance->setDescription($description !== '' ? $description : null);
        $seance->setStartAt($startAt);
        $seance->setDurationMin($durationMin);
        $seance->setMaxParticipants($maxParticipants);
        $seance->setStatus($status);

        return [];
    }

    /**
     * @param array<int, mixed> $seances
     *
     * @return array<int, Seance>
     */
    private function filterSeancesBySimpleSearch(array $seances, string $query): array
    {
        $normalizedQuery = $this->normalizeSearchText($query);
        if ($normalizedQuery === '') {
            return array_values(array_filter(
                $seances,
                static fn($seance): bool => $seance instanceof Seance
            ));
        }

        $tokens = array_values(array_filter(
            preg_split('/\s+/', $normalizedQuery) ?: [],
            static fn(string $token): bool => $token !== ''
        ));

        if ($tokens === []) {
            return array_values(array_filter(
                $seances,
                static fn($seance): bool => $seance instanceof Seance
            ));
        }

        return array_values(array_filter(
            $seances,
            fn($seance): bool => $seance instanceof Seance && $this->seanceMatchesSearch($seance, $tokens)
        ));
    }

    /**
     * @param array<int, string> $tokens
     */
    private function seanceMatchesSearch(Seance $seance, array $tokens): bool
    {
        $startAt = $seance->getStartAt();

        $haystack = implode(' ', array_filter([
            $seance->getMatiere(),
            $seance->getDescription(),
            $seance->getTuteur()?->getFullName(),
            $seance->getTuteur()?->getEmail(),
            $startAt?->format('d/m/Y H:i'),
            $startAt?->format('Y-m-d'),
        ]));

        $normalizedHaystack = $this->normalizeSearchText($haystack);

        foreach ($tokens as $token) {
            if (!str_contains($normalizedHaystack, $token)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeSearchText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($ascii) && $ascii !== '') {
            $normalized = $ascii;
        }

        $normalized = preg_replace('/[^a-z0-9\/:\-\s]/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param array<string, mixed> $aiRawRecommendations
     * @param array<int, mixed> $allSeances
     *
     * @return array<string, mixed>
     */
    private function hydrateAiRecommendations(array $aiRawRecommendations, array $allSeances): array
    {
        /** @var array<int, Seance> $seanceById */
        $seanceById = [];
        /** @var array<int, User> $tutorById */
        $tutorById = [];

        foreach ($allSeances as $seance) {
            if (!$seance instanceof Seance) {
                continue;
            }

            $seanceId = $seance->getId();
            $tuteur = $seance->getTuteur();
            $tutorId = $tuteur?->getId();

            if ($seanceId !== null) {
                $seanceById[$seanceId] = $seance;
            }

            if ($tuteur instanceof User && $tutorId !== null && !isset($tutorById[$tutorId])) {
                $tutorById[$tutorId] = $tuteur;
            }
        }

        $recommendedSeances = [];
        foreach ((array) ($aiRawRecommendations['recommendedSeances'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $seanceId = (int) ($item['seanceId'] ?? 0);
            $seance = $seanceById[$seanceId] ?? null;
            if (!$seance instanceof Seance) {
                continue;
            }

            $recommendedSeances[] = [
                'seance' => $seance,
                'score' => is_numeric($item['score'] ?? null) ? (int) $item['score'] : null,
                'reason' => trim((string) ($item['reason'] ?? '')),
            ];
        }

        $recommendedTutors = [];
        foreach ((array) ($aiRawRecommendations['recommendedTutors'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $tutorId = (int) ($item['tutorId'] ?? 0);
            $tuteur = $tutorById[$tutorId] ?? null;
            if (!$tuteur instanceof User) {
                continue;
            }

            $recommendedTutors[] = [
                'tutor' => $tuteur,
                'score' => is_numeric($item['score'] ?? null) ? (int) $item['score'] : null,
                'reason' => trim((string) ($item['reason'] ?? '')),
            ];
        }

        return [
            'source' => (string) ($aiRawRecommendations['source'] ?? 'fallback'),
            'summary' => trim((string) ($aiRawRecommendations['summary'] ?? '')),
            'generatedAt' => (string) ($aiRawRecommendations['generatedAt'] ?? ''),
            'error' => trim((string) ($aiRawRecommendations['error'] ?? '')),
            'recommendedSeances' => $recommendedSeances,
            'recommendedTutors' => $recommendedTutors,
        ];
    }
}
