<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class KonnectCheckoutService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RouterInterface $router,
        private readonly string $apiKey = '',
        private readonly string $receiverWalletId = '',
        private readonly string $apiBaseUrl = 'https://api.sandbox.konnect.network/api/v2',
        private readonly string $currency = 'TND',
        private readonly int $pricePerHourInMinorUnit = 3000,
        private readonly int $lifespanMinutes = 15
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->receiverWalletId) !== '';
    }

    /**
     * @return array{
     *     paymentRef: string,
     *     payUrl: string,
     *     amountMinor: int,
     *     currency: string
     * }
     */
    public function createPayment(Reservation $reservation): array
    {
        $this->assertConfigured();

        $seance = $reservation->getSeance();
        $participant = $reservation->getParticipant();
        if (!$seance instanceof Seance || !$participant instanceof User) {
            throw new \RuntimeException('Reservation incomplete: seance ou participant manquant.');
        }

        $reservationId = (int) ($reservation->getId() ?? 0);
        if ($reservationId <= 0) {
            throw new \RuntimeException('Reservation invalide.');
        }

        $amountMinor = $this->computeReservationAmountMinor($reservation);
        $currency = strtoupper(trim($this->currency)) ?: 'TND';

        $firstName = $this->extractFirstName((string) ($participant->getFullName() ?? ''));
        $lastName = $this->extractLastName((string) ($participant->getFullName() ?? ''));

        $successUrl = $this->router->generate(
            'app_payment_success',
            ['id' => $reservationId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $failUrl = $this->router->generate(
            'app_payment_cancel',
            ['id' => $reservationId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $webhookUrl = $this->router->generate(
            'app_payment_konnect_webhook',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $payload = [
            'receiverWalletId' => trim($this->receiverWalletId),
            'token' => $currency,
            'amount' => $amountMinor,
            'type' => 'immediate',
            'description' => sprintf(
                'Reservation #%d - %s (%s)',
                $reservationId,
                (string) ($seance->getMatiere() ?? 'Seance'),
                $seance->getStartAt()?->format('d/m/Y H:i') ?? 'date'
            ),
            'lifespan' => max(5, $this->lifespanMinutes),
            'checkoutForm' => true,
            'addPaymentFeesToAmount' => false,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => (string) ($participant->getEmail() ?? ''),
            'orderId' => 'reservation-' . $reservationId,
            'webhook' => $webhookUrl,
            'successUrl' => $successUrl,
            'failUrl' => $failUrl,
            'theme' => 'light',
        ];

        $response = $this->httpClient->request(
            'POST',
            $this->buildEndpoint('/payments/init-payment'),
            [
                'headers' => [
                    'x-api-key' => trim($this->apiKey),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 25,
            ]
        );

        $statusCode = $response->getStatusCode();
        $result = $response->toArray(false);

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Konnect init-payment error (%d): %s',
                $statusCode,
                $this->extractErrorMessage($result)
            ));
        }

        $payUrl = trim((string) ($result['payUrl'] ?? ''));
        $paymentRef = trim((string) ($result['paymentRef'] ?? ''));

        if ($payUrl === '' || $paymentRef === '') {
            throw new \RuntimeException('Reponse Konnect invalide: payUrl/paymentRef manquant.');
        }

        return [
            'paymentRef' => $paymentRef,
            'payUrl' => $payUrl,
            'amountMinor' => $amountMinor,
            'currency' => $currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentDetails(string $paymentRef): array
    {
        $this->assertConfigured();

        $paymentRef = trim($paymentRef);
        if ($paymentRef === '') {
            throw new \InvalidArgumentException('paymentRef manquant.');
        }

        $response = $this->httpClient->request(
            'GET',
            $this->buildEndpoint('/payments/' . rawurlencode($paymentRef)),
            [
                'headers' => [
                    'x-api-key' => trim($this->apiKey),
                    'Accept' => 'application/json',
                ],
                'timeout' => 25,
            ]
        );

        $statusCode = $response->getStatusCode();
        $result = $response->toArray(false);

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Konnect get-payment-details error (%d): %s',
                $statusCode,
                $this->extractErrorMessage($result)
            ));
        }

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $paymentDetails
     */
    public function isCompleted(array $paymentDetails): bool
    {
        $payment = $paymentDetails['payment'] ?? null;
        if (!is_array($payment)) {
            return false;
        }

        return strtolower(trim((string) ($payment['status'] ?? ''))) === 'completed';
    }

    public function computeReservationAmountMinor(Reservation $reservation): int
    {
        $durationMinutes = max(15, (int) ($reservation->getSeance()?->getDurationMin() ?? 60));
        $unit = max(100, (int) $this->pricePerHourInMinorUnit);
        $amount = (int) ceil(($durationMinutes / 60) * $unit);

        return max(100, $amount);
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'Konnect non configure. Definis KONNECT_API_KEY et KONNECT_RECEIVER_WALLET_ID dans .env.local.'
            );
        }
    }

    private function buildEndpoint(string $path): string
    {
        $base = rtrim(trim($this->apiBaseUrl), '/');
        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractErrorMessage(array $result): string
    {
        foreach (['message', 'error', 'details'] as $key) {
            $value = $result[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $first = reset($result);
        if (is_string($first) && trim($first) !== '') {
            return trim($first);
        }

        return 'Erreur Konnect inconnue.';
    }

    private function extractFirstName(string $fullName): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: []));
        if ($parts === []) {
            return 'Client';
        }

        return mb_substr($parts[0], 0, 60);
    }

    private function extractLastName(string $fullName): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: []));
        if (count($parts) < 2) {
            return 'Fahamni';
        }

        array_shift($parts);

        return mb_substr(implode(' ', $parts), 0, 80);
    }
}
