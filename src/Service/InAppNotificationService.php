<?php

namespace App\Service;

use App\Entity\InAppNotification;
use App\Entity\PaymentTransaction;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\InAppNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class InAppNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InAppNotificationRepository $inAppNotificationRepository
    ) {
    }

    public function notifyTutorPaymentReceived(
        Reservation $reservation,
        PaymentTransaction $transaction,
        string $provider,
        string $externalRef,
        \DateTimeImmutable $paidAt
    ): ?InAppNotification {
        $seance = $reservation->getSeance();
        $tuteur = $seance?->getTuteur();

        if (!$tuteur instanceof User) {
            return null;
        }

        $reservationId = (int) ($reservation->getId() ?? 0);
        if ($reservationId <= 0) {
            return null;
        }

        $eventSuffix = trim($externalRef);
        if ($eventSuffix === '') {
            $eventSuffix = trim((string) ($transaction->getStripePaymentIntentId() ?? ''));
        }
        if ($eventSuffix === '') {
            $eventSuffix = 'tx-' . (int) ($transaction->getId() ?? 0);
        }

        $eventKey = sprintf('payment_paid:%d:%s', $reservationId, $eventSuffix);
        $existing = $this->inAppNotificationRepository->findOneByRecipientAndEventKey($tuteur, $eventKey);
        if ($existing instanceof InAppNotification) {
            return null;
        }

        $studentName = trim((string) ($reservation->getParticipant()?->getFullName() ?? 'Etudiant'));
        $matiere = trim((string) ($seance?->getMatiere() ?? 'Seance'));
        $seanceDate = $seance?->getStartAt();
        $currency = strtoupper((string) ($transaction->getCurrency() ?? 'TND'));
        $amountCents = max(0, (int) ($transaction->getAmountCents() ?? 0));
        $amount = number_format($amountCents / 100, 2, '.', ' ');
        $providerLabel = strtoupper(trim($provider)) !== '' ? strtoupper(trim($provider)) : 'PAYMENT';

        $title = 'Nouveau paiement recu';
        $message = sprintf(
            '%s a paye la reservation "%s" (%s %s) via %s.',
            $studentName !== '' ? $studentName : 'Un etudiant',
            $matiere !== '' ? $matiere : 'Seance',
            $amount,
            $currency,
            $providerLabel
        );

        $notification = new InAppNotification();
        $notification
            ->setRecipient($tuteur)
            ->setType(InAppNotification::TYPE_PAYMENT_RECEIVED)
            ->setEventKey($eventKey)
            ->setTitle($title)
            ->setMessage($message)
            ->setIsRead(false)
            ->setCreatedAt($paidAt)
            ->setData([
                'reservationId' => $reservationId,
                'seanceId' => (int) ($seance?->getId() ?? 0),
                'matiere' => $matiere,
                'studentName' => $studentName,
                'provider' => $providerLabel,
                'externalRef' => $eventSuffix,
                'amountCents' => $amountCents,
                'currency' => $currency,
                'seanceStartAt' => $seanceDate?->format(DATE_ATOM),
                'paidAt' => $paidAt->format(DATE_ATOM),
                'route' => '/seance/revision',
            ]);

        $this->entityManager->persist($notification);

        return $notification;
    }
}
