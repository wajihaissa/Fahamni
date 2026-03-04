<?php

namespace App\Controller;

use App\Repository\QuizResultRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class LeaderboardController extends AbstractController
{
    #[Route('/api/leaderboard', name: 'api_leaderboard', methods: ['GET'])]
    public function index(Request $request, QuizResultRepository $quizResultRepository): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query->get('limit', 10)));
        $quizIdRaw = $request->query->get('quizId');

        $quizId = null;
        if ($quizIdRaw !== null && $quizIdRaw !== '') {
            if (!is_numeric($quizIdRaw) || (int) $quizIdRaw <= 0) {
                return $this->json(
                    ['success' => false, 'message' => 'quizId must be a positive integer.'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $quizId = (int) $quizIdRaw;
        }

        $rows = $quizResultRepository->findLeaderboard($limit, $quizId);

        $entries = [];
        foreach ($rows as $index => $row) {
            $entries[] = [
                'rank' => $index + 1,
                'user' => [
                    'id' => $row['userId'],
                    'fullName' => $row['fullName'],
                    'email' => $row['email'],
                ],
                'stats' => [
                    'totalScore' => $row['totalScore'],
                    'averagePercentage' => $row['averagePercentage'],
                    'attempts' => $row['attempts'],
                    'passedCount' => $row['passedCount'],
                    'lastCompletedAt' => $row['lastCompletedAt']?->format(\DateTimeInterface::ATOM),
                ],
            ];
        }

        return $this->json([
            'success' => true,
            'limit' => $limit,
            'quizId' => $quizId,
            'count' => count($entries),
            'entries' => $entries,
        ]);
    }
}

