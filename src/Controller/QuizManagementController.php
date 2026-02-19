<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\Question;
use App\Entity\Choice;
use App\Repository\QuizRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/quiz')]
final class QuizManagementController extends AbstractController
{
    #[Route('', name: 'admin_quiz_list')]
    public function list(QuizRepository $quizRepository): Response
    {
        $quizzes = $quizRepository->findAll();
        
        return $this->render('back/quiz/list.html.twig', [
            'quizzes' => $quizzes,
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
    public function deleteQuiz(Quiz $quiz, EntityManagerInterface $em): Response
    {
        $em->remove($quiz);
        $em->flush();

        return $this->redirectToRoute('admin_quiz_list');
    }
}