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
    public const PROVIDER_MOCK = 'mock';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentTransactionRepository $paymentTransactionRepository,
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
        return $this->stripeCheckoutService->isConfigured();
    }

    public function isStripeConfigured(): bool
    {
        return $this->isPaymentConfigured();
    }

    public function isStripeProvider(): bool
    {
        return $this->getProviderName() === self::PROVIDER_STRIPE;
    }

    public function isMockProvider(): bool
    {
        return $this->getProviderName() === self::PROVIDER_MOCK;
    }

    /**
     * @return array{paymentIntentId: string, clientSecret: string, publishableKey: string, amountCents: int, currency: string}
     */
    public function prepareStripeElementsPayment(Reservation $reservation): array
    {
        if (!$this->isStripeProvider()) {
            throw new \LogicException('Stripe Elements disponible uniquement avec PAYMENT_PROVIDER=stripe.');
        }
        if ((int) ($reservation->getStatus() ?? Reservation::STATUS_PENDING) === Reservation::STATUS_PAID) {
            throw new \LogicException('Cette reservation est deja payee.');
        }

        $intentData = $this->stripeCheckoutService->createPaymentIntent($reservation);
        $studentEmail = trim((string) ($reservation->getParticipant()?->getEmail() ?? ''));
        if ($studentEmail === '') {
            throw new \RuntimeException('Email participant manquant.');
        }

        $transaction = new PaymentTransaction();
        $transaction->setReservation($reservation)
            ->setStripeCheckoutSessionId((string) ($intentData['id'] ?? ''))
            ->setStripePaymentIntentId((string) ($intentData['id'] ?? ''))
            ->setAmountCents((int) ($intentData['amountCents'] ?? 0))
            ->setCurrency((string) ($intentData['currency'] ?? 'eur'))
            ->setStatus(PaymentTransaction::STATUS_PENDING)
            ->setStudentEmail($studentEmail)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setMetadata(['provider' => self::PROVIDER_STRIPE, 'flow' => 'elements']);

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return [
            'paymentIntentId' => (string) ($intentData['id'] ?? ''),
            'clientSecret' => (string) ($intentData['clientSecret'] ?? ''),
            'publishableKey' => $this->stripeCheckoutService->getPublishableKey(),
            'amountCents' => (int) ($intentData['amountCents'] ?? 0),
            'currency' => strtolower((string) ($intentData['currency'] ?? 'eur')),
        ];
    }

    public function createCheckoutForReservation(Reservation $reservation): string
    {
        if ((int) ($reservation->getStatus() ?? Reservation::STATUS_PENDING) === Reservation::STATUS_PAID) {
            throw new \LogicException('Cette reservation est deja payee.');
        }
        if ($this->isStripeProvider()) {
            $sessionData = $this->stripeCheckoutService->createCheckoutSession($reservation);
            $transaction = new PaymentTransaction();
            $transaction->setReservation($reservation)
                ->setStripeCheckoutSessionId((string) ($sessionData['sessionId'] ?? ''))
                ->setAmountCents((int) ($sessionData['amountCents'] ?? 0))
                ->setCurrency((string) ($sessionData['currency'] ?? 'eur'))
                ->setStatus(PaymentTransaction::STATUS_PENDING)
                ->setStudentEmail(trim((string) ($reservation->getParticipant()?->getEmail() ?? '')))
                ->setCreatedAt(new \DateTimeImmutable())
                ->setMetadata(['provider' => self::PROVIDER_STRIPE]);
            $this->entityManager->persist($transaction);
            $this->entityManager->flush();
            return (string) ($sessionData['checkoutUrl'] ?? '');
        }
        $reservationId = (int) ($reservation->getId() ?? 0);
        if ($reservationId <= 0) {
            throw new \RuntimeException('Reservation invalide.');
        }
        $paymentRef = sprintf('mock_%d_%d', $reservationId, (int) (microtime(true) * 1000));
        $transaction = new PaymentTransaction();
        $transaction->setReservation($reservation)
            ->setStripeCheckoutSessionId($paymentRef)
            ->setAmountCents($this->stripeCheckoutService->computeReservationAmountCents($reservation))
            ->setCurrency('eur')
            ->setStatus(PaymentTransaction::STATUS_PENDING)
            ->setStudentEmail(trim((string) ($reservation->getParticipant()?->getEmail() ?? '')))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setMetadata(['provider' => self::PROVIDER_MOCK]);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();
        $url = $this->router->generate('app_payment_success', ['id' => $reservationId], UrlGeneratorInterface::ABSOLUTE_URL);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'payment_ref=' . rawurlencode($paymentRef);
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
        if (str_starts_with($externalRef, 'pi_')) {
            $intent = $this->stripeCheckoutService->fetchPaymentIntent($externalRef);
            return $this->applyStripePaymentIntentPayload($intent);
        }
        $session = $this->stripeCheckoutService->fetchCheckoutSession($externalRef);
        return $this->applyStripeSessionPayload($session);
    }

    /** @param array<string, mixed> $sessionPayload */
    public function applyCheckoutSessionPayload(array $sessionPayload): ?PaymentTransaction
    {
        return $this->applyStripeSessionPayload($sessionPayload);
    }

    /** @param array<string, mixed> $sessionPayload */
    public function applyStripeSessionPayload(array $sessionPayload): ?PaymentTransaction
    {
        $sessionId = trim((string) ($sessionPayload['id'] ?? ''));
        if ($sessionId === '') {
            return null;
        }
        $transaction = $this->paymentTransactionRepository->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
        if (!$transaction instanceof PaymentTransaction) {
            return null;
        }
        $now = new \DateTimeImmutable();
        $paymentStatus = strtolower(trim((string) ($sessionPayload['payment_status'] ?? '')));
        $transaction->setUpdatedAt($now);
        $reservation = $transaction->getReservation();
        if ($paymentStatus === 'paid') {
            $transaction->setStatus(PaymentTransaction::STATUS_PAID)->setPaidAt($now)->setErrorMessage(null);
            if ($reservation instanceof Reservation && (int) ($reservation->getStatus() ?? 0) !== Reservation::STATUS_PAID) {
                $reservation->setStatus(Reservation::STATUS_PAID);
            }
            $this->createTutorPaymentNotification($transaction, $sessionId, self::PROVIDER_STRIPE, $now);
        }
        $this->entityManager->flush();
        return $transaction;
    }

    /** @param array<string, mixed> $intentPayload */
    public function applyStripePaymentIntentPayload(array $intentPayload): ?PaymentTransaction
    {
        $intentId = trim((string) ($intentPayload['id'] ?? ''));
        if ($intentId === '') {
            return null;
        }
        $transaction = $this->paymentTransactionRepository->findOneBy(['stripePaymentIntentId' => $intentId]);
        if (!$transaction instanceof PaymentTransaction) {
            $transaction = $this->paymentTransactionRepository->findOneBy(['stripeCheckoutSessionId' => $intentId]);
        }
        if (!$transaction instanceof PaymentTransaction) {
            return null;
        }
        $now = new \DateTimeImmutable();
        $status = strtolower(trim((string) ($intentPayload['status'] ?? '')));
        $transaction->setUpdatedAt($now);
        $reservation = $transaction->getReservation();
        if ($status === 'succeeded') {
            $transaction->setStatus(PaymentTransaction::STATUS_PAID)->setPaidAt($now)->setErrorMessage(null);
            if ($reservation instanceof Reservation && (int) ($reservation->getStatus() ?? 0) !== Reservation::STATUS_PAID) {
                $reservation->setStatus(Reservation::STATUS_PAID);
            }
            $this->createTutorPaymentNotification($transaction, $intentId, self::PROVIDER_STRIPE, $now);
        }
        $this->entityManager->flush();
        return $transaction;
    }

    public function markReservationCheckoutCanceled(Reservation $reservation): void
    {
        $transaction = $this->paymentTransactionRepository->findLatestPendingByReservation($reservation);
        if (!$transaction instanceof PaymentTransaction || (string) $transaction->getStatus() !== PaymentTransaction::STATUS_PENDING) {
            return;
        }
        $transaction->setStatus(PaymentTransaction::STATUS_CANCELED)->setUpdatedAt(new \DateTimeImmutable())->setErrorMessage('Paiement annule.');
        $this->entityManager->flush();
    }

    public function synchronizeLatestPendingForReservation(Reservation $reservation): ?PaymentTransaction
    {
        $pending = $this->paymentTransactionRepository->findLatestPendingByReservation($reservation);
        if (!$pending instanceof PaymentTransaction) {
            return null;
        }
        $ref = trim((string) $pending->getStripeCheckoutSessionId());
        return $ref !== '' ? $this->synchronizePaymentReference($ref) : null;
    }

    private function synchronizeMockPaymentReference(string $paymentRef): ?PaymentTransaction
    {
        $transaction = $this->paymentTransactionRepository->findOneBy(['stripeCheckoutSessionId' => $paymentRef]);
        if (!$transaction instanceof PaymentTransaction || $transaction->getStatus() === PaymentTransaction::STATUS_PAID) {
            return $transaction instanceof PaymentTransaction ? $transaction : null;
        }
        $now = new \DateTimeImmutable();
        $transaction->setStatus(PaymentTransaction::STATUS_PAID)->setPaidAt($now)->setUpdatedAt($now)->setErrorMessage(null);
        $reservation = $transaction->getReservation();
        if ($reservation instanceof Reservation && (int) ($reservation->getStatus() ?? 0) !== Reservation::STATUS_PAID) {
            $reservation->setStatus(Reservation::STATUS_PAID);
        }
        $this->createTutorPaymentNotification($transaction, $paymentRef, self::PROVIDER_MOCK, $now);
        $this->entityManager->flush();
        return $transaction;
    }

    private function getProviderName(): string
    {
        $p = strtolower(trim($this->paymentProvider));
        return $p === self::PROVIDER_MOCK ? self::PROVIDER_MOCK : self::PROVIDER_STRIPE;
    }

    private function createTutorPaymentNotification(PaymentTransaction $transaction, string $externalRef, string $provider, \DateTimeImmutable $paidAt): void
    {
        $reservation = $transaction->getReservation();
        if (!$reservation instanceof Reservation) {
            return;
        }
        try {
            $this->inAppNotificationService->notifyTutorPaymentReceived($reservation, $transaction, $provider, $externalRef, $paidAt);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur notification paiement tuteur.', ['message' => $e->getMessage()]);
        }
    }
}
