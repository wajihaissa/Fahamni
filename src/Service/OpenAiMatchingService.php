<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\User;
use App\Repository\RatingTutorRepository;
use App\Repository\ReservationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiMatchingService
{
    private const STATUS_ACCEPTED = Reservation::STATUS_ACCEPTED;
    private const STATUS_PAID = Reservation::STATUS_PAID;
    private const CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ReservationRepository $reservationRepository,
        private readonly RatingTutorRepository $ratingTutorRepository,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey = '',
        private readonly string $model = 'gpt-4o-mini',
        private readonly string $apiBaseUrl = 'https://api.openai.com/v1',
        private readonly int $requestTimeoutSeconds = 25,
        private readonly string $plannerApiKey = '',
        private readonly string $plannerModel = '',
        private readonly string $plannerApiBaseUrl = '',
        private readonly int $plannerTimeoutSeconds = 25
    ) {
    }

    /**
     * @param array<int, mixed> $allSeances
     * @param array<int, mixed> $myReservations
     *
     * @return array<string, mixed>
     */
    public function recommendForStudent(
        User $student,
        array $allSeances,
        array $myReservations,
        bool $forceRefresh = false
    ): array {
        $normalized = $this->normalizeContext($student, $allSeances, $myReservations);
        $candidates = $normalized['candidateSeances'];

        if ($candidates === []) {
            return [
                'source' => 'none',
                'summary' => 'Aucune seance candidate disponible pour le moment.',
                'recommendedSeances' => [],
                'recommendedTutors' => [],
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'error' => null,
            ];
        }

        $cacheKey = sprintf(
            'matching.ai.student.%d.%s',
            (int) ($student->getId() ?? 0),
            $normalized['fingerprint']
        );

        if ($forceRefresh) {
            return $this->computeRecommendation($normalized);
        }

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($normalized): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->computeRecommendation($normalized);
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function generateRevisionPlanner(User $student, array $input): array
    {
        $normalized = $this->normalizePlannerInput($input);

        if (isset($normalized['error'])) {
            return [
                'source' => 'none',
                'summary' => 'Generation impossible pour le moment.',
                'tips' => [],
                'planEntries' => [],
                'reminders' => [],
                'metrics' => [
                    'totalMinutes' => 0,
                    'totalHours' => 0,
                    'intensityScore' => 0,
                    'revisionDays' => 0,
                ],
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'error' => (string) $normalized['error'],
            ];
        }

        $plannerFailureReason = null;
        $openAiPlanner = $this->fetchOpenAiRevisionPlanner($student, $normalized, $plannerFailureReason);
        if ($openAiPlanner !== null) {
            if (!isset($openAiPlanner['examDate']) && $normalized['examDate'] instanceof \DateTimeImmutable) {
                $openAiPlanner['examDate'] = $normalized['examDate']->format(DATE_ATOM);
            }
            if (!isset($openAiPlanner['notified']) || !is_array($openAiPlanner['notified'])) {
                $openAiPlanner['notified'] = [];
            }
            $openAiPlanner['source'] = 'openai';
            $openAiPlanner['generatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
            $openAiPlanner['error'] = null;

            return $openAiPlanner;
        }

        $fallbackPlanner = $this->buildFallbackRevisionPlanner($normalized);
        if (!isset($fallbackPlanner['examDate']) && $normalized['examDate'] instanceof \DateTimeImmutable) {
            $fallbackPlanner['examDate'] = $normalized['examDate']->format(DATE_ATOM);
        }
        if (!isset($fallbackPlanner['notified']) || !is_array($fallbackPlanner['notified'])) {
            $fallbackPlanner['notified'] = [];
        }
        $fallbackPlanner['source'] = 'fallback';
        $fallbackPlanner['generatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $fallbackPlanner['error'] = $plannerFailureReason ?: 'Planner IA indisponible ou non configure.';

        return $fallbackPlanner;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalizePlannerInput(array $input): array
    {
        $examDateRaw = trim((string) ($input['examDate'] ?? ''));
        if ($examDateRaw === '') {
            return ['error' => 'Date d\'examen manquante.'];
        }

        $examDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $examDateRaw);
        if (!$examDate instanceof \DateTimeImmutable) {
            try {
                $examDate = new \DateTimeImmutable($examDateRaw);
                $examDate = $examDate->setTime(0, 0);
            } catch (\Throwable) {
                return ['error' => 'Date d\'examen invalide.'];
            }
        }

        $today = new \DateTimeImmutable('today');
        if ($examDate <= $today) {
            return ['error' => 'La date d\'examen doit etre dans le futur.'];
        }

        $dailySessions = max(1, min(4, (int) ($input['dailySessions'] ?? 2)));
        $includeWeekend = in_array($input['includeWeekend'] ?? false, [true, 1, '1', 'true', 'on'], true);

        $reminderTime = trim((string) ($input['reminderTime'] ?? '19:00'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $reminderTime)) {
            $reminderTime = '19:00';
        }

        $subjectCounter = [];
        $subjectStatsRaw = $input['subjects_stats'] ?? [];
        if (is_array($subjectStatsRaw)) {
            foreach ($subjectStatsRaw as $name => $count) {
                $subjectName = trim((string) $name);
                if ($subjectName === '') {
                    continue;
                }

                $subjectCounter[$subjectName] = max(1, (int) $count);
            }
        }

        if ($subjectCounter === [] && isset($input['subjects']) && is_array($input['subjects'])) {
            foreach ($input['subjects'] as $subject) {
                $subjectName = trim((string) $subject);
                if ($subjectName === '') {
                    continue;
                }

                if (!isset($subjectCounter[$subjectName])) {
                    $subjectCounter[$subjectName] = 0;
                }
                $subjectCounter[$subjectName] += 1;
            }
        }

        if ($subjectCounter === []) {
            $subjectCounter = [
                'Revision generale' => 2,
                'Exercices pratiques' => 1,
                'Annales' => 1,
            ];
        }

        arsort($subjectCounter);
        $subjects = [];
        foreach ($subjectCounter as $subjectName => $count) {
            $subjects[] = [
                'name' => $subjectName,
                'weight' => max(1, min(3, (int) $count)),
            ];
        }

        $revisionDates = [];
        $cursor = $today;
        while ($cursor < $examDate) {
            $weekDay = (int) $cursor->format('w');
            if ($includeWeekend || ($weekDay !== 0 && $weekDay !== 6)) {
                $revisionDates[] = $cursor->format('Y-m-d');
            }

            $cursor = $cursor->modify('+1 day');
        }

        if ($revisionDates === []) {
            $fallbackDate = $examDate->modify('-1 day');
            if ($fallbackDate <= $today) {
                $fallbackDate = $today->modify('+1 day');
            }
            $revisionDates[] = $fallbackDate->format('Y-m-d');
        }

        return [
            'examDate' => $examDate,
            'dailySessions' => $dailySessions,
            'includeWeekend' => $includeWeekend,
            'reminderTime' => $reminderTime,
            'subjects' => $subjects,
            'revisionDates' => $revisionDates,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function fetchOpenAiRevisionPlanner(User $student, array $context, ?string &$failureReason = null): ?array
    {
        $failureReason = null;
        $apiKey = $this->resolvePlannerApiKey();
        if ($apiKey === '') {
            $failureReason = 'Planner IA non configure: PLANNER_AI_API_KEY (ou OPENAI_API_KEY) manquant.';

            return null;
        }

        $payload = [
            'model' => $this->resolvePlannerModel(),
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => implode(' ', [
                        'You are an expert revision coach.',
                        'Return strictly valid JSON only with keys:',
                        '{"summary":"...",',
                        '"tips":["..."],',
                        '"schedule":[{"date":"YYYY-MM-DD","sessions":[{"subject":"...","phase":"...","duration_min":60,"priority":"high|normal"}]}],',
                        '"reminders":[{"at":"ISO8601","label":"..."}]}',
                        'Use only revision dates provided in the input.',
                        'Provide concise, practical coaching tips in French.',
                        'Do not include markdown.'
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'student_name' => $student->getFullName() ?? 'Etudiant',
                        'exam_date' => $context['examDate'] instanceof \DateTimeImmutable ? $context['examDate']->format('Y-m-d') : null,
                        'revision_dates' => $context['revisionDates'],
                        'daily_sessions' => $context['dailySessions'],
                        'include_weekend' => $context['includeWeekend'],
                        'reminder_time' => $context['reminderTime'],
                        'subject_weights' => $context['subjects'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];

        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($this->resolvePlannerApiBaseUrl(), '/') . '/chat/completions',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => max(5, $this->resolvePlannerTimeoutSeconds()),
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode < Response::HTTP_OK || $statusCode >= Response::HTTP_MULTIPLE_CHOICES) {
                $rawBody = (string) $response->getContent(false);
                $errorMessage = $this->extractOpenAiErrorMessage($rawBody);
                $failureReason = $this->buildPlannerAiFailureReason($statusCode, $errorMessage);
                $this->logger->warning('Planner AI request failed.', [
                    'statusCode' => $statusCode,
                    'errorMessage' => $errorMessage,
                ]);

                return null;
            }

            $json = $response->toArray(false);
            $content = (string) ($json['choices'][0]['message']['content'] ?? '');
            if ($content === '') {
                return null;
            }

            $decoded = $this->extractJsonObject($content);
            if (!is_array($decoded)) {
                $failureReason = 'Reponse Planner IA invalide.';

                return null;
            }

            return $this->normalizeOpenAiPlannerOutput($decoded, $context);
        } catch (TransportException|ExceptionInterface|\JsonException|\Throwable $exception) {
            $failureReason = 'Erreur reseau Planner IA: ' . $exception->getMessage();
            $this->logger->warning('Planner AI request exception.', [
                'message' => $exception->getMessage(),
                'exceptionClass' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function normalizeOpenAiPlannerOutput(array $output, array $context): ?array
    {
        $allowedDates = [];
        foreach ((array) ($context['revisionDates'] ?? []) as $date) {
            $dateValue = trim((string) $date);
            if ($dateValue !== '') {
                $allowedDates[$dateValue] = true;
            }
        }

        $subjectNames = [];
        foreach ((array) ($context['subjects'] ?? []) as $subject) {
            if (!is_array($subject)) {
                continue;
            }

            $subjectName = trim((string) ($subject['name'] ?? ''));
            if ($subjectName !== '') {
                $subjectNames[$subjectName] = true;
            }
        }

        if ($subjectNames === []) {
            $subjectNames['Revision generale'] = true;
        }

        $defaultSubject = (string) array_key_first($subjectNames);
        $planEntries = [];
        foreach ((array) ($output['schedule'] ?? []) as $day) {
            if (!is_array($day)) {
                continue;
            }

            $date = trim((string) ($day['date'] ?? ''));
            if (!isset($allowedDates[$date])) {
                continue;
            }

            $slot = 0;
            foreach ((array) ($day['sessions'] ?? []) as $session) {
                if (!is_array($session)) {
                    continue;
                }

                $subject = trim((string) ($session['subject'] ?? ''));
                if ($subject === '' || !isset($subjectNames[$subject])) {
                    $subject = $defaultSubject;
                }

                $phase = trim((string) ($session['phase'] ?? 'Consolidation'));
                if ($phase === '') {
                    $phase = 'Consolidation';
                }
                if (mb_strlen($phase) > 80) {
                    $phase = rtrim(mb_substr($phase, 0, 80)) . '...';
                }

                $duration = max(35, min(150, (int) ($session['duration_min'] ?? 75)));
                $priorityRaw = mb_strtolower(trim((string) ($session['priority'] ?? 'normal')));
                $priority = in_array($priorityRaw, ['high', 'haute', 'urgent'], true) ? 'Haute' : 'Normale';

                $planEntries[] = [
                    'day' => $date,
                    'subject' => $subject,
                    'phase' => $phase,
                    'durationMin' => $duration,
                    'priority' => $priority,
                ];

                $slot++;
                if ($slot >= 5) {
                    break;
                }
            }
        }

        if ($planEntries === []) {
            return null;
        }

        $tips = [];
        foreach ((array) ($output['tips'] ?? []) as $tip) {
            $tipText = trim((string) $tip);
            if ($tipText === '') {
                continue;
            }

            if (mb_strlen($tipText) > 180) {
                $tipText = rtrim(mb_substr($tipText, 0, 180)) . '...';
            }

            $tips[] = $tipText;
            if (count($tips) >= 5) {
                break;
            }
        }

        if ($tips === []) {
            $tips = [
                'Travaille en blocs courts et fais une pause de 10 minutes toutes les 60-90 minutes.',
                'Commence chaque session par un rappel actif (sans regarder le cours).',
                'Les 3 derniers jours: priorise annales, erreurs frequentes et fiches resumees.',
            ];
        }

        $summary = trim((string) ($output['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'Plan IA genere selon ta date d\'examen et tes priorites de matieres.';
        }
        if (mb_strlen($summary) > 320) {
            $summary = rtrim(mb_substr($summary, 0, 320)) . '...';
        }

        $reminders = $this->buildPlannerReminders((array) ($output['reminders'] ?? []), $context, $planEntries);
        $metrics = $this->buildPlannerMetrics($planEntries, count((array) ($context['revisionDates'] ?? [])));

        return [
            'summary' => $summary,
            'tips' => $tips,
            'planEntries' => $planEntries,
            'reminders' => $reminders,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildFallbackRevisionPlanner(array $context): array
    {
        $examDate = $context['examDate'];
        if (!$examDate instanceof \DateTimeImmutable) {
            return [
                'summary' => 'Generation locale impossible.',
                'tips' => [],
                'planEntries' => [],
                'reminders' => [],
                'metrics' => [
                    'totalMinutes' => 0,
                    'totalHours' => 0,
                    'intensityScore' => 0,
                    'revisionDays' => 0,
                ],
            ];
        }

        $revisionDates = array_values((array) ($context['revisionDates'] ?? []));
        $dailySessions = max(1, min(4, (int) ($context['dailySessions'] ?? 2)));

        $weightedPool = [];
        foreach ((array) ($context['subjects'] ?? []) as $subject) {
            if (!is_array($subject)) {
                continue;
            }

            $subjectName = trim((string) ($subject['name'] ?? ''));
            $weight = max(1, min(3, (int) ($subject['weight'] ?? 1)));
            if ($subjectName === '') {
                continue;
            }

            for ($i = 0; $i < $weight; $i++) {
                $weightedPool[] = $subjectName;
            }
        }

        if ($weightedPool === []) {
            $weightedPool = ['Revision generale', 'Exercices pratiques', 'Annales'];
        }

        $planEntries = [];
        $poolCursor = 0;
        $lastSubject = '';
        $dayMs = 24 * 60 * 60 * 1000;
        $revisionDatesCount = count($revisionDates);

        foreach ($revisionDates as $dayIndex => $day) {
            $dayDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $day);
            if (!$dayDate instanceof \DateTimeImmutable) {
                continue;
            }

            $daysLeft = max(1, (int) ceil(($examDate->getTimestamp() - $dayDate->getTimestamp()) / $dayMs));
            $sessionsToday = min(
                4,
                max(
                    1,
                    $dailySessions
                    + (($daysLeft <= 5 && $dailySessions < 4) ? 1 : 0)
                    - (($dayIndex === $revisionDatesCount - 1) ? 1 : 0)
                )
            );

            $recentSubjects = [];
            for ($slot = 0; $slot < $sessionsToday; $slot++) {
                $subject = (string) $weightedPool[$poolCursor % count($weightedPool)];
                $attempts = 0;
                while (($subject === $lastSubject || in_array($subject, $recentSubjects, true)) && $attempts < count($weightedPool)) {
                    $poolCursor++;
                    $subject = (string) $weightedPool[$poolCursor % count($weightedPool)];
                    $attempts++;
                }
                $poolCursor++;
                $lastSubject = $subject;
                $recentSubjects[] = $subject;
                if (count($recentSubjects) > 2) {
                    array_shift($recentSubjects);
                }

                $phaseData = $this->resolvePlannerPhase($daysLeft, $slot, $sessionsToday);

                $planEntries[] = [
                    'day' => $dayDate->format('Y-m-d'),
                    'subject' => $subject,
                    'phase' => $phaseData['phase'],
                    'durationMin' => $phaseData['durationMin'],
                    'priority' => $daysLeft <= 3 ? 'Haute' : 'Normale',
                ];
            }
        }

        $metrics = $this->buildPlannerMetrics($planEntries, $revisionDatesCount);
        $reminders = $this->buildPlannerReminders([], $context, $planEntries);

        return [
            'summary' => sprintf(
                'Plan genere localement: %d sessions, %.1f h de revision avant l\'examen.',
                count($planEntries),
                (float) $metrics['totalHours']
            ),
            'tips' => [
                'Bloque des sessions fixes dans la meme plage horaire pour stabiliser la concentration.',
                'Commence chaque jour par 10 minutes de rappel actif des notions de la veille.',
                'A partir de J-3, privilegie annales chronometrees + correction de tes erreurs types.',
            ],
            'planEntries' => $planEntries,
            'reminders' => $reminders,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param array<int, mixed> $rawReminders
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $planEntries
     *
     * @return array<int, array<string, string>>
     */
    private function buildPlannerReminders(array $rawReminders, array $context, array $planEntries): array
    {
        $reminders = [];
        $seen = [];
        $now = new \DateTimeImmutable();

        foreach ($rawReminders as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? 'Rappel revision'));
            $atRaw = trim((string) ($item['at'] ?? ''));
            if ($label === '' || $atRaw === '') {
                continue;
            }

            try {
                $at = new \DateTimeImmutable($atRaw);
            } catch (\Throwable) {
                continue;
            }

            if ($at <= $now->modify('-2 minutes')) {
                continue;
            }

            $id = hash('sha256', $at->format(DATE_ATOM) . '|' . $label);
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $reminders[] = [
                'id' => $id,
                'at' => $at->format(DATE_ATOM),
                'label' => $label,
            ];

            if (count($reminders) >= 20) {
                break;
            }
        }

        if ($reminders !== []) {
            usort(
                $reminders,
                static fn(array $a, array $b): int => strcmp($a['at'], $b['at'])
            );

            return $reminders;
        }

        $reminderTime = (string) ($context['reminderTime'] ?? '19:00');
        [$hour, $minute] = array_map('intval', explode(':', $reminderTime . ':00'));

        $daySubjects = [];
        foreach ($planEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $day = trim((string) ($entry['day'] ?? ''));
            $subject = trim((string) ($entry['subject'] ?? 'Revision'));
            if ($day === '' || $subject === '') {
                continue;
            }

            if (!isset($daySubjects[$day])) {
                $daySubjects[$day] = $subject;
            }
        }

        foreach ($daySubjects as $day => $subject) {
            try {
                $at = (new \DateTimeImmutable($day . ' 00:00:00'))->setTime($hour, $minute);
            } catch (\Throwable) {
                continue;
            }

            if ($at <= $now->modify('-2 minutes')) {
                continue;
            }

            $label = 'Session du jour: ' . $subject;
            $id = hash('sha256', $at->format(DATE_ATOM) . '|' . $label);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $reminders[] = [
                'id' => $id,
                'at' => $at->format(DATE_ATOM),
                'label' => $label,
            ];
        }

        $examDate = $context['examDate'] ?? null;
        if ($examDate instanceof \DateTimeImmutable) {
            foreach ([7, 3, 1] as $offset) {
                $at = $examDate->modify('-' . $offset . ' day')->setTime($hour, $minute);
                if ($at <= $now->modify('-2 minutes')) {
                    continue;
                }

                $label = sprintf('J-%d: revise les points critiques', $offset);
                $id = hash('sha256', $at->format(DATE_ATOM) . '|' . $label);
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $reminders[] = [
                    'id' => $id,
                    'at' => $at->format(DATE_ATOM),
                    'label' => $label,
                ];
            }

            $examMorning = $examDate->setTime(7, 30);
            if ($examMorning > $now->modify('-2 minutes')) {
                $label = 'Jour J: relire fiches resumees et rester confiant';
                $id = hash('sha256', $examMorning->format(DATE_ATOM) . '|' . $label);
                if (!isset($seen[$id])) {
                    $reminders[] = [
                        'id' => $id,
                        'at' => $examMorning->format(DATE_ATOM),
                        'label' => $label,
                    ];
                }
            }
        }

        usort(
            $reminders,
            static fn(array $a, array $b): int => strcmp($a['at'], $b['at'])
        );

        return array_slice($reminders, 0, 20);
    }

    /**
     * @param array<int, array<string, mixed>> $planEntries
     *
     * @return array<string, int|float>
     */
    private function buildPlannerMetrics(array $planEntries, int $revisionDays): array
    {
        $totalMinutes = 0;
        foreach ($planEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $totalMinutes += max(0, (int) ($entry['durationMin'] ?? 0));
        }

        $totalHours = round($totalMinutes / 60, 1);
        $intensityScore = min(100, (int) round(($totalMinutes / max(1, $revisionDays * 90)) * 100));

        return [
            'totalMinutes' => $totalMinutes,
            'totalHours' => $totalHours,
            'intensityScore' => $intensityScore,
            'revisionDays' => max(1, $revisionDays),
        ];
    }

    /**
     * @return array{phase: string, durationMin: int}
     */
    private function resolvePlannerPhase(int $daysLeft, int $slotIndex, int $slotsToday): array
    {
        $phase = 'Consolidation';
        $durationMin = 80;

        if ($daysLeft > 14) {
            $phase = 'Apprentissage actif';
            $durationMin = 65;
        } elseif ($daysLeft > 7) {
            $phase = 'Exercices cibles';
            $durationMin = 75;
        } elseif ($daysLeft > 3) {
            $phase = 'Simulation annales';
            $durationMin = 90;
        } else {
            $phase = 'Revision finale';
            $durationMin = 55;
        }

        if ($daysLeft <= 7 && $slotIndex === $slotsToday - 1) {
            $durationMin = max(35, $durationMin - 20);
        }

        return [
            'phase' => $phase,
            'durationMin' => $durationMin,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function computeRecommendation(array $context): array
    {
        $failureReason = null;
        $openAiResult = $this->fetchOpenAiRecommendations($context, $failureReason);

        if ($openAiResult !== null) {
            return [
                'source' => 'openai',
                'summary' => $openAiResult['summary'] ?: 'Recommandations generees par IA.',
                'recommendedSeances' => $openAiResult['recommendedSeances'],
                'recommendedTutors' => $openAiResult['recommendedTutors'],
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'error' => null,
            ];
        }

        $fallback = $this->buildFallbackRecommendations($context);
        if ($failureReason !== null && $failureReason !== '') {
            $fallback['error'] = $failureReason;
        }
        $fallback['generatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);

        return $fallback;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function fetchOpenAiRecommendations(array $context, ?string &$failureReason = null): ?array
    {
        $failureReason = null;
        $apiKey = trim($this->apiKey);
        if ($apiKey === '') {
            $failureReason = 'OpenAI non configure: OPENAI_API_KEY manquant.';

            return null;
        }

        $payload = [
            'model' => trim($this->model) !== '' ? $this->model : 'gpt-4o-mini',
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => implode(' ', [
                        'You are an advanced tutor matching engine for an educational platform.',
                        'You receive student profile and candidate sessions.',
                        'Return strictly valid JSON with this exact shape:',
                        '{"summary":"...",',
                        '"recommended_seances":[{"seance_id":123,"score":0-100,"reason":"..."}],',
                        '"recommended_tutors":[{"tutor_id":456,"score":0-100,"reason":"..."}]}',
                        'Use only IDs from the provided candidates.',
                        'Select up to 3 sessions and up to 3 tutors.',
                        'Do not include markdown.'
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'student_profile' => $context['studentProfile'],
                        'candidate_seances' => $context['candidateSeances'],
                        'tutor_stats' => $context['tutorStats'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];

        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($this->apiBaseUrl, '/') . '/chat/completions',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => max(5, $this->requestTimeoutSeconds),
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode < Response::HTTP_OK || $statusCode >= Response::HTTP_MULTIPLE_CHOICES) {
                $rawBody = (string) $response->getContent(false);
                $errorMessage = $this->extractOpenAiErrorMessage($rawBody);
                $failureReason = $this->buildOpenAiFailureReason($statusCode, $errorMessage);
                $this->logger->warning('OpenAI matching request failed.', [
                    'statusCode' => $statusCode,
                    'errorMessage' => $errorMessage,
                ]);

                return null;
            }

            $json = $response->toArray(false);
            $content = (string) ($json['choices'][0]['message']['content'] ?? '');
            if ($content === '') {
                return null;
            }

            $decoded = $this->extractJsonObject($content);
            if (!is_array($decoded)) {
                $failureReason = 'Reponse OpenAI invalide.';

                return null;
            }

            return $this->normalizeOpenAiOutput($decoded, $context);
        } catch (TransportException|ExceptionInterface|\JsonException|\Throwable $exception) {
            $failureReason = 'Erreur reseau OpenAI: ' . $exception->getMessage();
            $this->logger->warning('OpenAI matching request exception.', [
                'message' => $exception->getMessage(),
                'exceptionClass' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normalizeOpenAiOutput(array $output, array $context): array
    {
        $validSeanceIds = [];
        $candidateSeances = $context['candidateSeances'] ?? [];
        foreach ($candidateSeances as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $id = (int) ($candidate['seance_id'] ?? 0);
            if ($id > 0) {
                $validSeanceIds[$id] = true;
            }
        }

        $validTutorIds = [];
        $tutorStats = $context['tutorStats'] ?? [];
        foreach ($tutorStats as $stats) {
            if (!is_array($stats)) {
                continue;
            }

            $id = (int) ($stats['tutor_id'] ?? 0);
            if ($id > 0) {
                $validTutorIds[$id] = true;
            }
        }

        $recommendedSeances = [];
        foreach ((array) ($output['recommended_seances'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $seanceId = (int) ($item['seance_id'] ?? 0);
            if (!isset($validSeanceIds[$seanceId])) {
                continue;
            }

            $recommendedSeances[$seanceId] = [
                'seanceId' => $seanceId,
                'score' => $this->normalizeScore($item['score'] ?? null),
                'reason' => $this->normalizeReason($item['reason'] ?? ''),
            ];
        }

        $recommendedTutors = [];
        foreach ((array) ($output['recommended_tutors'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $tutorId = (int) ($item['tutor_id'] ?? 0);
            if (!isset($validTutorIds[$tutorId])) {
                continue;
            }

            $recommendedTutors[$tutorId] = [
                'tutorId' => $tutorId,
                'score' => $this->normalizeScore($item['score'] ?? null),
                'reason' => $this->normalizeReason($item['reason'] ?? ''),
            ];
        }

        return [
            'summary' => $this->normalizeReason((string) ($output['summary'] ?? '')),
            'recommendedSeances' => array_slice(array_values($recommendedSeances), 0, 3),
            'recommendedTutors' => array_slice(array_values($recommendedTutors), 0, 3),
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildFallbackRecommendations(array $context): array
    {
        $preferredSubjects = (array) (($context['studentProfile']['preferred_subjects'] ?? []));
        $preferredTimeBands = (array) (($context['studentProfile']['preferred_time_bands'] ?? []));
        $candidateSeances = (array) ($context['candidateSeances'] ?? []);
        $tutorStatsById = [];

        foreach ((array) ($context['tutorStats'] ?? []) as $stats) {
            if (!is_array($stats)) {
                continue;
            }

            $tutorId = (int) ($stats['tutor_id'] ?? 0);
            if ($tutorId > 0) {
                $tutorStatsById[$tutorId] = $stats;
            }
        }

        $scoredSeances = [];
        foreach ($candidateSeances as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $subject = mb_strtolower((string) ($candidate['matiere'] ?? ''));
            $subjectWeight = (int) ($preferredSubjects[$subject] ?? 0);
            $timeBand = (string) ($candidate['time_band'] ?? 'afternoon');
            $timeBandWeight = (int) ($preferredTimeBands[$timeBand] ?? 0);
            $tutorId = (int) ($candidate['tutor_id'] ?? 0);
            $avgRating = (float) (($tutorStatsById[$tutorId]['avg_rating'] ?? 0.0));
            $daysUntil = (int) ($candidate['days_until'] ?? 999);

            $score = 30;
            $score += min(35, $subjectWeight * 9);
            $score += min(15, $timeBandWeight * 3);
            $score += max(0, min(20, (int) round($avgRating * 4)));

            if ($daysUntil <= 2) {
                $score += 10;
            } elseif ($daysUntil <= 7) {
                $score += 6;
            } elseif ($daysUntil <= 14) {
                $score += 3;
            }

            $score = max(0, min(100, $score));

            $scoredSeances[] = [
                'seanceId' => (int) ($candidate['seance_id'] ?? 0),
                'tutorId' => $tutorId,
                'score' => $score,
                'reason' => $this->buildFallbackReason($candidate, $subjectWeight, $timeBandWeight, $avgRating),
            ];
        }

        usort(
            $scoredSeances,
            static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
        );

        $recommendedSeances = array_slice($scoredSeances, 0, 3);

        $tutorScores = [];
        foreach ($recommendedSeances as $seance) {
            $tutorId = (int) ($seance['tutorId'] ?? 0);
            if ($tutorId <= 0) {
                continue;
            }

            if (!isset($tutorScores[$tutorId])) {
                $tutorScores[$tutorId] = 0;
            }

            $tutorScores[$tutorId] += (int) ($seance['score'] ?? 0);
        }

        foreach ($tutorStatsById as $tutorId => $stats) {
            if (!isset($tutorScores[$tutorId])) {
                $tutorScores[$tutorId] = 0;
            }

            $tutorScores[$tutorId] += max(0, min(30, (int) round(((float) ($stats['avg_rating'] ?? 0.0)) * 6)));
            $tutorScores[$tutorId] += max(0, min(20, ((int) ($stats['future_seances'] ?? 0)) * 3));
        }

        arsort($tutorScores);
        $recommendedTutors = [];
        foreach (array_slice($tutorScores, 0, 3, true) as $tutorId => $score) {
            $stats = $tutorStatsById[(int) $tutorId] ?? [];
            $avgRating = (float) ($stats['avg_rating'] ?? 0.0);
            $futureSeances = (int) ($stats['future_seances'] ?? 0);

            $recommendedTutors[] = [
                'tutorId' => (int) $tutorId,
                'score' => max(0, min(100, (int) $score)),
                'reason' => sprintf(
                    'Bon potentiel pedagogique (%.1f/5) avec %d seance(s) a venir.',
                    $avgRating,
                    $futureSeances
                ),
            ];
        }

        return [
            'source' => 'fallback',
            'summary' => 'Recommandations generees localement (mode secours) selon ton historique et les disponibilites.',
            'recommendedSeances' => $recommendedSeances,
            'recommendedTutors' => $recommendedTutors,
            'error' => 'OpenAI indisponible ou non configure.',
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function buildFallbackReason(
        array $candidate,
        int $subjectWeight,
        int $timeBandWeight,
        float $avgRating
    ): string {
        $parts = [];
        if ($subjectWeight > 0) {
            $parts[] = 'Matiere proche de ton historique';
        }

        if ($timeBandWeight > 0) {
            $parts[] = 'Creneau compatible avec tes habitudes';
        }

        if ($avgRating > 0) {
            $parts[] = sprintf('Tuteur note %.1f/5', $avgRating);
        }

        if ($parts === []) {
            $parts[] = 'Bon compromis entre disponibilite et qualite';
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildPromptFingerprint(array $context): string
    {
        $payload = [
            'studentId' => $context['studentId'] ?? 0,
            'candidateSeances' => $context['candidateSeances'] ?? [],
            'studentProfile' => $context['studentProfile'] ?? [],
            'tutorStats' => $context['tutorStats'] ?? [],
        ];

        return substr(hash('sha256', json_encode($payload)), 0, 20);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractJsonObject(string $decodedString): ?array
    {
        $decodedString = trim($decodedString);
        if ($decodedString === '') {
            return null;
        }

        $decoded = json_decode($decodedString, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (!preg_match('/\{.*\}/s', $decodedString, $matches)) {
            return null;
        }

        $decoded = json_decode((string) $matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }

    private function extractOpenAiErrorMessage(string $rawBody): string
    {
        $rawBody = trim($rawBody);
        if ($rawBody === '') {
            return '';
        }

        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $message = trim((string) ($decoded['error']['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return '';
    }

    private function buildOpenAiFailureReason(int $statusCode, string $errorMessage = ''): string
    {
        return match ($statusCode) {
            401 => 'OpenAI: cle API invalide ou revoquee.',
            403 => 'OpenAI: acces refuse (projet sans droits sur le modele).',
            404 => 'OpenAI: modele ou endpoint introuvable.',
            429 => 'OpenAI: quota limite ou rate limit atteint (HTTP 429). Verifie billing et credits.',
            default => 'OpenAI indisponible (HTTP ' . $statusCode . ')' . ($errorMessage !== '' ? ': ' . $errorMessage : '.'),
        };
    }

    private function buildPlannerAiFailureReason(int $statusCode, string $errorMessage = ''): string
    {
        return match ($statusCode) {
            401 => 'Planner IA: cle API invalide ou revoquee.',
            403 => 'Planner IA: acces refuse (modele ou projet non autorise).',
            404 => 'Planner IA: modele ou endpoint introuvable.',
            429 => 'Planner IA: quota limite ou rate limit atteint (HTTP 429).',
            default => 'Planner IA indisponible (HTTP ' . $statusCode . ')' . ($errorMessage !== '' ? ': ' . $errorMessage : '.'),
        };
    }

    private function resolvePlannerApiKey(): string
    {
        $plannerApiKey = trim($this->plannerApiKey);
        if ($plannerApiKey !== '') {
            return $plannerApiKey;
        }

        return trim($this->apiKey);
    }

    private function resolvePlannerModel(): string
    {
        $plannerModel = trim($this->plannerModel);
        if ($plannerModel !== '') {
            return $plannerModel;
        }

        $matchingModel = trim($this->model);

        return $matchingModel !== '' ? $matchingModel : 'gpt-4o-mini';
    }

    private function resolvePlannerApiBaseUrl(): string
    {
        $plannerApiBaseUrl = trim($this->plannerApiBaseUrl);
        if ($plannerApiBaseUrl !== '') {
            return $plannerApiBaseUrl;
        }

        $matchingApiBaseUrl = trim($this->apiBaseUrl);

        return $matchingApiBaseUrl !== '' ? $matchingApiBaseUrl : 'https://api.openai.com/v1';
    }

    private function resolvePlannerTimeoutSeconds(): int
    {
        if ($this->plannerTimeoutSeconds > 0) {
            return $this->plannerTimeoutSeconds;
        }

        return $this->requestTimeoutSeconds;
    }

    private function normalizeScore(mixed $score): int
    {
        $value = is_numeric($score) ? (int) round((float) $score) : 50;

        return max(0, min(100, $value));
    }

    private function normalizeReason(mixed $reason): string
    {
        $value = trim((string) $reason);
        if ($value === '') {
            return 'Correspond bien a ton profil de progression.';
        }

        if (mb_strlen($value) > 220) {
            return rtrim(mb_substr($value, 0, 220)) . '...';
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $allSeances
     * @param array<int, mixed> $myReservations
     *
     * @return array<string, mixed>
     */
    private function normalizeContext(User $student, array $allSeances, array $myReservations): array
    {
        $now = new \DateTimeImmutable();
        $studentId = (int) ($student->getId() ?? 0);
        $alreadyReservedIds = [];
        $subjectStats = [];
        $timeBandStats = ['morning' => 0, 'afternoon' => 0, 'evening' => 0];
        $acceptedCount = 0;

        foreach ($myReservations as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }

            $seance = $reservation->getSeance();
            $startAt = $seance?->getStartAt();
            $seanceId = $seance?->getId();

            if ($seanceId !== null) {
                $alreadyReservedIds[$seanceId] = true;
            }

            if (!$seance instanceof Seance || !$startAt instanceof \DateTimeImmutable) {
                continue;
            }

            $subject = mb_strtolower(trim((string) $seance->getMatiere()));
            if ($subject === '') {
                continue;
            }

            $reservationStatus = (int) ($reservation->getStatus() ?? Reservation::STATUS_PENDING);
            $weight = in_array($reservationStatus, [self::STATUS_ACCEPTED, self::STATUS_PAID], true) ? 3 : 1;
            if ($startAt < $now) {
                $weight += 1;
            }

            if (!isset($subjectStats[$subject])) {
                $subjectStats[$subject] = 0;
            }
            $subjectStats[$subject] += $weight;

            $timeBand = $this->resolveTimeBand((int) $startAt->format('H'));
            $timeBandStats[$timeBand] += $weight;

            if (in_array($reservationStatus, [self::STATUS_ACCEPTED, self::STATUS_PAID], true)) {
                $acceptedCount++;
            }
        }

        $candidateSeances = [];
        $seanceIds = [];
        $tutorIds = [];

        foreach ($allSeances as $seance) {
            if (!$seance instanceof Seance) {
                continue;
            }

            $seanceId = $seance->getId();
            $startAt = $seance->getStartAt();
            $tutor = $seance->getTuteur();
            $tutorId = $tutor?->getId();

            if (
                $seanceId === null
                || !$startAt instanceof \DateTimeImmutable
                || !$tutor instanceof User
                || $tutorId === null
                || $tutorId === $studentId
                || $startAt < $now
                || (int) ($seance->getStatus() ?? 0) !== 1
                || isset($alreadyReservedIds[$seanceId])
            ) {
                continue;
            }

            $daysUntil = (int) $now->diff($startAt)->format('%a');
            $timeBand = $this->resolveTimeBand((int) $startAt->format('H'));

            $candidateSeances[] = [
                'seance_id' => $seanceId,
                'matiere' => (string) ($seance->getMatiere() ?? 'Seance'),
                'description' => mb_substr(trim((string) ($seance->getDescription() ?? '')), 0, 280),
                'start_at' => $startAt->format(DATE_ATOM),
                'duration_min' => (int) ($seance->getDurationMin() ?? 0),
                'max_participants' => (int) ($seance->getMaxParticipants() ?? 0),
                'days_until' => $daysUntil,
                'time_band' => $timeBand,
                'tutor_id' => (int) $tutorId,
                'tutor_name' => (string) ($tutor->getFullName() ?? 'Tuteur'),
            ];

            $seanceIds[] = (int) $seanceId;
            $tutorIds[] = (int) $tutorId;
        }

        $seanceReservationCounts = $this->loadReservationCounts($seanceIds);
        $tutorStats = $this->loadTutorStats($tutorIds, $now);

        foreach ($candidateSeances as &$candidateSeance) {
            $seanceId = (int) ($candidateSeance['seance_id'] ?? 0);
            $tutorId = (int) ($candidateSeance['tutor_id'] ?? 0);
            $reservedCount = (int) ($seanceReservationCounts[$seanceId] ?? 0);
            $maxParticipants = (int) ($candidateSeance['max_participants'] ?? 0);
            $remainingSeats = $maxParticipants > 0 ? max(0, $maxParticipants - $reservedCount) : 0;
            $candidateSeance['reserved_count'] = $reservedCount;
            $candidateSeance['remaining_seats'] = $remainingSeats;
            $candidateSeance['tutor_avg_rating'] = (float) (($tutorStats[$tutorId]['avg_rating'] ?? 0.0));
            $candidateSeance['tutor_review_count'] = (int) (($tutorStats[$tutorId]['review_count'] ?? 0));
        }
        unset($candidateSeance);

        arsort($subjectStats);
        arsort($timeBandStats);

        $studentProfile = [
            'student_id' => $studentId,
            'student_name' => $student->getFullName() ?? 'Etudiant',
            'accepted_reservations' => $acceptedCount,
            'total_reservations' => count($myReservations),
            'preferred_subjects' => $subjectStats,
            'preferred_time_bands' => $timeBandStats,
        ];

        $context = [
            'studentId' => $studentId,
            'studentProfile' => $studentProfile,
            'candidateSeances' => $candidateSeances,
            'tutorStats' => array_values($tutorStats),
        ];
        $context['fingerprint'] = $this->buildPromptFingerprint($context);

        return $context;
    }

    private function resolveTimeBand(int $hour): string
    {
        if ($hour < 12) {
            return 'morning';
        }

        if ($hour < 18) {
            return 'afternoon';
        }

        return 'evening';
    }

    /**
     * @param array<int, int> $seanceIds
     *
     * @return array<int, int>
     */
    private function loadReservationCounts(array $seanceIds): array
    {
        $seanceIds = array_values(array_unique(array_filter($seanceIds, static fn(int $id): bool => $id > 0)));

        if ($seanceIds === []) {
            return [];
        }

        $rows = $this->reservationRepository->createQueryBuilder('r')
            ->select('IDENTITY(r.seance) AS seanceId, COUNT(r.id) AS reservationsCount')
            ->andWhere('r.seance IN (:seanceIds)')
            ->setParameter('seanceIds', $seanceIds)
            ->groupBy('r.seance')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $seanceId = (int) ($row['seanceId'] ?? 0);
            if ($seanceId > 0) {
                $counts[$seanceId] = (int) ($row['reservationsCount'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * @param array<int, int> $tutorIds
     *
     * @return array<int, array<string, int|float>>
     */
    private function loadTutorStats(array $tutorIds, \DateTimeImmutable $now): array
    {
        $tutorIds = array_values(array_unique(array_filter($tutorIds, static fn(int $id): bool => $id > 0)));
        if ($tutorIds === []) {
            return [];
        }

        $ratingRows = $this->ratingTutorRepository->createQueryBuilder('rt')
            ->select('IDENTITY(rt.tuteur) AS tutorId, AVG(rt.note) AS avgRating, COUNT(rt.id) AS reviewCount')
            ->andWhere('rt.tuteur IN (:tutorIds)')
            ->setParameter('tutorIds', $tutorIds)
            ->groupBy('rt.tuteur')
            ->getQuery()
            ->getArrayResult();

        $futureRows = $this->reservationRepository->getEntityManager()
            ->createQueryBuilder()
            ->select('IDENTITY(s.tuteur) AS tutorId, COUNT(s.id) AS futureSeances')
            ->from(Seance::class, 's')
            ->andWhere('s.tuteur IN (:tutorIds)')
            ->andWhere('s.startAt >= :now')
            ->andWhere('s.status = :activeStatus')
            ->setParameter('tutorIds', $tutorIds)
            ->setParameter('now', $now)
            ->setParameter('activeStatus', 1)
            ->groupBy('s.tuteur')
            ->getQuery()
            ->getArrayResult();

        $stats = [];
        foreach ($tutorIds as $tutorId) {
            $stats[$tutorId] = [
                'tutor_id' => $tutorId,
                'avg_rating' => 0.0,
                'review_count' => 0,
                'future_seances' => 0,
            ];
        }

        foreach ($ratingRows as $row) {
            $tutorId = (int) ($row['tutorId'] ?? 0);
            if ($tutorId <= 0 || !isset($stats[$tutorId])) {
                continue;
            }

            $stats[$tutorId]['avg_rating'] = round((float) ($row['avgRating'] ?? 0.0), 2);
            $stats[$tutorId]['review_count'] = (int) ($row['reviewCount'] ?? 0);
        }

        foreach ($futureRows as $row) {
            $tutorId = (int) ($row['tutorId'] ?? 0);
            if ($tutorId <= 0 || !isset($stats[$tutorId])) {
                continue;
            }

            $stats[$tutorId]['future_seances'] = (int) ($row['futureSeances'] ?? 0);
        }

        return $stats;
    }
}
