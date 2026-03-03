<?php

namespace App\Controller;

use App\Entity\Matiere;
use App\Entity\FlashcardAttempt;
use App\Service\OpenRouterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FlashcardController extends AbstractController
{
    #[Route('/student/matiere/{id}/flashcards', name: 'app_student_flashcards')]
public function index(
    Matiere $matiere, 
    Request $request, 
    OpenRouterService $aiService, 
    EntityManagerInterface $em
): Response {
    $session = $request->getSession();
    $sessionKey = 'flashcards_' . $matiere->getId();

    // 1. Reset Logic
    if ($request->query->get('reset')) {
        $session->remove($sessionKey);
        return $this->redirectToRoute('app_student_flashcards', ['id' => $matiere->getId()]);
    }

    // 2. Generation Logic (No changes here, assuming AI service works)
    if (!$session->has($sessionKey)) {
        $contextParts = ["Subject: " . $matiere->getTitre()];
        foreach ($matiere->getChapters() as $chapter) {
            foreach ($chapter->getSections() as $section) {
                $contextParts[] = "[ID: " . $section->getId() . "] Section: " . $section->getTitre();
            }
        }
        $richContext = implode("\n", $contextParts);
        $flashcards = $aiService->generateFlashcards($matiere->getTitre(), $richContext);
        shuffle($flashcards);
        $session->set($sessionKey, $flashcards);
    } else {
        $flashcards = $session->get($sessionKey);
    }

    $currentIndex = $request->query->getInt('step', 0);
    $totalCards = count($flashcards);

    if ($currentIndex >= $totalCards && $totalCards > 0) {
        $session->remove($sessionKey);
        return $this->redirectToRoute('app_front_subject_show', ['id' => $matiere->getId()]);
    }

    $currentCard = $flashcards[$currentIndex] ?? null;
    $aiFeedback = null;
    $isFeedbackMode = false;

    // 3. ANSWER SUBMISSION & LINKING LOGIC
    if ($request->isMethod('POST')) {
        $userAnswer = $request->request->get('user_answer');
        
        if ($userAnswer !== null && $currentCard) {
            $isFeedbackMode = true;
            $aiFeedback = $aiService->getQuickFeedback(
                $currentCard['question'], 
                $userAnswer, 
                $currentCard['answer']
            );

            $attempt = new FlashcardAttempt();
            $attempt->setQuestion($currentCard['question']);
            $attempt->setUserAnswer($userAnswer);
            $attempt->setAiFeedback($aiFeedback);
            $attempt->setSubject($matiere);
            
            // ====================================================
            // ROBUST SECTION LINKING LOGIC
            // ====================================================
            $linkedSection = null;

            // PLAN A: Try to use the ID provided by the AI
            if (isset($currentCard['section_id'])) {
                $linkedSection = $em->getRepository(\App\Entity\Section::class)->find($currentCard['section_id']);
            }

            // PLAN B: Fallback - Keyword Matching
            // If Plan A failed (AI didn't give ID or ID was wrong), we guess based on text.
            if (!$linkedSection) {
                foreach ($matiere->getChapters() as $chapter) {
                    foreach ($chapter->getSections() as $section) {
                        // Check if Section Title is inside the Question (Case Insensitive)
                        // e.g. If Section is "Variables" and Question is "Define variables...", this matches.
                        if (stripos($currentCard['question'], $section->getTitre()) !== false) {
                            $linkedSection = $section;
                            break 2; // Stop looping, we found it
                        }
                    }
                }
            }
            
            // Save the relation if we found one
            if ($linkedSection) {
                $attempt->setSection($linkedSection);
            }
            // ====================================================

            $isCorrect = !preg_match('/(incorrect|faux|no|non)/i', $aiFeedback);
            $attempt->setIsCorrect($isCorrect);

            $em->persist($attempt);
            $em->flush();
        }
    }

    return $this->render('front/flashcards/index.html.twig', [
        'matiere' => $matiere,
        'card' => $currentCard,
        'currentIndex' => $currentIndex,
        'totalCards' => $totalCards,
        'isFeedbackMode' => $isFeedbackMode,
        'aiFeedback' => $aiFeedback,
    ]);
}
}