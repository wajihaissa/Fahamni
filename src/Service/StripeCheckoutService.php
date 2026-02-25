<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class StripeCheckoutService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RouterInterface $router,
        private readonly LoggerInterface $logger,
        private readonly string $stripeSecretKey = '',
        private readonly string $stripeWebhookSecret = '',
        private readonly string $stripePublishableKey = '',
        private readonly string $defaultCurrency = 'tnd',
        private readonly int $defaultPricePerHourCents = 3000
    ) {
    }

    public function isConfigured(): bool
    {
        return str_starts_with(trim($this->stripeSecretKey), 'sk_');
    }

    public function getWebhookSecret(): string
    {
        return trim($this->stripeWebhookSecret);
    }

    public function getPublishableKey(): string
    {
        $publishable = trim($this->stripePublishableKey);
        if ($publishable === '' || !str_starts_with($publishable, 'pk_')) {
            throw new \RuntimeException('STRIPE_PUBLISHABLE_KEY non configure dans .env.local.');
        }

        return $publishable;
    }

    public function getDefaultCurrencyCode(): string
    {
        return $this->normalizeCurrencyCode($this->defaultCurrency);
    }

    /**
     * @return array{
     *     sessionId: string,
     *     checkoutUrl: string,
     *     amountCents: int,
     *     currency: string
     * }
     */
    public function createCheckoutSession(Reservation $reservation): array
    {
        $this->assertConfigured();

        $seance = $reservation->getSeance();
        $participant = $reservation->getParticipant();

        if (!$seance instanceof Seance || !$participant instanceof User) {
            throw new \RuntimeException('Reservation incomplete: seance ou participant manquant.');
        }

        $reservationId = (int) ($reservation->getId() ?? 0);
        if ($reservationId <= 0) {
            throw new \RuntimeException('Impossible de demarrer le paiement: reservation invalide.');
        }

        $amountCents = $this->computeReservationAmountCents($reservation);
        $currency = $this->normalizeCurrencyCode($this->defaultCurrency);

        $successUrl = $this->router->generate(
            'app_payment_success',
            ['id' => $reservationId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';

        $cancelUrl = $this->router->generate(
            'app_payment_cancel',
            ['id' => $reservationId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $body = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => (string) $participant->getEmail(),
            'client_reference_id' => (string) $reservationId,
            'metadata[reservation_id]' => (string) $reservationId,
            'metadata[seance_id]' => (string) ($seance->getId() ?? 0),
            'metadata[matiere]' => (string) ($seance->getMatiere() ?? 'Seance'),
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][unit_amount]' => (string) $amountCents,
            'line_items[0][price_data][product_data][name]' => sprintf(
                'Reservation seance: %s',
                (string) ($seance->getMatiere() ?? 'Seance')
            ),
            'line_items[0][price_data][product_data][description]' => sprintf(
                'Seance du %s avec %s (%d min)',
                $seance->getStartAt()?->format('d/m/Y H:i') ?? 'date non definie',
                $seance->getTuteur()?->getFullName() ?? 'tuteur',
                (int) ($seance->getDurationMin() ?? 0)
            ),
        ];

        $response = $this->httpClient->request('POST', 'https://api.stripe.com/v1/checkout/sessions', [
            'auth_bearer' => trim($this->stripeSecretKey),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
            'timeout' => 25,
        ]);

        $statusCode = $response->getStatusCode();
        $payload = $response->toArray(false);

        if ($statusCode >= 400) {
            $message = $this->extractStripeErrorMessage($payload);
            throw new \RuntimeException(
                sprintf('Stripe Checkout indisponible (%d): %s', $statusCode, $message)
            );
        }

        $sessionId = trim((string) ($payload['id'] ?? ''));
        $checkoutUrl = trim((string) ($payload['url'] ?? ''));

        if ($sessionId === '' || $checkoutUrl === '') {
            throw new \RuntimeException('Reponse Stripe invalide: session id/url manquant.');
        }

        return [
            'sessionId' => $sessionId,
            'checkoutUrl' => $checkoutUrl,
            'amountCents' => $amountCents,
            'currency' => $currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        $this->assertConfigured();

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new \InvalidArgumentException('sessionId manquant.');
        }

        $response = $this->httpClient->request(
            'GET',
            sprintf('https://api.stripe.com/v1/checkout/sessions/%s', rawurlencode($sessionId)),
            [
                'auth_bearer' => trim($this->stripeSecretKey),
                'query' => [
                    'expand[]' => 'payment_intent',
                ],
                'timeout' => 25,
            ]
        );

        $statusCode = $response->getStatusCode();
        $payload = $response->toArray(false);

        if ($statusCode >= 400) {
            $message = $this->extractStripeErrorMessage($payload);
            throw new \RuntimeException(
                sprintf('Impossible de lire la session Stripe (%d): %s', $statusCode, $message)
            );
        }

        return is_array($payload) ? $payload : [];
    }

    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        $webhookSecret = trim($this->stripeWebhookSecret);
        if ($webhookSecret === '' || $signatureHeader === '') {
            return false;
        }

        $parts = explode(',', $signatureHeader);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $part, 2));
            if ($key === 't') {
                $timestamp = ctype_digit($value) ? (int) $value : null;
                continue;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expectedSignature, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function computeReservationAmountCents(Reservation $reservation): int
    {
        $duration = max(15, (int) ($reservation->getSeance()?->getDurationMin() ?? 60));
        $pricePerHour = max(100, $this->defaultPricePerHourCents);
        $amount = (int) ceil(($duration / 60) * $pricePerHour);

        return max(50, $amount);
    }

    /**
     * @return array{
     *     id: string,
     *     clientSecret: string,
     *     status: string,
     *     amountCents: int,
     *     currency: string
     * }
     */
    public function createPaymentIntent(Reservation $reservation): array
    {
        $this->assertConfigured();

        $seance = $reservation->getSeance();
        $participant = $reservation->getParticipant();
        if (!$seance instanceof Seance || !$participant instanceof User) {
            throw new \RuntimeException('Reservation incomplete: seance ou participant manquant.');
        }

        $reservationId = (int) ($reservation->getId() ?? 0);
        if ($reservationId <= 0) {
            throw new \RuntimeException('Impossible de creer le paiement: reservation invalide.');
        }

        $amountCents = $this->computeReservationAmountCents($reservation);
        $currency = $this->normalizeCurrencyCode($this->defaultCurrency);

        $body = [
            'amount' => (string) $amountCents,
            'currency' => $currency,
            'automatic_payment_methods[enabled]' => 'true',
            'description' => sprintf(
                'Reservation #%d - %s (%s)',
                $reservationId,
                (string) ($seance->getMatiere() ?? 'Seance'),
                $seance->getStartAt()?->format('d/m/Y H:i') ?? 'date'
            ),
            'receipt_email' => (string) ($participant->getEmail() ?? ''),
            'metadata[reservation_id]' => (string) $reservationId,
            'metadata[seance_id]' => (string) ($seance->getId() ?? 0),
        ];

        $response = $this->httpClient->request('POST', 'https://api.stripe.com/v1/payment_intents', [
            'auth_bearer' => trim($this->stripeSecretKey),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
            'timeout' => 25,
        ]);

        $statusCode = $response->getStatusCode();
        $payload = $response->toArray(false);

        if ($statusCode >= 400) {
            $message = $this->extractStripeErrorMessage($payload);
            throw new \RuntimeException(
                sprintf('Stripe PaymentIntent indisponible (%d): %s', $statusCode, $message)
            );
        }

        $intentId = trim((string) ($payload['id'] ?? ''));
        $clientSecret = trim((string) ($payload['client_secret'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));

        if ($intentId === '' || $clientSecret === '') {
            throw new \RuntimeException('Reponse Stripe invalide: payment_intent/client_secret manquant.');
        }

        return [
            'id' => $intentId,
            'clientSecret' => $clientSecret,
            'status' => $status !== '' ? $status : 'requires_payment_method',
            'amountCents' => (int) ($payload['amount'] ?? $amountCents),
            'currency' => $this->normalizeCurrencyCode((string) ($payload['currency'] ?? $currency)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPaymentIntent(string $paymentIntentId): array
    {
        $this->assertConfigured();

        $paymentIntentId = trim($paymentIntentId);
        if ($paymentIntentId === '') {
            throw new \InvalidArgumentException('paymentIntentId manquant.');
        }

        $response = $this->httpClient->request(
            'GET',
            sprintf('https://api.stripe.com/v1/payment_intents/%s', rawurlencode($paymentIntentId)),
            [
                'auth_bearer' => trim($this->stripeSecretKey),
                'timeout' => 25,
            ]
        );

        $statusCode = $response->getStatusCode();
        $payload = $response->toArray(false);

        if ($statusCode >= 400) {
            $message = $this->extractStripeErrorMessage($payload);
            throw new \RuntimeException(
                sprintf('Impossible de lire le PaymentIntent Stripe (%d): %s', $statusCode, $message)
            );
        }

        return is_array($payload) ? $payload : [];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Stripe non configure. Definis STRIPE_SECRET_KEY dans .env.local.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractStripeErrorMessage(array $payload): string
    {
        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            $message = trim((string) ($error['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return 'Erreur Stripe inconnue.';
    }

    private function normalizeCurrencyCode(string $currency): string
    {
        $normalized = strtolower(trim($currency));
        if ($normalized === '' || $normalized === 'dtn') {
            return 'tnd';
        }

        return $normalized;
    }
}
