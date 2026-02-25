<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\Choice;
use App\Entity\User;
use App\Service\KeywordQuizProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\QuizRepository;
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
            $pendingKeywords = $keywordQuizProvisioner->normalizeKeywords((array) ($student?->getCertificationKeywords() ?? []));
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
        $quizKeyword = $quiz->getKeyword();

        if ($student && $quizKeyword) {
            $normalizedKeyword = $keywordQuizProvisioner->normalizeKeyword($quizKeyword);
            $validatedKeywords = $keywordQuizProvisioner->normalizeKeywords((array) ($student->getCertifications() ?? []));
            $pendingKeywords = $keywordQuizProvisioner->normalizeKeywords((array) ($student->getCertificationKeywords() ?? []));

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
            $student->setCertificationKeywords($pendingKeywords);
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
