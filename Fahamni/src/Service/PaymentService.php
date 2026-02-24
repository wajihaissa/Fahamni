<?php

namespace App\Service;

use App\Entity\PaymentTransaction;
use App\Entity\Reservation;
use App\Repository\PaymentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class PaymentService
{
    public const PROVIDER_STRIPE = 'stripe';
    public const PROVIDER_KONNECT = 'konnect';
    public const PROVIDER_MOCK = 'mock';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentTransactionRepository $paymentTransactionRepository,
        private readonly KonnectCheckoutService $konnectCheckoutService,
        private readonly StripeCheckoutService $stripeCheckoutService,
        private readonly InAppNotificationService $inAppNotificationService,
        private readonly LoggerInterface $logger,
        private readonly RouterInterface $router,
        private readonly string $paymentProvider = self::PROVIDER_STRIPE
    ) {
    }

    public function isPaymentConfigured(): bool
    {
        if ($this->isMockProvider()) {
            return true;
        }

        if ($this->isStripeProvider()) {
            return $this->stripeCheckoutService->isConfigured();
        }

        return $this->konnectCheckoutService->isConfigured();
    }

    // Backward compatibility
    public function isStripeConfigured(): bool
    {
        return $this->isPaymentConfigured();
    }

    public function isStripeProvider(): bool
    {
        return $this->getProviderName() === self::PROVIDER_STRIPE;
    }

    public function isKonnectProvider(): bool
    {
        return $this->getProviderName() === self::PROVIDER_KONNECT;
    }

    public function isMockProvider(): bool
    {
        return $this->getProviderName() === self::PROVIDER_MOCK;
    }

    /**
     * @return array{
     *     paymentIntentId: string,
     *     clientSecret: string,
     *     publishableKey: string,
     *     amountCents: int,
     *     currency: string
     * }
     */
    public function prepareStripeElementsPayment(Reservation $reservation): array
    {
        if (!$this->isStripeProvider()) {
            throw new \LogicException('Stripe Elements disponible uniquement avec PAYMENT_PROVIDER=stripe.');
        }

        if ((int) ($reservation->getStatus() ?? Reservation::STATUS_PENDING) === Reservation::STATUS_PAID) {
            throw new \LogicException('Cette reservation est deja payee.');
        }

        $targetStripeCurrency = $this->stripeCheckoutService->getDefaultCurrencyCode();
        $existing = $this->paymentTransactionRepository->findLatestPendingByReservation($reservation);
        if ($existing instanceof PaymentTransaction) {
            $existingProvider = strtolower(trim((string) (($existing->getMetadata() ?? [])['provider'] ?? '')));
            $existingRef = trim((string) ($existing->getStripeCheckoutSessionId() ?? ''));

            if ($existingProvider === self::PROVIDER_STRIPE && str_starts_with($existingRef, 'pi_')) {
                try {
                    $intent = $this->stripeCheckoutService->fetchPaymentIntent($existingRef);
                    $status = strtolower(trim((string) ($intent['status'] ?? '')));

                    if ($status === 'succeeded') {
                        $this->applyStripePaymentIntentPayload($intent);
                    } else {
                        $clientSecret = trim((string) ($intent['client_secret'] ?? ''));
                        $intentCurrency = $this->normalizeCurrencyCode(
                            (string) ($intent['currency'] ?? $existing->getCurrency() ?? '')
                        );
                        if ($clientSecret !== '' && in_array($status, [
                            'requires_payment_method',
                            'requires_confirmation',
                            'requires_action',
                            'processing',
                        ], true) && $intentCurrency === $targetStripeCurrency) {
                            return [
                                'paymentIntentId' => $existingRef,
                                'clientSecret' => $clientSecret,
                                'publishableKey' => $this->stripeCheckoutService->getPublishableKey(),
                                'amountCents' => (int) ($intent['amount'] ?? $existing->getAmountCents() ?? 0),
                                'currency' => $intentCurrency,
                            ];
                        }
                    }
                } catch (\Throwable) {
                    // create a new intent
                }
            }
        }

        $intentData = $this->stripeCheckoutService->createPaymentIntent($reservation);
        $studentEmail = trim((string) ($reservation->getParticipant()?->getEmail() ?? ''));
        if ($studentEmail === '') {
            throw new \RuntimeException('Email participant manquant.');
        }

        $transaction = new PaymentTransaction();
        $transaction
            ->setReservation($reservation)
            ->setStripeCheckoutSessionId((string) ($intentData['id'] ?? ''))
            ->setStripePaymentIntentId((string) ($intentData['id'] ?? ''))
            ->setAmountCents((int) ($intentData['amountCents'] ?? 0))
            ->setCurrency((string) ($intentData['currency'] ?? 'tnd'))
            ->setStatus(PaymentTransaction::STATUS_PENDING)
            ->setStudentEmail($studentEmail)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setMetadata([
                'provider' => self::PROVIDER_STRIPE,
                'flow' => 'elements',
                'reservationId' => $reservation->getId(),
                'seanceId' => $reservation->getSeance()?->getId(),
            ]);

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return [
            'paymentIntentId' => (string) ($intentData['id'] ?? ''),
            'clientSecret' => (string) ($intentData['clientSecret'] ?? ''),
            'publishableKey' => $this->stripeCheckoutService->getPublishableKey(),
            'amountCents' => (int) ($intentData['amountCents'] ?? 0),
            'currency' => strtolower((string) ($intentData['currency'] ?? 'tnd')),
        ];
    }

    public function createCheckoutForReservation(Reservation $reservation): string
    {
        if ((int) ($reservation->getStatus() ?? Reservation::STATUS_PENDING) === Reservation::STATUS_PAID) {
            throw new \LogicException('Cette reservation est deja payee.');
        }

        $latestPending = $this->paymentTransactionRepository->findLatestPendingByReservation($reservation);
        if ($latestPending instanceof PaymentTransaction) {
            $createdAt = $latestPending->getCreatedAt();
            if ($createdAt instanceof \DateTimeImmutable && $createdAt > (new \DateTimeImmutable('-20 minutes'))) {
                if ($this->canReusePendingTransactionForCurrentCurrency($latestPending)) {
                    $existingUrl = $this->resolveExistingPendingRedirectUrl($latestPending);
                    if ($existingUrl !== null) {
                        return $existingUrl;
                    }
                }
            }
        }

        $paymentData = $this->createProviderPaymentData($reservation);
        $studentEmail = trim((string) ($reservation->getParticipant()?->getEmail() ?? ''));
        if ($studentEmail === '') {
            throw new \RuntimeException('Email participant manquant.');
        }

        $provider = $this->getProviderName();
        $externalRef = (string) ($paymentData['externalRef'] ?? '');
        $redirectUrl = (string) ($paymentData['redirectUrl'] ?? '');
        $amountMinor = (int) ($paymentData['amountMinor'] ?? 0);
        $currency = (string) ($paymentData['currency'] ?? 'TND');

        if ($externalRef === '' || $redirectUrl === '') {
            throw new \RuntimeException('Reponse paiement invalide (reference/url manquante).');
        }

        $transaction = new PaymentTransaction();
        $transaction
            ->setReservation($reservation)
            ->setStripeCheckoutSessionId($externalRef)
            ->setAmountCents($amountMinor)
            ->setCurrency($currency)
            ->setStatus(PaymentTransaction::STATUS_PENDING)
            ->setStudentEmail($studentEmail)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setMetadata([
                'reservationId' => $reservation->getId(),
                'seanceId' => $reservation->getSeance()?->getId(),
                'source' => $provider . '_checkout',
                'provider' => $provider,
                'redirectUrl' => $redirectUrl,
                'payUrl' => $redirectUrl,
            ]);

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $redirectUrl;
    }

    public function synchronizeCheckoutSession(string $sessionId): ?PaymentTransaction
    {
        return $this->synchronizePaymentReference($sessionId);
    }

    public function synchronizePaymentReference(string $externalRef): ?PaymentTransaction
    {
        $externalRef = trim($externalRef);
        if ($externalRef === '') {
            return null;
        }

        if ($this->isMockProvider()) {
            return $this->synchronizeMockPaymentReference($externalRef);
        }

        if ($this->isStripeProvider()) {
            if (str_starts_with($externalRef, 'pi_')) {
                $intent = $this->stripeCheckoutService->fetchPaymentIntent($externalRef);

                return $this->applyStripePaymentIntentPayload($intent);
            }

            $session = $this->stripeCheckoutService->fetchCheckoutSession($externalRef);

            return $this->applyStripeSessionPayload($session);
        }

        $paymentDetails = $this->konnectCheckoutService->getPaymentDetails($externalRef);

        return $this->applyPaymentDetailsPayload($paymentDetails, $externalRef);
    }

    /**
     * Backward-compatible alias for old Stripe webhook flow.
     *
     * @param array<string, mixed> $sessionPayload
     */
    public function applyCheckoutSessionPayload(array $sessionPayload): ?PaymentTransaction
    {
        return $this->applyStripeSessionPayload($sessionPayload);
    }

    /**
     * @param array<string, mixed> $sessionPayload
     */
    public function applyStripeSessionPayload(array $sessionPayload): ?PaymentTransaction
    {
        $sessionId = trim((string) ($sessionPayload['id'] ?? ''));
        if ($sessionId === '') {
            return null;
        }

        $transaction = $this->paymentTransactionRepository->findOneBy([
            'stripeCheckoutSessionId' => $sessionId,
        ]);

        if (!$transaction instanceof PaymentTransaction) {
            $this->logger->warning('Stripe session recue sans transaction locale.', [
                'session_id' => $sessionId,
            ]);

            return null;
        }

        $now = new \DateTimeImmutable();
        $paymentStatus = strtolower(trim((string) ($sessionPayload['payment_status'] ?? '')));
        $checkoutStatus = strtolower(trim((string) ($sessionPayload['status'] ?? '')));
        $previousStatus = (string) ($transaction->getStatus() ?? PaymentTransaction::STATUS_PENDING);
        $paidTransitioned = false;

        $transaction->setUpdatedAt($now);
        $transaction->setStripePaymentIntentId($this->resolveStripePaymentIntentId($sessionPayload));
        $transaction->setPaymentMethodType($this->resolveStripePaymentMethodType($sessionPayload));

        $reservation = $transaction->getReservation();

        if ($paymentStatus === 'paid') {
            if ($previousStatus !== PaymentTransaction::STATUS_PAID) {
                $paidTransitioned = true;
            }
            $transaction->setStatus(PaymentTransaction::STATUS_PAID);
            $transaction->setPaidAt($now);
            $transaction->setErrorMessage(null);

            if ($reservation instanceof Reservation && (int) ($reservation->getStatus() ?? 0) !== Reservation::STATUS_PAID) {
                $reservation->setStatus(Reservation::STATUS_PAID);
                $reservation->setNotes($this->appendPaymentNote(
                    $reservation->getNotes(),
                    $sessionId,
                    $now,
                    self::PROVIDER_STRIPE
                ));
            }
        } elseif ($checkoutStatus === 'expired') {
            if ($previousStatus !== PaymentTransaction::STATUS_PAID) {
                $transaction->setStatus(PaymentTransaction::STATUS_EXPIRED);
            }
        } elseif ($checkoutStatus === 'complete' && $paymentStatus !== 'paid') {
            if ($previousStatus !== PaymentTransaction::STATUS_PAID) {
                $transaction->setStatus(PaymentTransaction::STATUS_FAILED);
                $transaction->setErrorMessage('Paiement Stripe incomplet ou refuse.');
            }
        }

        $transaction->setMetadata($this->buildSafeStripeMetadata($sessionPayload, $transaction));
        if ($paidTransitioned) {
            $this->createTutorPaymentNotification(
                $transaction,
                $sessionId,
                self::PROVIDER_STRIPE,
                $now
            );
        }
        $this->entityManager->flush();

        return $transaction;
    }

    /**
     * @param array<string, mixed> $intentPayload
     */
    public function applyStripePaymentIntentPayload(array $intentPayload): ?PaymentTransaction
    {
        $intentId = trim((string) ($intentPayload['id'] ?? ''));
        if ($intentId === '') {
            return null;
        }

        $transaction = $this->paymentTransactionRepository->findOneBy([
            'stripeCheckoutSessionId' => $intentId,
        ]);

        if (!$transaction instanceof PaymentTransaction) {
            $transaction = $this->paymentTransactionRepository->findOneBy([
                'stripePaymentIntentId' => $intentId,
            ]);
        }

        if (!$transaction instanceof PaymentTransaction) {
            $this->logger->warning('Stripe payment_intent recu sans transaction locale.', [
                'payment_intent' => $intentId,
            ]);

            return null;
        }

        $now = new \DateTimeImmutable();
        $status = strtolower(trim((string) ($intentPayload['status'] ?? '')));
        $previousStatus = (string) ($transaction->getStatus() ?? PaymentTransaction::STATUS_PENDING);
        $paidTransitioned = false;
        $transaction->setUpdatedAt($now);
        $transaction->setStripePaymentIntentId($intentId);
        $transaction->setPaymentMethodType($this->resolveStripePaymentMethodType($intentPayload));

        $reservation = $transaction->getReservation();

        if ($status === 'succeeded') {
            if ($previousStatus !== PaymentTransaction::STATUS_PAID) {
                $paidTransitioned = true;
            }
            $transaction->setStatus(PaymentTransaction::STATUS_PAID);
            $transaction->setPaidAt($now);
            $transaction->setErrorMessage(null);

            if ($reservation instanceof Reservation && (int) ($reservation->getStatus() ?? 0) !== Reservation::STATUS_PAID) {
                $reservation->setStatus(Reservation::STATUS_PAID);
                $reservation->setNotes($this->appendPaymentNote(
                    $reservation->getNotes(),
                    $intentId,
                    $now,
                    self::PROVIDER_STRIPE
                ));
            }
        } elseif (in_array($status, ['canceled', 'cancelled'], true)) {
            if ($previousStatus !== PaymentTransaction::STATUS_PAID) {
                $transaction->setStatus(PaymentTransaction::STATUS_CANCELED);
                $transaction->setErrorMessage('Paiement Stripe annule.');
            }
        } elseif ($status === 'requires_payment_method') {
            if ($previousStatus !== PaymentTransaction::STATUS_PAID) {
                $transaction->setStatus(PaymentTransaction::STATUS_FAILED);
                $transaction->setErrorMessage('Paiement Stripe echoue ou incomplet.');
            }
        }

        $transaction->setMetadata($this->buildSafeStripeMetadata($intentPayload, $transaction));
        if ($paidTransitioned) {
            $this->createTutorPaymentNotification(
                $transaction,
                $intentId,
                self::PROVIDER_STRIPE,
                $now
            );
        }
        $this->entityManager->flush();

        return $transaction;
    }

    /**
     * @param array<string, mixed> $paymentPayload
     */
    public function applyPaymentDetailsPayload(array $paymentPayload, ?string $paymentRef = null): ?PaymentTransaction
    {
        $resolvedPaymentRef = trim((string) ($paymentRef ?? ($paymentPayload['paymentRef'] ?? '')));
        if ($resolvedPaymentRef === '') {
            return null;
        }

        $transaction = $this->paymentTransactionRepository->findOneBy([
            'stripeCheckoutSessionId' => $resolvedPaymentRef,
        ]);

        if (!$transaction instanceof PaymentTransaction) {
            $this->logger->warning('Konnect payment recu sans transaction locale.', [
                'payment_ref' => $resolvedPaymentRef,
            ]);

            return null;
        }

        $now = new \DateTimeImmutable();
        $payment = $paymentPayload['payment'] ?? null;
        $paymentStatus = is_array($payment)
            ? strtolower(trim((string) ($payment['status'] ?? '')))
            : strtolower(trim((string) ($paymentPayload['status'] ?? '')));
        $previousStatus = (string) ($transaction->getStatus() ?? PaymentTransaction::STATUS_PENDING);
        $paidTransitioned = false;

        $transaction->setUpdatedAt($now);
        $transaction->setStripePaymentIntentId($this->resolveKonnectTransactionId($paymentPayload));
        $transaction->setPaymentMethodType($this->resolveKonnectPaymentMethodType($paymentPayload));

        $reservation = $transaction->getReservation();

        if (in_array($paymentStatus, ['completed', 'paid'], true)) {
            if ($previousStatus !== PaymentTransaction::STATUS_PAID) {
                $paidTransitioned = true;
            }
            $transaction->setStatus(PaymentTransaction::STATUS_PAID);
            $transaction->setPaidAt($now);
            $transaction->setErrorMessage(null);

            if ($reservation instanceof Reservation && (int) ($reservation->getStatus() ?? 0) !== Reservation::STATUS_PAID) {
                $reservation->setStatus(Reservation::STATUS_PAID);
                $reservation->setNotes($this->appendPaymentNote(
                    $reservation->getNotes(),
                    $resolvedPaymentRef,
                    $now,
                    self::PROVIDER_KONNECT
                ));
            }
        } elseif (in_array($paymentStatus, ['failed', 'cancelled', 'canceled'], true)) {
            if ($previousStatus !== PaymentTransaction::STATUS_PAID) {
                $transaction->setStatus(
                    in_array($paymentStatus, ['cancelled', 'canceled'], true)
                        ? PaymentTransaction::STATUS_CANCELED
                        : PaymentTransaction::STATUS_FAILED
                );
                $transaction->setErrorMessage('Paiement Konnect non abouti.');
            }
        }

        $transaction->setMetadata($this->buildSafeKonnectMetadata($paymentPayload, $transaction));
        if ($paidTransitioned) {
            $this->createTutorPaymentNotification(
                $transaction,
                $resolvedPaymentRef,
                self::PROVIDER_KONNECT,
                $now
            );
        }
        $this->entityManager->flush();

        return $transaction;
    }

    public function markReservationCheckoutCanceled(Reservation $reservation): void
    {
        $transaction = $this->paymentTransactionRepository->findLatestPendingByReservation($reservation);
        if (!$transaction instanceof PaymentTransaction) {
            return;
        }

        if ((string) $transaction->getStatus() !== PaymentTransaction::STATUS_PENDING) {
            return;
        }

        $transaction
            ->setStatus(PaymentTransaction::STATUS_CANCELED)
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setErrorMessage('Paiement annule par l\'utilisateur.');

        $this->entityManager->flush();
    }

    public function synchronizeLatestPendingForReservation(Reservation $reservation): ?PaymentTransaction
    {
        $pending = $this->paymentTransactionRepository->findLatestPendingByReservation($reservation);
        if (!$pending instanceof PaymentTransaction) {
            return null;
        }

        $externalRef = trim((string) $pending->getStripeCheckoutSessionId());
        if ($externalRef === '') {
            return null;
        }

        return $this->synchronizePaymentReference($externalRef);
    }

    /**
     * @return array{
     *     externalRef: string,
     *     redirectUrl: string,
     *     amountMinor: int,
     *     currency: string
     * }
     */
    private function createProviderPaymentData(Reservation $reservation): array
    {
        if ($this->isMockProvider()) {
            return $this->createMockPaymentData($reservation);
        }

        if ($this->isStripeProvider()) {
            $sessionData = $this->stripeCheckoutService->createCheckoutSession($reservation);

            return [
                'externalRef' => (string) ($sessionData['sessionId'] ?? ''),
                'redirectUrl' => (string) ($sessionData['checkoutUrl'] ?? ''),
                'amountMinor' => (int) ($sessionData['amountCents'] ?? 0),
                'currency' => strtolower((string) ($sessionData['currency'] ?? 'tnd')),
            ];
        }

        $konnectData = $this->konnectCheckoutService->createPayment($reservation);

        return [
            'externalRef' => (string) ($konnectData['paymentRef'] ?? ''),
            'redirectUrl' => (string) ($konnectData['payUrl'] ?? ''),
            'amountMinor' => (int) ($konnectData['amountMinor'] ?? 0),
            'currency' => strtoupper((string) ($konnectData['currency'] ?? 'TND')),
        ];
    }

    private function resolveExistingPendingRedirectUrl(PaymentTransaction $transaction): ?string
    {
        $metadata = is_array($transaction->getMetadata()) ? $transaction->getMetadata() : [];

        if ($this->isStripeProvider()) {
            try {
                $session = $this->stripeCheckoutService->fetchCheckoutSession(
                    (string) ($transaction->getStripeCheckoutSessionId() ?? '')
                );

                $status = strtolower(trim((string) ($session['status'] ?? '')));
                $url = trim((string) ($session['url'] ?? ''));
                if ($status === 'open' && $url !== '') {
                    return $url;
                }
            } catch (\Throwable) {
                return null;
            }

            return null;
        }

        $redirectUrl = trim((string) ($metadata['redirectUrl'] ?? $metadata['payUrl'] ?? ''));

        return $redirectUrl !== '' ? $redirectUrl : null;
    }

    /**
     * @return array{
     *     externalRef: string,
     *     redirectUrl: string,
     *     amountMinor: int,
     *     currency: string
     * }
     */
    private function createMockPaymentData(Reservation $reservation): array
    {
        $reservationId = (int) ($reservation->getId() ?? 0);
        if ($reservationId <= 0) {
            throw new \RuntimeException('Reservation invalide.');
        }

        $paymentRef = sprintf('mock_%d_%d', $reservationId, (int) (microtime(true) * 1000));
        $amountMinor = $this->konnectCheckoutService->computeReservationAmountMinor($reservation);

        $redirectUrl = $this->router->generate(
            'app_payment_success',
            ['id' => $reservationId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'payment_ref=' . rawurlencode($paymentRef);

        return [
            'externalRef' => $paymentRef,
            'redirectUrl' => $redirectUrl,
            'amountMinor' => $amountMinor,
            'currency' => 'TND',
        ];
    }

    private function synchronizeMockPaymentReference(string $paymentRef): ?PaymentTransaction
    {
        $transaction = $this->paymentTransactionRepository->findOneBy([
            'stripeCheckoutSessionId' => $paymentRef,
        ]);
        if (!$transaction instanceof PaymentTransaction) {
            return null;
        }

        if ($transaction->getStatus() === PaymentTransaction::STATUS_PAID) {
            return $transaction;
        }

        $now = new \DateTimeImmutable();
        $transaction
            ->setStatus(PaymentTransaction::STATUS_PAID)
            ->setPaidAt($now)
            ->setUpdatedAt($now)
            ->setErrorMessage(null)
            ->setMetadata(array_merge(
                is_array($transaction->getMetadata()) ? $transaction->getMetadata() : [],
                [
                    'provider' => self::PROVIDER_MOCK,
                    'mockAuto' => true,
                    'paymentRef' => $paymentRef,
                ]
            ));

        $reservation = $transaction->getReservation();
        if ($reservation instanceof Reservation && (int) ($reservation->getStatus() ?? 0) !== Reservation::STATUS_PAID) {
            $reservation->setStatus(Reservation::STATUS_PAID);
            $reservation->setNotes($this->appendPaymentNote(
                $reservation->getNotes(),
                $paymentRef,
                $now,
                self::PROVIDER_MOCK
            ));
        }

        $this->createTutorPaymentNotification(
            $transaction,
            $paymentRef,
            self::PROVIDER_MOCK,
            $now
        );
        $this->entityManager->flush();

        return $transaction;
    }

    private function getProviderName(): string
    {
        $provider = strtolower(trim($this->paymentProvider));

        return match ($provider) {
            self::PROVIDER_MOCK => self::PROVIDER_MOCK,
            self::PROVIDER_KONNECT => self::PROVIDER_KONNECT,
            default => self::PROVIDER_STRIPE,
        };
    }

    private function canReusePendingTransactionForCurrentCurrency(PaymentTransaction $transaction): bool
    {
        if (!$this->isStripeProvider()) {
            return true;
        }

        $expected = $this->stripeCheckoutService->getDefaultCurrencyCode();
        $current = $this->normalizeCurrencyCode((string) ($transaction->getCurrency() ?? ''));

        return $current !== '' && $current === $expected;
    }

    private function normalizeCurrencyCode(string $currency): string
    {
        $normalized = strtolower(trim($currency));
        if ($normalized === 'dtn') {
            return 'tnd';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveStripePaymentIntentId(array $payload): ?string
    {
        $value = $payload['payment_intent'] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_array($value)) {
            $id = trim((string) ($value['id'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveStripePaymentMethodType(array $payload): ?string
    {
        $types = $payload['payment_method_types'] ?? null;
        if (!is_array($types) || $types === []) {
            return null;
        }

        $first = trim((string) ($types[0] ?? ''));

        return $first !== '' ? $first : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveKonnectTransactionId(array $payload): ?string
    {
        $payment = $payload['payment'] ?? null;
        if (is_array($payment)) {
            foreach (['id', 'transactionId', 'txid'] as $key) {
                $value = trim((string) ($payment[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach (['id', 'transactionId', 'txid'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveKonnectPaymentMethodType(array $payload): ?string
    {
        $payment = $payload['payment'] ?? null;
        if (is_array($payment)) {
            foreach (['method', 'paymentMethod', 'type'] as $key) {
                $value = trim((string) ($payment[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function buildSafeStripeMetadata(array $payload, PaymentTransaction $transaction): array
    {
        $existing = is_array($transaction->getMetadata()) ? $transaction->getMetadata() : [];
        $metadata = $payload['metadata'] ?? [];
        $safeMetadata = is_array($metadata) ? $metadata : [];

        $safeMetadata = array_merge($existing, $safeMetadata);
        $safeMetadata['provider'] = self::PROVIDER_STRIPE;
        $safeMetadata['session_id'] = (string) ($payload['id'] ?? $transaction->getStripeCheckoutSessionId() ?? '');
        $safeMetadata['payment_status'] = (string) ($payload['payment_status'] ?? ($payload['status'] ?? ''));
        $safeMetadata['checkout_status'] = (string) ($payload['status'] ?? '');
        $safeMetadata['amount_total'] = $payload['amount_total'] ?? ($payload['amount'] ?? null);
        $safeMetadata['currency'] = $payload['currency'] ?? null;
        $safeMetadata['url'] = $payload['url'] ?? null;

        return $safeMetadata;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function buildSafeKonnectMetadata(array $payload, PaymentTransaction $transaction): array
    {
        $safeMetadata = is_array($transaction->getMetadata()) ? $transaction->getMetadata() : [];
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];

        $safeMetadata['provider'] = self::PROVIDER_KONNECT;
        $safeMetadata['paymentRef'] = (string) ($payload['paymentRef'] ?? $transaction->getStripeCheckoutSessionId() ?? '');
        $safeMetadata['status'] = (string) ($payment['status'] ?? $payload['status'] ?? '');
        $safeMetadata['amount'] = $payment['amount'] ?? $payload['amount'] ?? null;
        $safeMetadata['token'] = $payment['token'] ?? $payload['token'] ?? null;
        $safeMetadata['raw'] = $payload;

        return $safeMetadata;
    }

    private function appendPaymentNote(
        ?string $existingNotes,
        string $externalRef,
        \DateTimeImmutable $paidAt,
        string $provider
    ): string {
        $prefix = trim((string) $existingNotes);
        $providerLabel = match ($provider) {
            self::PROVIDER_STRIPE => 'Stripe session',
            self::PROVIDER_KONNECT => 'Konnect ref',
            default => 'Mock ref',
        };
        $paymentLine = sprintf('[PAID] %s %s le %s', $providerLabel, $externalRef, $paidAt->format('d/m/Y H:i'));

        if ($prefix === '') {
            return $paymentLine;
        }

        if (str_contains($prefix, $externalRef)) {
            return $prefix;
        }

        return $prefix . "\n" . $paymentLine;
    }

    private function createTutorPaymentNotification(
        PaymentTransaction $transaction,
        string $externalRef,
        string $provider,
        \DateTimeImmutable $paidAt
    ): void {
        $reservation = $transaction->getReservation();
        if (!$reservation instanceof Reservation) {
            return;
        }

        try {
            $this->inAppNotificationService->notifyTutorPaymentReceived(
                $reservation,
                $transaction,
                $provider,
                $externalRef,
                $paidAt
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Erreur creation notification paiement tuteur.', [
                'reservation_id' => $reservation->getId(),
                'payment_ref' => $externalRef,
                'provider' => $provider,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
