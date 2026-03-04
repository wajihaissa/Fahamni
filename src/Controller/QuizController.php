<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\Choice;
use App\Entity\Question;
use App\Entity\User;
use App\Service\KeywordQuizProvisioner;
use App\Service\QuizHintService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\QuizRepository;
use App\Repository\QuizResultRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use App\Service\PDFGeneratorService;



/**
 * Quiz côté front (liste, passage, soumission).
 */
#[Route('/quiz', name: 'app_quiz_')]
final class QuizController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, QuizRepository $quizRepository, KeywordQuizProvisioner $keywordQuizProvisioner): Response
    {
        if (!$this->getUser()) {
    $this->addFlash('warning', 'Please login to take quizzes.');
    return $this->redirectToRoute('app_login');
}
        $skillsMode = (int) $request->query->get('skills', 0) === 1;
        $quizzes = $quizRepository->findAll();

        if ($skillsMode && $this->getUser() instanceof User) {
            $student = $this->getUser()->getProfile();
            $pendingKeywordsRaw = [];
            if ($student && method_exists($student, 'getCertificationKeywords')) {
                $pendingKeywordsRaw = (array) $student->getCertificationKeywords();
            } elseif ($student && method_exists($student, 'getCertifications')) {
                $pendingKeywordsRaw = (array) ($student->getCertifications() ?? []);
            }

            $pendingKeywords = $keywordQuizProvisioner->normalizeKeywords($pendingKeywordsRaw);
            $targetQuizzes = [];

            foreach ($pendingKeywords as $keyword) {
                $quiz = $keywordQuizProvisioner->findQuizByKeyword($keyword);
                if ($quiz instanceof Quiz) {
                    $targetQuizzes[$quiz->getId()] = $quiz;
                }
            }

            $quizzes = array_values($targetQuizzes);
        }

        return $this->render('front/quiz/list.html.twig', [
            'quizzes' => $quizzes,
            'skillsMode' => $skillsMode,
        ]);
    }

    #[Route('/leaderboard', name: 'leaderboard', methods: ['GET'])]
    public function leaderboard(
        Request $request,
        QuizRepository $quizRepository,
        QuizResultRepository $quizResultRepository
    ): Response {
        if (!$this->getUser()) {
            $this->addFlash('warning', 'Please login to view the leaderboard.');
            return $this->redirectToRoute('app_login');
        }

        $limit = max(1, min(100, (int) $request->query->get('limit', 10)));
        $quizIdRaw = $request->query->get('quizId');

        $quizId = null;
        if ($quizIdRaw !== null && $quizIdRaw !== '' && is_numeric($quizIdRaw) && (int) $quizIdRaw > 0) {
            $quizId = (int) $quizIdRaw;
        }

        return $this->render('front/quiz/leaderboard.html.twig', [
            'entries' => $quizResultRepository->findLeaderboard($limit, $quizId),
            'quizzes' => $quizRepository->findAll(),
            'selectedQuizId' => $quizId,
            'limit' => $limit,
        ]);
    }

    #[Route('/take/{id}', name: 'quiz_take_by_id', requirements: ['id' => '\d+'])]
#[Route('/take/{keyword}', name: 'quiz_take_by_keyword')]
public function takeQuiz(
    EntityManagerInterface $entityManager,
    KeywordQuizProvisioner $keywordQuizProvisioner,
    ?int $id = null,
    ?string $keyword = null
): Response {
    // 1. Check if quiz exists in database
    $quiz = null;
    
    // Determine which parameter was actually provided
    if ($id !== null) {
        // Search by ID only (don't use keyword for ID search)
        $quiz = $entityManager->getRepository(Quiz::class)->find($id);
        
        if (!$quiz) {
            throw $this->createNotFoundException('Quiz not found');
        }
    } elseif ($keyword !== null) {
        // Reuse existing quiz by keyword, generate only if it does not exist.
        $result = $keywordQuizProvisioner->ensureQuizForKeyword($keyword);
        $quiz = $result['quiz'];
    } else {
        throw $this->createNotFoundException('No identifier provided');
    }
    
    // 3. Render the quiz template
    return $this->render('front/quiz/quiz.html.twig', [
        'quiz' => $quiz
    ]);
}

#[Route('/question/{id}/hint', name: 'question_hint', requirements: ['id' => '\d+'], methods: ['POST'])]
public function questionHint(
    int $id,
    Request $request,
    EntityManagerInterface $entityManager,
    QuizHintService $quizHintService
): JsonResponse {
    if (!$this->getUser()) {
        return $this->json(['success' => false, 'message' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
    }

    $question = $entityManager->getRepository(Question::class)->find($id);
    if (!$question instanceof Question) {
        return $this->json(['success' => false, 'message' => 'Question not found.'], Response::HTTP_NOT_FOUND);
    }

    $payload = json_decode((string) $request->getContent(), true);
    $quizId = (int) ($payload['quizId'] ?? 0);
    if ($quizId <= 0) {
        return $this->json(['success' => false, 'message' => 'quizId is required.'], Response::HTTP_BAD_REQUEST);
    }

    if (($question->getQuiz()?->getId() ?? 0) !== $quizId) {
        return $this->json(['success' => false, 'message' => 'Question does not belong to this quiz.'], Response::HTTP_BAD_REQUEST);
    }

    $session = $request->getSession();
    $cacheKey = 'quiz_hint_' . $quizId . '_' . $id;
    if ($session->has($cacheKey)) {
        /** @var array{hint?:string,provider?:string}|mixed $cached */
        $cached = $session->get($cacheKey);
        if (is_array($cached) && isset($cached['hint'])) {
            return $this->json([
                'success' => true,
                'hint' => (string) $cached['hint'],
                'provider' => (string) ($cached['provider'] ?? 'cache'),
                'cached' => true,
            ]);
        }
    }

    try {
        $result = $quizHintService->generateHint($question);
    } catch (\Throwable $exception) {
        return $this->json([
            'success' => false,
            'message' => 'Unable to generate hint right now.',
            'error' => $exception->getMessage(),
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $session->set($cacheKey, $result);

    return $this->json([
        'success' => true,
        'hint' => $result['hint'],
        'provider' => $result['provider'],
        'cached' => false,
    ]);
}
    
  #[Route('/{id}/submit', name: 'quiz_submit', methods: ['POST'])]
public function submitQuiz(
    int $id, 
    Request $request, 
    EntityManagerInterface $entityManager,
    MailerInterface $mailer,
    PDFGeneratorService $pdfGenerator,
    KeywordQuizProvisioner $keywordQuizProvisioner
    
): Response {
    $quiz = $entityManager->getRepository(Quiz::class)->find($id);
    $data = json_decode($request->getContent(), true);
    $answers = $data['answers'] ?? [];
    
    // Calculate score
    $totalQuestions = count($quiz->getQuestions());
    $correctCount = 0;
    
    foreach ($quiz->getQuestions() as $question) {
        $selectedChoiceId = $answers[$question->getId()] ?? null;
        if ($selectedChoiceId) {
            $choice = $entityManager->getRepository(Choice::class)->find($selectedChoiceId);
            if ($choice && $choice->isCorrect()) {
                $correctCount++;
            }
        }
    }
    
    $percentage = ($correctCount / $totalQuestions) * 100;
    $passed = $percentage >= 60;
    
    // Get current user
    $user = $this->getUser();
    
    // Create QuizResult entity (create this entity first)
    $result = new \App\Entity\QuizResult();
    $result->setUser($user);
    $result->setQuiz($quiz);
    $result->setScore($correctCount);
    $result->setTotalQuestions($totalQuestions);
    $result->setPercentage($percentage);
    $result->setPassed($passed);
    $result->setCompletedAt(new \DateTimeImmutable());
    
    if ($passed) {
            $pdfContent = $pdfGenerator->generateCertificatePDF([
        'user' => $user,
        'quiz' => $quiz,
        'percentage' => $percentage,
        'date' => new \DateTime()
    ]);
     
    
    // Get view link
    

         $transport = Transport::fromDsn($_ENV['MAILER_DSN']);
    // Send HTML email without attachment
    $email = (new \Symfony\Component\Mime\Email())
        ->from('iwajih6@gmail.com')
        ->to($user->getEmail())
        ->subject('🎉 Congratulations! You earned a certification!')
        ->attach($pdfContent, 'certificate.pdf', 'application/pdf')
        ->html($this->renderView('email/certification_email.html.twig', [
            'user' => $user,
            'quiz' => $quiz,
            'percentage' => $percentage
        ]));
        
    
    $transport->send($email);
    
}
    
    $entityManager->persist($result);

    if ($user instanceof User) {
        $student = $user->getProfile();
        $quizKeyword = method_exists($quiz, 'getKeyword')
            ? (string) ($quiz->getKeyword() ?? '')
            : (string) ($quiz->getTitre() ?? '');

        if ($student && $quizKeyword !== '') {
            $normalizedKeyword = $keywordQuizProvisioner->normalizeKeyword($quizKeyword);
            $validatedKeywords = $keywordQuizProvisioner->normalizeKeywords((array) ($student->getCertifications() ?? []));
            $pendingKeywords = method_exists($student, 'getCertificationKeywords')
                ? $keywordQuizProvisioner->normalizeKeywords((array) ($student->getCertificationKeywords() ?? []))
                : [];

            if ($passed) {
                if (!in_array($normalizedKeyword, $validatedKeywords, true)) {
                    $validatedKeywords[] = $normalizedKeyword;
                }
                $pendingKeywords = array_values(array_diff($pendingKeywords, [$normalizedKeyword]));
            } else {
                if (!in_array($normalizedKeyword, $pendingKeywords, true) && !in_array($normalizedKeyword, $validatedKeywords, true)) {
                    $pendingKeywords[] = $normalizedKeyword;
                }
            }

            $student->setCertifications($validatedKeywords);
            if (method_exists($student, 'setCertificationKeywords')) {
                $student->setCertificationKeywords($pendingKeywords);
            }
            $entityManager->persist($student);
        }
    }

    $entityManager->flush();
    
    
    return $this->json([
        'success' => true,
        'score' => $correctCount,
        'total' => $totalQuestions,
        'percentage' => $percentage,
        'passed' => $passed
    ]);
}
}
