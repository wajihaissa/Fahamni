<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appelle l'API Mistral pour le chatbot d'assistance avec contexte utilisateur.
 */
final class ChatbotService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Tu es l'assistant virtuel de la plateforme Fahamni, une application de mise en relation entre étudiants apprenants et étudiants tuteurs.

Tu dois :
- Répondre en français, de manière claire et courtoise.
- Aider l'utilisateur sur l'utilisation de la plateforme : matching tuteur/apprenant, réservations de séances, quiz, recherche de tuteurs, articles (blogs), messagerie, signalement.
- Quand l'utilisateur pose une question sur SES propres données (réservations, séances, articles, conversations), t'apuyer UNIQUEMENT sur le bloc "Contexte utilisateur" fourni ci-dessous. Ne jamais inventer de données.
- Si la question porte sur des données personnelles absentes du contexte, dire que tu n'as pas cette information et inviter à vérifier sur l'application (Calendrier, Messenger, etc.).
- Rester concis (réponses courtes à moyennes) sauf si l'utilisateur demande plus de détails.
PROMPT;

    public function __construct(
        private readonly ChatbotContextService $contextService,
        private readonly HttpClientInterface $httpClient,
        private readonly string $mistralApiKey,
        private readonly string $mistralApiUrl,
        private readonly string $mistralModel,
    ) {
    }

    /**
     * Envoie le message de l'utilisateur à Mistral avec contexte et historique, retourne la réponse texte.
     *
     * @param array<int, array{role: string, content: string}> $history [ { role: 'user'|'assistant', content: '...' }, ... ]
     */
    public function chat(User $user, string $userMessage, array $history = []): string
    {
        $userContext = $this->contextService->buildUserContext($user);
        $systemContent = self::SYSTEM_PROMPT . "\n\n--- Contexte utilisateur (données à jour) ---\n" . $userContext . "\n--- Fin du contexte ---";

        $messages = [
            ['role' => 'system', 'content' => $systemContent],
        ];

        $recentHistory = array_slice($history, -10);
        foreach ($recentHistory as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            if ($content !== '' && \in_array($role, ['user', 'assistant'], true)) {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = $this->httpClient->request('POST', $this->mistralApiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->mistralApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->mistralModel,
                    'messages' => $messages,
                    'temperature' => 0.5,
                    'max_tokens' => 1024,
                ],
                'timeout' => 45,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de contacter l\'assistant : ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Réponse API invalide.', 0, $e);
        }

        if ($status !== 200) {
            $message = $data['message'] ?? $data['error'] ?? $body;
            if (is_array($message)) {
                $message = json_encode($message);
            }
            throw new \RuntimeException('Erreur API Mistral (' . $status . ') : ' . $message, $status);
        }

        $choices = $data['choices'] ?? [];
        if ($choices === []) {
            throw new \RuntimeException('Aucune réponse de l\'assistant.');
        }

        $content = $choices[0]['message']['content'] ?? '';
        return trim((string) $content);
    }
}
