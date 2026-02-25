<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAIService
{
    private HttpClientInterface $client;
    private string $token;
    private string $endpoint = 'https://models.github.ai/inference';
    private string $model;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        $this->token = trim((string) ($_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?: ''));
        $this->model = trim((string) ($_ENV['GITHUB_AI_MODEL'] ?? getenv('GITHUB_AI_MODEL') ?: 'openai/gpt-4o'));
    }

    public function askQuestion(string $question): string
    {
        $response = $this->client->request('POST', $this->endpoint . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $question
                    ]
                ],
                'temperature' => 1.0,
                'top_p' => 1.0,
                'max_tokens' => 1000,
            ]
        ]);

        $data = $response->toArray();

        return $data['choices'][0]['message']['content'] ?? 'No response';
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
                'source' => 'openai_service',
                'summary' => 'Generation impossible pour le moment.',
                'tips' => [],
                'planEntries' => [],
                'reminders' => [],
                'metrics' => $this->emptyMetrics(),
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'error' => (string) $normalized['error'],
            ];
        }

        if ($this->token === '') {
            return [
                'source' => 'openai_service',
                'summary' => 'Planner IA indisponible.',
                'tips' => [],
                'planEntries' => [],
                'reminders' => [],
                'metrics' => $this->emptyMetrics(),
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'error' => 'GITHUB_TOKEN manquant.',
            ];
        }

        $payload = [
            'model' => $this->model,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => implode(' ', [
                        'You are an expert revision coach.',
                        'Return strict JSON only with keys:',
                        '{"summary":"...",',
                        '"tips":["..."],',
                        '"schedule":[{"date":"YYYY-MM-DD","sessions":[{"subject":"...","phase":"...","duration_min":60,"priority":"high|normal"}]}],',
                        '"reminders":[{"at":"ISO8601","label":"..."}]}',
                        'Use only revision dates provided.',
                        'Tips must be concise and in French.',
                        'Tips must be personalized based on focus_subject, days_until_exam and difficulty_level.',
                        'No markdown.'
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'student_name' => $student->getFullName() ?? 'Etudiant',
                        'exam_date' => $normalized['examDateIso'],
                        'revision_dates' => $normalized['revisionDates'],
                        'daily_sessions' => $normalized['dailySessions'],
                        'include_weekend' => $normalized['includeWeekend'],
                        'reminder_time' => $normalized['reminderTime'],
                        'subject_weights' => $normalized['subjects'],
                        'focus_subject' => $normalized['focusSubject'],
                        'difficulty_level' => $normalized['difficultyLevel'],
                        'days_until_exam' => $normalized['daysUntilExam'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'max_tokens' => 1600,
            'top_p' => 1.0,
        ];

        try {
            $response = $this->client->request('POST', rtrim($this->endpoint, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 25,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < Response::HTTP_OK || $statusCode >= Response::HTTP_MULTIPLE_CHOICES) {
                $body = (string) $response->getContent(false);
                $errorMessage = $this->extractErrorMessage($body);

                return [
                    'source' => 'openai_service',
                    'summary' => 'Planner IA indisponible.',
                    'tips' => [],
                    'planEntries' => [],
                    'reminders' => [],
                    'metrics' => $this->emptyMetrics(),
                    'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'error' => $this->buildFailureReason($statusCode, $errorMessage),
                ];
            }

            $data = $response->toArray(false);
            $content = (string) ($data['choices'][0]['message']['content'] ?? '');
            $decoded = $this->extractJsonObject($content);
            if (!is_array($decoded)) {
                return [
                    'source' => 'openai_service',
                    'summary' => 'Planner IA indisponible.',
                    'tips' => [],
                    'planEntries' => [],
                    'reminders' => [],
                    'metrics' => $this->emptyMetrics(),
                    'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'error' => 'Reponse IA invalide.',
                ];
            }

            $normalizedPlanner = $this->normalizePlannerOutput($decoded, $normalized);
            if ($normalizedPlanner === null) {
                return [
                    'source' => 'openai_service',
                    'summary' => 'Planner IA indisponible.',
                    'tips' => [],
                    'planEntries' => [],
                    'reminders' => [],
                    'metrics' => $this->emptyMetrics(),
                    'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'error' => 'Contenu IA insuffisant.',
                ];
            }

            $normalizedPlanner['source'] = 'openai_service';
            $normalizedPlanner['generatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
            $normalizedPlanner['error'] = null;

            return $normalizedPlanner;
        } catch (\Throwable $exception) {
            return [
                'source' => 'openai_service',
                'summary' => 'Planner IA indisponible.',
                'tips' => [],
                'planEntries' => [],
                'reminders' => [],
                'metrics' => $this->emptyMetrics(),
                'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'error' => 'Erreur reseau IA: ' . $exception->getMessage(),
            ];
        }
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
        $focusSubject = trim((string) ($input['focusSubject'] ?? ''));
        $difficultyLevel = $this->normalizeDifficultyLevel((string) ($input['difficultyLevel'] ?? 'medium'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $reminderTime)) {
            $reminderTime = '19:00';
        }

        $subjectCounter = [];
        if (isset($input['subjects_stats']) && is_array($input['subjects_stats'])) {
            foreach ($input['subjects_stats'] as $name => $count) {
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
        foreach ($subjectCounter as $name => $count) {
            $subjects[] = [
                'name' => $name,
                'weight' => max(1, min(3, (int) $count)),
            ];
        }

        $revisionDates = [];
        $cursor = $today;
        while ($cursor < $examDate) {
            $w = (int) $cursor->format('w');
            if ($includeWeekend || ($w !== 0 && $w !== 6)) {
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
            'examDateIso' => $examDate->format(DATE_ATOM),
            'examDate' => $examDate,
            'dailySessions' => $dailySessions,
            'includeWeekend' => $includeWeekend,
            'reminderTime' => $reminderTime,
            'focusSubject' => $focusSubject,
            'difficultyLevel' => $difficultyLevel,
            'daysUntilExam' => max(1, (int) $today->diff($examDate)->format('%a')),
            'subjects' => $subjects,
            'revisionDates' => $revisionDates,
        ];
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function normalizePlannerOutput(array $output, array $context): ?array
    {
        $allowedDates = [];
        foreach ((array) ($context['revisionDates'] ?? []) as $date) {
            $d = trim((string) $date);
            if ($d !== '') {
                $allowedDates[$d] = true;
            }
        }

        $subjectNames = [];
        foreach ((array) ($context['subjects'] ?? []) as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $name = trim((string) ($subject['name'] ?? ''));
            if ($name !== '') {
                $subjectNames[$name] = true;
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
                'Travaille en blocs courts avec une pause de 10 minutes toutes les 60-90 minutes.',
                'Commence chaque session par un rappel actif.',
                'Les derniers jours: priorise annales et correction des erreurs.',
            ];
        }
        $tips = $this->buildContextualTips($tips, $context);

        $summary = trim((string) ($output['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'Plan IA genere selon ta date d\'examen et tes priorites.';
        }
        $focusSubject = trim((string) ($context['focusSubject'] ?? ''));
        $difficultyLevel = $this->normalizeDifficultyLevel((string) ($context['difficultyLevel'] ?? 'medium'));
        $difficultyLabel = match ($difficultyLevel) {
            'easy' => 'facile',
            'hard' => 'difficile',
            default => 'moyen',
        };
        $daysUntilExam = max(1, (int) ($context['daysUntilExam'] ?? 1));
        $contextSummary = trim(implode(' ', array_filter([
            $focusSubject !== '' ? ('Focus matiere: ' . $focusSubject . '.') : '',
            'Niveau ' . $difficultyLabel . ' sur ' . $daysUntilExam . ' jour(s) avant examen.',
        ])));
        if ($contextSummary !== '') {
            $summary = $contextSummary . ' ' . $summary;
        }
        if (mb_strlen($summary) > 320) {
            $summary = rtrim(mb_substr($summary, 0, 320)) . '...';
        }

        $reminders = [];
        foreach ((array) ($output['reminders'] ?? []) as $item) {
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
            $reminders[] = [
                'id' => hash('sha256', $at->format(DATE_ATOM) . '|' . $label),
                'at' => $at->format(DATE_ATOM),
                'label' => $label,
            ];
            if (count($reminders) >= 20) {
                break;
            }
        }

        usort($reminders, static fn(array $a, array $b): int => strcmp($a['at'], $b['at']));
        $metrics = $this->buildMetrics($planEntries, count((array) ($context['revisionDates'] ?? [])));

        return [
            'examDate' => (string) ($context['examDateIso'] ?? ''),
            'summary' => $summary,
            'tips' => $tips,
            'planEntries' => $planEntries,
            'reminders' => $reminders,
            'metrics' => $metrics,
            'notified' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $planEntries
     *
     * @return array<string, int|float>
     */
    private function buildMetrics(array $planEntries, int $revisionDays): array
    {
        $totalMinutes = 0;
        foreach ($planEntries as $entry) {
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
     * @return array<string, int|float>
     */
    private function emptyMetrics(): array
    {
        return [
            'totalMinutes' => 0,
            'totalHours' => 0,
            'intensityScore' => 0,
            'revisionDays' => 0,
        ];
    }

    private function extractJsonObject(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (!preg_match('/\{.*\}/s', $value, $matches)) {
            return null;
        }

        $decoded = json_decode((string) $matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }

    private function extractErrorMessage(string $rawBody): string
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

    private function buildFailureReason(int $statusCode, string $errorMessage = ''): string
    {
        return match ($statusCode) {
            401 => 'Planner IA: token invalide ou revoque.',
            403 => 'Planner IA: acces refuse (token/projet non autorise).',
            404 => 'Planner IA: modele ou endpoint introuvable.',
            429 => 'Planner IA: quota limite ou rate limit atteint (HTTP 429).',
            default => 'Planner IA indisponible (HTTP ' . $statusCode . ')' . ($errorMessage !== '' ? ': ' . $errorMessage : '.'),
        };
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
     * @param array<int, string> $tips
     * @param array<string, mixed> $context
     *
     * @return array<int, string>
     */
    private function buildContextualTips(array $tips, array $context): array
    {
        $focusSubject = trim((string) ($context['focusSubject'] ?? ''));
        $daysUntilExam = max(1, (int) ($context['daysUntilExam'] ?? 1));
        $difficultyLevel = $this->normalizeDifficultyLevel((string) ($context['difficultyLevel'] ?? 'medium'));

        $contextTips = [];
        if ($focusSubject !== '') {
            $contextTips[] = sprintf(
                'Pour %s, commence chaque session par 20 minutes de rappel actif puis passe aux exercices les plus frequents.',
                $focusSubject
            );
        }

        if ($daysUntilExam <= 3) {
            $contextTips[] = 'Il reste peu de jours: concentre-toi sur annales corrigees, points faibles et fiches ultra-courtes.';
        } elseif ($daysUntilExam <= 7) {
            $contextTips[] = 'Cette semaine, alterne un bloc de revision et un bloc d\'application chronometree chaque jour.';
        } else {
            $contextTips[] = 'Tu as encore du temps: consolide les bases puis augmente progressivement la difficulte des exercices.';
        }

        $contextTips[] = match ($difficultyLevel) {
            'easy' => 'Niveau facile: valide les notions cles et automatise les methodes standards.',
            'hard' => 'Niveau difficile: decoupe les chapitres en micro-objectifs et termine chaque jour par une auto-correction.',
            default => 'Niveau moyen: combine recap actif, exercices types et correction des erreurs recurrentes.',
        };

        $merged = [];
        foreach (array_merge($contextTips, $tips) as $tip) {
            $tipText = trim((string) $tip);
            if ($tipText === '' || in_array($tipText, $merged, true)) {
                continue;
            }
            if (mb_strlen($tipText) > 200) {
                $tipText = rtrim(mb_substr($tipText, 0, 200)) . '...';
            }
            $merged[] = $tipText;
            if (count($merged) >= 5) {
                break;
            }
        }

        return $merged;
    }
}
