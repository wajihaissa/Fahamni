<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FacePlusPlusService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $apiBaseUrl = 'https://api-us.faceplusplus.com'
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /**
     * @return array{success:bool,faceToken?:string,message?:string}
     */
    public function detectFaceToken(string $imageBase64): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Face ID provider is not configured.'];
        }

        try {
            $data = $this->requestWithConcurrencyRetry('/facepp/v3/detect', [
                'api_key' => $this->apiKey,
                'api_secret' => $this->apiSecret,
                'image_base64' => $imageBase64,
            ]);
        } catch (ExceptionInterface|\Throwable) {
            return ['success' => false, 'message' => 'Face ID provider is unavailable (SSL/network).'];
        }

        if (!empty($data['error_message'])) {
            $providerError = (string) $data['error_message'];
            if ($providerError === 'CONCURRENCY_LIMIT_EXCEEDED') {
                return ['success' => false, 'message' => 'Face service is busy right now. Please wait 3-5 seconds and retry.'];
            }
            return ['success' => false, 'message' => 'Face detection failed: ' . $providerError];
        }

        $faces = $data['faces'] ?? null;
        if (!is_array($faces) || count($faces) === 0) {
            return ['success' => false, 'message' => 'No face detected. Use a clear selfie.'];
        }

        if (count($faces) > 1) {
            return ['success' => false, 'message' => 'Multiple faces detected. Use only one face.'];
        }

        $faceToken = (string) (($faces[0]['face_token'] ?? ''));
        if ($faceToken === '') {
            return ['success' => false, 'message' => 'Face token missing from provider response.'];
        }

        return ['success' => true, 'faceToken' => $faceToken];
    }

    /**
     * @return array{success:bool,match?:bool,score?:float,threshold?:float,message?:string}
     */
    public function compareFaces(string $faceToken1, string $faceToken2): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Face ID provider is not configured.'];
        }

        try {
            $data = $this->requestWithConcurrencyRetry('/facepp/v3/compare', [
                'api_key' => $this->apiKey,
                'api_secret' => $this->apiSecret,
                'face_token1' => $faceToken1,
                'face_token2' => $faceToken2,
            ]);
        } catch (ExceptionInterface|\Throwable) {
            return ['success' => false, 'message' => 'Face ID provider is unavailable (SSL/network).'];
        }

        if (!empty($data['error_message'])) {
            $providerError = (string) $data['error_message'];
            if ($providerError === 'CONCURRENCY_LIMIT_EXCEEDED') {
                return ['success' => false, 'message' => 'Face service is busy right now. Please wait 3-5 seconds and retry.'];
            }
            return ['success' => false, 'message' => 'Face compare failed: ' . $providerError];
        }

        $confidence = (float) ($data['confidence'] ?? 0.0);
        $threshold = (float) (($data['thresholds']['1e-4'] ?? $data['thresholds']['1e-3'] ?? 80.0));
        $match = $confidence >= $threshold;

        return [
            'success' => true,
            'match' => $match,
            'score' => $confidence,
            'threshold' => $threshold,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestWithConcurrencyRetry(string $path, array $body): array
    {
        $url = rtrim($this->apiBaseUrl, '/') . $path;
        $attempts = 3;
        $delaysMicros = [200000, 600000];
        $lastData = [];

        for ($i = 0; $i < $attempts; $i++) {
            $response = $this->httpClient->request('POST', $url, ['body' => $body]);
            $data = $response->toArray(false);
            $lastData = is_array($data) ? $data : [];

            $error = (string) ($lastData['error_message'] ?? '');
            if ($error !== 'CONCURRENCY_LIMIT_EXCEEDED') {
                return $lastData;
            }

            if ($i < $attempts - 1) {
                usleep($delaysMicros[$i] ?? 600000);
            }
        }

        return $lastData;
    }
}
