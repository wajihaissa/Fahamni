<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\GeminiQuizService;

class test extends AbstractController
{
    #[Route('/test/gemini/{keyword}', name: 'test_gemini')]
    public function test(string $keyword, GeminiQuizService $geminiService): Response
    {
        try {
            $questions = $geminiService->generateQuizFromKeyword($keyword);
            
            return $this->json([
                'success' => true,
                'keyword' => $keyword,
                'questions' => $questions
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}