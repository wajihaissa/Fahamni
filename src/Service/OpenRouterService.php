<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class OpenRouterService
{
    private $client;
    private $apiKey;

    public function __construct(
        HttpClientInterface $client,
        #[Autowire('%env(OPENROUTER_API_KEY)%')] string $apiKey
    ) {
        $this->client = $client;
        $this->apiKey = trim($apiKey);
    }

    public function generateFlashcards(string $subjectName, string $description): array
    {
        $cleanDesc = substr(strip_tags($description), 0, 6000);
        $randomSeed = random_int(1, 9999);
        $url = 'https://models.github.ai/inference/chat/completions';

        $systemPrompt = "You are a teacher. You must output strictly a valid JSON array. No text before or after the JSON.";
        $userPrompt = "Generate 5 unique flashcards based on this syllabus: $subjectName. " .
          "Syllabus: $cleanDesc. " .
          "IMPORTANT: For each card, you MUST identify the 'section_id' from the syllabus that this question fits best. " .
          "Random Seed: $randomSeed. " . 
          "Format: [{\"question\":\"...\",\"answer\":\"...\",\"section_id\": 123}]";
        try {
            $response = $this->client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'json' => [
                    'model' => 'gpt-4o',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt]
                    ],
                    'temperature' => 0.7,
                ],
            ]);

            $data = $response->toArray();
            $rawText = $data['choices'][0]['message']['content'] ?? '';

            // Clean markdown tags if present
            $rawText = preg_replace('/^```json|```$/m', '', trim($rawText));
            $cards = json_decode($rawText, true);

            return is_array($cards) ? $cards : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getQuickFeedback(string $question, string $userAnswer, string $correctAnswer): string
    {
        $url = 'https://models.github.ai/inference/chat/completions';
        $prompt = "Question: $question. Student Answer: $userAnswer. Correct Answer: $correctAnswer. 
                   Compare them. If the student is right, say 'Bravo!'. If not, explain why in 1 short sentence.";

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-4o',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => 0.5,
                    'max_tokens' => 150,
                ],
            ]);

            $data = $response->toArray();
            return $data['choices'][0]['message']['content'] ?? "Analyse indisponible.";
        } catch (\Exception $e) {
            return "Erreur d'analyse.";
        }
    }
}