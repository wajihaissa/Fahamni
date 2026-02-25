<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\Question;
use App\Entity\Choice;
use App\Entity\QuizResult;
use App\Repository\QuizRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/quiz')]
final class QuizManagementController extends AbstractController
{
    #[Route('', name: 'admin_quiz_list')]
    public function list(Request $request, QuizRepository $quizRepository, EntityManagerInterface $entityManager): Response
    {
        $search = trim((string) $request->query->get('q', ''));

        $listQb = $quizRepository->createQueryBuilder('q')
            ->leftJoin('q.questions', 'question')
            ->addSelect('question')
            ->orderBy('q.id', 'DESC');

        if ($search !== '') {
            $listQb
                ->andWhere('LOWER(q.titre) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        $quizzes = $listQb->getQuery()->getResult();

        $totalQuizzes = $quizRepository->count([]);
        $totalQuestions = (int) $entityManager->createQueryBuilder()
            ->select('COUNT(question.id)')
            ->from(Question::class, 'question')
            ->getQuery()
            ->getSingleScalarResult();

        $totalAttempts = $entityManager->getRepository(QuizResult::class)->count([]);
        $totalPassedAttempts = $entityManager->getRepository(QuizResult::class)->count(['passed' => true]);
        $passRate = $totalAttempts > 0 ? round(($totalPassedAttempts / $totalAttempts) * 100, 1) : 0.0;
        $todayStart = new DateTimeImmutable('today');
        $tomorrowStart = $todayStart->modify('+1 day');
        $weekStart = $todayStart->modify('-6 days');

        $quizzesToday = 0;
        $quizzesThisWeek = 0;
        $todayAttempts = 0;
        $avgScore = 0.0;

        // Keep page usable even if DB migration for quiz.createdAt is not applied yet.
        try {
            $quizzesToday = (int) $entityManager->createQueryBuilder()
                ->select('COUNT(q.id)')
                ->from(Quiz::class, 'q')
                ->where('q.createdAt >= :todayStart')
                ->andWhere('q.createdAt < :tomorrowStart')
                ->setParameter('todayStart', $todayStart)
                ->setParameter('tomorrowStart', $tomorrowStart)
                ->getQuery()
                ->getSingleScalarResult();

            $quizzesThisWeek = (int) $entityManager->createQueryBuilder()
                ->select('COUNT(q.id)')
                ->from(Quiz::class, 'q')
                ->where('q.createdAt >= :weekStart')
                ->setParameter('weekStart', $weekStart)
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable) {
            $quizzesToday = 0;
            $quizzesThisWeek = 0;
        }

        $todayAttempts = (int) $entityManager->createQueryBuilder()
            ->select('COUNT(qr.id)')
            ->from(QuizResult::class, 'qr')
            ->where('qr.completedAt >= :todayStart')
            ->andWhere('qr.completedAt < :tomorrowStart')
            ->setParameter('todayStart', $todayStart)
            ->setParameter('tomorrowStart', $tomorrowStart)
            ->getQuery()
            ->getSingleScalarResult();

        $avgScore = (float) $entityManager->createQueryBuilder()
            ->select('COALESCE(AVG(qr.percentage), 0)')
            ->from(QuizResult::class, 'qr')
            ->getQuery()
            ->getSingleScalarResult();

        $topQuiz = $entityManager->createQueryBuilder()
            ->select('q.titre AS title, COUNT(qr.id) AS attempts')
            ->from(QuizResult::class, 'qr')
            ->join('qr.quiz', 'q')
            ->groupBy('q.id, q.titre')
            ->orderBy('attempts', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->render('back/quiz/list.html.twig', [
            'quizzes' => $quizzes,
            'search' => $search,
            'totalQuizzes' => $totalQuizzes,
            'totalQuestions' => $totalQuestions,
            'quizzesToday' => $quizzesToday,
            'quizzesThisWeek' => $quizzesThisWeek,
            'totalAttempts' => $totalAttempts,
            'todayAttempts' => $todayAttempts,
            'passRate' => $passRate,
            'avgScore' => round($avgScore, 1),
            'topQuizTitle' => $topQuiz['title'] ?? 'No attempts yet',
            'topQuizAttempts' => isset($topQuiz['attempts']) ? (int) $topQuiz['attempts'] : 0,
        ]);
    }

    #[Route('/create', name: 'admin_quiz_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $titre = $request->request->get('titre');
            
            if (!$titre) {
                return $this->render('back/quiz/create.html.twig', [
                    'error' => 'Quiz title is required',
                ]);
            }

            $quiz = new Quiz();
            $quiz->setTitre($titre);
            $em->persist($quiz);
            $em->flush();

            return $this->redirectToRoute('admin_quiz_edit', ['id' => $quiz->getId()]);
        }

        return $this->render('back/quiz/create.html.twig');
    }

    #[Route('/{id}/edit', name: 'admin_quiz_edit')]
    public function edit(Quiz $quiz): Response
    {
        return $this->render('back/quiz/edit.html.twig', [
            'quiz' => $quiz,
        ]);
    }

    #[Route('/{id}/question/add', name: 'admin_quiz_question_add', methods: ['POST'])]
    public function addQuestion(Quiz $quiz, Request $request, EntityManagerInterface $em): Response
    {
        $questionText = $request->request->get('question_text');
        
        if (!$questionText) {
            return $this->redirectToRoute('admin_quiz_edit', ['id' => $quiz->getId()]);
        }

        $question = new Question();
        $question->setQuestion($questionText);
        $question->setQuiz($quiz);
        $em->persist($question);
        $em->flush();

        return $this->redirectToRoute('admin_quiz_question_edit', [
            'quiz' => $quiz->getId(),
            'question' => $question->getId(),
        ]);
    }

    #[Route('/{quiz}/question/{question}/edit', name: 'admin_quiz_question_edit')]
    public function editQuestion(Quiz $quiz, Question $question): Response
    {
        if ($question->getQuiz()->getId() !== $quiz->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('back/quiz/question-edit.html.twig', [
            'quiz' => $quiz,
            'question' => $question,
        ]);
    }

    #[Route('/{quiz}/question/{question}/choice/add', name: 'admin_quiz_choice_add', methods: ['POST'])]
    public function addChoice(Quiz $quiz, Question $question, Request $request, EntityManagerInterface $em): Response
    {
        if ($question->getQuiz()->getId() !== $quiz->getId()) {
            throw $this->createAccessDeniedException();
        }

        $choiceText = $request->request->get('choice_text');
        $isCorrect = $request->request->get('is_correct') === '1';

        if (!$choiceText) {
            return $this->redirectToRoute('admin_quiz_question_edit', [
                'quiz' => $quiz->getId(),
                'question' => $question->getId(),
            ]);
        }

        $choice = new Choice();
        $choice->setChoice($choiceText);
        $choice->setIsCorrect($isCorrect);
        $choice->setQuestion($question);
        $em->persist($choice);
        $em->flush();

        return $this->redirectToRoute('admin_quiz_question_edit', [
            'quiz' => $quiz->getId(),
            'question' => $question->getId(),
        ]);
    }

    #[Route('/question/{id}/delete', name: 'admin_quiz_question_delete', methods: ['POST'])]
    public function deleteQuestion(Question $question, EntityManagerInterface $em, Request $request): Response
    {
        $quizId = $question->getQuiz()->getId();
        
        $em->remove($question);
        $em->flush();

        return $this->redirectToRoute('admin_quiz_edit', ['id' => $quizId]);
    }

    #[Route('/choice/{id}/delete', name: 'admin_quiz_choice_delete', methods: ['POST'])]
    public function deleteChoice(Choice $choice, EntityManagerInterface $em): Response
    {
        $question = $choice->getQuestion();
        $quizId = $question->getQuiz()->getId();
        $questionId = $question->getId();
        
        $em->remove($choice);
        $em->flush();

        return $this->redirectToRoute('admin_quiz_question_edit', [
            'quiz' => $quizId,
            'question' => $questionId,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_quiz_delete', methods: ['POST'])]
    public function deleteQuiz(Quiz $quiz, EntityManagerInterface $em, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_quiz_' . $quiz->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid delete request.');
            return $this->redirectToRoute('admin_quiz_list');
        }

        // Delete child rows first to satisfy foreign-key constraints.
        $em->createQueryBuilder()
            ->delete(Choice::class, 'c')
            ->where('c.question IN (
                SELECT q2.id FROM ' . Question::class . ' q2 WHERE q2.quiz = :quiz
            )')
            ->setParameter('quiz', $quiz)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(Question::class, 'q')
            ->where('q.quiz = :quiz')
            ->setParameter('quiz', $quiz)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(QuizResult::class, 'qr')
            ->where('qr.quiz = :quiz')
            ->setParameter('quiz', $quiz)
            ->getQuery()
            ->execute();

        $em->remove($quiz);
        $em->flush();

        $this->addFlash('success', 'Quiz deleted successfully.');

        return $this->redirectToRoute('admin_quiz_list');
    }
}
