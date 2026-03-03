<?php

namespace App\Controller;

use App\Service\ChatbotService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChatbotController extends AbstractController
{
    public function __construct(
        private readonly ChatbotService $chatbotService,
    ) {
    }

    #[Route('/chatbot/message', name: 'app_chatbot_message', methods: ['POST'])]
    public function message(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode($request->getContent(), true);
        $userMessage = isset($body['message']) && is_string($body['message'])
            ? trim($body['message'])
            : null;

        if ($userMessage === null || $userMessage === '') {
            return new JsonResponse(['error' => 'Message vide.'], Response::HTTP_BAD_REQUEST);
        }

        $history = isset($body['history']) && is_array($body['history']) ? $body['history'] : [];

        try {
            $reply = $this->chatbotService->chat($user, $userMessage, $history);
            return new JsonResponse(['reply' => $reply]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
