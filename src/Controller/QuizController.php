<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Repository\QuizRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Quiz côté front (liste, passage, soumission).
 */
#[Route('/quiz', name: 'app_quiz_')]
final class QuizController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(QuizRepository $quizRepository): Response
    {
        $quizzes = $quizRepository->findAll();

        return $this->render('front/quiz/list.html.twig', [
            'quizzes' => $quizzes,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Quiz $quiz): Response
    {
        return $this->render('front/quiz/quiz.html.twig', [
            'quiz' => $quiz,
        ]);
    }

    #[Route('/{id}/submit', name: 'submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(Quiz $quiz, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $answers = $data['answers'] ?? [];

        $total = 0;
        $score = 0;

        foreach ($quiz->getQuestions() as $question) {
            $total++;
            $submittedChoiceId = $answers[(string) $question->getId()] ?? null;
            if ($submittedChoiceId === null) {
                continue;
            }
            foreach ($question->getChoices() as $choice) {
                if ($choice->getId() === (int) $submittedChoiceId && $choice->isCorrect()) {
                    $score++;
                    break;
                }
            }
        }

        $percentage = $total > 0 ? ($score / $total) * 100 : 0;

        return new JsonResponse([
            'success' => true,
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
        ]);
    }
}
