<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\Question;
use App\Entity\Choice;
use App\Service\GeminiQuizService;
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
    public function list(QuizRepository $quizRepository): Response
    {
        if (!$this->getUser()) {
    $this->addFlash('warning', 'Please login to take quizzes.');
    return $this->redirectToRoute('app_login');
}
        $quizzes = $quizRepository->findAll();

        return $this->render('front/quiz/list.html.twig', [
            'quizzes' => $quizzes,
        ]);
    }
    #[Route('/take/{id}', name: 'quiz_take_by_id', requirements: ['id' => '\d+'])]
#[Route('/take/{keyword}', name: 'quiz_take_by_keyword')]
public function takeQuiz(
    ?string $keyword = null,  // Make it nullable with default null
    ?int $id = null, 
    GeminiQuizService $aiService,
    EntityManagerInterface $entityManager
   
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
        // Search by keyword in title
        $quiz = $entityManager->getRepository(Quiz::class)
            ->createQueryBuilder('q')
            ->where('q.titre LIKE :keyword')
            ->setParameter('keyword', '%' . $keyword . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        // 2. If not found, generate with AI
        if (!$quiz) {
            $questionsData = $aiService->generateQuizFromKeyword($keyword);
            
            // Create new quiz
            $quiz = new Quiz();
            $quiz->setTitre($keyword . ' Certification Quiz');
            
            // Add questions and choices
            foreach ($questionsData as $qData) {
                $question = new Question();
                $question->setQuestion($qData['question']);
                $question->setQuiz($quiz);
                
                foreach ($qData['options'] as $index => $optionText) {
                    $choice = new Choice();
                    $choice->setChoice($optionText);
                    $choice->setQuestion($question);
                    $choice->setIsCorrect($index === $qData['correctAnswer']);
                    
                    $entityManager->persist($choice);
                    $question->addChoice($choice);
                }
                
                $entityManager->persist($question);
                $quiz->addQuestion($question);
            }
            
            $entityManager->persist($quiz);
            $entityManager->flush();
        }
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
    $passed = $percentage >= 70;
    
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
