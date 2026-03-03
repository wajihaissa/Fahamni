<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Repository\MessageRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConversationSummaryService
{
    private const MAX_MESSAGES_FOR_SUMMARY = 200;
    private const MAX_CONTENT_LENGTH = 28000;

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly string $geminiApiKey,
        private readonly string $geminiApiUrl,
    ) {
    }

    /**
     * Construit le texte de la conversation (messages ordonnés) pour le prompt.
     */
    public function buildConversationText(Conversation $conversation): string
    {
        $messages = $this->messageRepository->findByConversation($conversation, true);
        $messages = array_slice($messages, -self::MAX_MESSAGES_FOR_SUMMARY);

        $lines = [];
        $totalLength = 0;
        foreach ($messages as $message) {
            if (!$message instanceof Message || $message->getDeletedAt() !== null) {
                continue;
            }
            $senderName = $message->getSender()?->getFullName() ?? 'Utilisateur';
            $content = trim($message->getContent() ?? '');
            if ($content === '') {
                $content = '[pièce jointe ou contenu vide]';
            }
            $date = $message->getCreatedAt()?->format('d/m/Y H:i') ?? '';
            $line = sprintf("[%s] %s : %s", $date, $senderName, $content);
            $totalLength += strlen($line);
            if ($totalLength > self::MAX_CONTENT_LENGTH) {
                break;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Appelle l'API Gemini pour générer un résumé et le retourne.
     * Lance \RuntimeException en cas d'erreur API.
     */
    public function generateSummary(Conversation $conversation): string
    {
        $conversationText = $this->buildConversationText($conversation);
        if ($conversationText === '') {
            return 'Aucun message à résumer dans cette conversation.';
        }

        $prompt = $this->buildPrompt($conversationText);

        try {
            $response = $this->httpClient->request('POST', $this->geminiApiUrl, [
                'query' => ['key' => $this->geminiApiKey],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->geminiApiKey,
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.4,
                        'maxOutputTokens' => 1024,
                        'topP' => 0.9,
                    ],
                ],
                'timeout' => 60,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Connexion à l\'API Gemini impossible: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Réponse API invalide (non-JSON). Status: ' . $status . '. ' . substr($body, 0, 200), 0, $e);
        }

        if ($status !== 200) {
            $message = $data['error']['message'] ?? ($data['error']['status'] ?? $body);
            if (is_array($message)) {
                $message = json_encode($message);
            }
            throw new \RuntimeException('Erreur API Gemini (' . $status . '): ' . $message, $status);
        }

        $candidates = $data['candidates'] ?? [];
        if ($candidates === []) {
            $blockReason = $data['promptFeedback']['blockReason'] ?? 'aucune réponse';
            throw new \RuntimeException('Gemini n\'a pas renvoyé de résumé (blocage ou filtre: ' . $blockReason . ').');
        }

        $first = $candidates[0];
        $text = $first['content']['parts'][0]['text'] ?? '';
        return trim((string) $text);
    }

    private function buildPrompt(string $conversationText): string
    {
        return <<<PROMPT
Tu es un assistant qui résume des conversations de messagerie de manière claire et professionnelle.

Voici l'historique de la conversation (format : [date heure] Nom : message) :

---
{$conversationText}
---

Rédige un résumé concis en français (3 à 8 phrases) qui :
- résume les sujets principaux abordés ;
- met en évidence les points importants, décisions ou conclusions éventuelles ;
- reste neutre et factuel.

Réponds uniquement par le texte du résumé, sans introduction ni formule du type "Voici le résumé".
PROMPT;
    }
}
