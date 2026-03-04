<?php

namespace App\Service;

use App\Entity\Question;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class QuizHintService
{
    private HttpClientInterface $httpClient;
    private string $githubToken;
    private string $githubModel;
    private string $mistralApiKey;
    private string $mistralApiUrl;
    private string $mistralModel;
    private string $geminiApiKey;
    private string $geminiApiUrl;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        $this->githubToken = trim((string) ($_ENV['API_KEY'] ?? getenv('API_KEY') ?: $_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?: ''));
        $this->githubModel = trim((string) ($_ENV['GITHUB_AI_MODEL'] ?? getenv('GITHUB_AI_MODEL') ?: 'openai/gpt-4o-mini'));
        $this->mistralApiKey = trim((string) ($_ENV['MISTRAL_API_KEY'] ?? getenv('MISTRAL_API_KEY') ?: ''));
        $this->mistralApiUrl = trim((string) ($_ENV['MISTRAL_API_URL'] ?? getenv('MISTRAL_API_URL') ?: 'https://api.mistral.ai/v1/chat/completions'));
        $this->mistralModel = trim((string) ($_ENV['MISTRAL_MODEL'] ?? getenv('MISTRAL_MODEL') ?: 'mistral-small-latest'));
        $this->geminiApiKey = trim((string) ($_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: ''));
        $this->geminiApiUrl = trim((string) ($_ENV['GEMINI_API_URL'] ?? getenv('GEMINI_API_URL') ?: 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent'));
    }

    /**
     * @return array{hint:string,provider:string}
     */
    public function generateHint(Question $question): array
    {
        $prompt = $this->buildPrompt($question);

        $providers = [
            'github_models' => fn() => $this->tryGitHubModels($prompt),
            'mistral' => fn() => $this->tryMistral($prompt),
            'gemini' => fn() => $this->tryGemini($prompt),
        ];

        $errors = [];
        foreach ($providers as $name => $callable) {
            try {
                $hint = $callable();
                if ($hint !== null && $hint !== '') {
                    return [
                        'hint' => $hint,
                        'provider' => $name,
                    ];
                }
                $errors[] = $name . ': empty response';
            } catch (\Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        throw new \RuntimeException('No AI provider could generate a hint. ' . implode(' | ', $errors));
    }

    private function buildPrompt(Question $question): string
    {
        $choices = [];
        foreach ($question->getChoices() as $index => $choice) {
            $choices[] = sprintf('%d. %s', $index + 1, trim((string) $choice->getChoice()));
        }

        return implode("\n", [
            'You are a quiz tutor. Give a helpful hint for a multiple-choice question.',
            'Rules:',
            '- Do not reveal the exact correct option.',
            '- Do not quote the full answer.',
            '- Keep it short: max 2 sentences.',
            '- Be practical and guiding.',
            '',
            'Question: ' . trim((string) $question->getQuestion()),
            'Choices:',
            implode("\n", $choices),
            '',
            'Return only the hint text.'
        ]);
    }

    private function tryGitHubModels(string $prompt): ?string
    {
        if ($this->githubToken === '') {
            return null;
        }

        $response = $this->httpClient->request('POST', 'https://models.github.ai/inference/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->githubToken,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->githubModel,
                'messages' => [
                    ['role' => 'system', 'content' => 'You produce concise, safe quiz hints only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.5,
                'max_tokens' => 140,
            ],
            'timeout' => 25,
        ]);

        $data = $response->toArray(false);
        $content = (string) ($data['choices'][0]['message']['content'] ?? '');

        return $this->normalizeHint($content);
    }

    private function tryMistral(string $prompt): ?string
    {
        if ($this->mistralApiKey === '') {
            return null;
        }

        $response = $this->httpClient->request('POST', $this->mistralApiUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mistralApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->mistralModel,
                'messages' => [
                    ['role' => 'system', 'content' => 'You produce concise, safe quiz hints only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.5,
                'max_tokens' => 160,
            ],
            'timeout' => 25,
        ]);

        $data = $response->toArray(false);
        $content = (string) ($data['choices'][0]['message']['content'] ?? '');

        return $this->normalizeHint($content);
    }

    private function tryGemini(string $prompt): ?string
    {
        if ($this->geminiApiKey === '') {
            return null;
        }

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
                    'temperature' => 0.5,
                    'maxOutputTokens' => 160,
                    'topP' => 0.9,
                ],
            ],
            'timeout' => 25,
        ]);

        $data = $response->toArray(false);
        $content = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');

        return $this->normalizeHint($content);
    }

    private function normalizeHint(string $hint): ?string
    {
        $hint = trim(str_replace(['```', '`'], '', $hint));
        if ($hint === '') {
            return null;
        }

        if (mb_strlen($hint) > 280) {
            $hint = rtrim(mb_substr($hint, 0, 280)) . '...';
        }

        return $hint;
    }
}

