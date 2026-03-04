<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class GeminiQuizService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ParameterBagInterface $params
    ) {}

      public function generateQuizFromKeyword(string $keyword): array
    {
        $token = $this->params->get('app.api_key');
        
       $prompt = "Create a 5-question ADVANCED technical multiple choice quiz about '$keyword' for expert-level tutors.
           Questions should be challenging, easy concepts.
           Return ONLY JSON array with: question, options (array of 4), correctAnswer (index 0-3)";
        
        $response = $this->httpClient->request('POST', 
            'https://models.github.ai/inference/chat/completions', 
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'openai/gpt-4o',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a quiz generator. Return only valid JSON.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 1.0,
                    'top_p' => 1.0,
                    'max_tokens' => 1000
                ]
            ]
        );
        
        $data = $response->toArray();
        $text = $data['choices'][0]['message']['content'] ?? '';
        
        $cleanJson = trim(str_replace(['```json', '```', '`'], '', $text));
        
        return json_decode($cleanJson, true) ?? [];
    }
}