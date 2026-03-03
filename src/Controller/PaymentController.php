<?php

namespace App\Controller;

use App\Entity\PaymentTransaction;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\PaymentTransactionRepository;
use App\Service\PaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PaymentController extends AbstractController
{
    #[Route('/payment/reservation/{id}/checkout', name: 'app_payment_checkout', methods: ['POST'])]
    public function checkoutReservation(
        Reservation $reservation,
        Request $request,
        PaymentService $paymentService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$this->isCsrfTokenValid('pay_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertParticipantOwnsReservation($reservation, $user);

        $status = (int) ($reservation->getStatus() ?? Reservation::STATUS_PENDING);
        if ($status === Reservation::STATUS_PENDING) {
            $this->addFlash('warning', 'Paiement indisponible: la reservation doit etre acceptee par le tuteur.');

            return $this->redirectToRoute('app_seance_revision');
        }

        if ($status === Reservation::STATUS_PAID) {
            $this->addFlash('info', 'Cette reservation est deja payee.');

            return $this->redirectToRoute('app_seance_revision');
        }

        if ($status !== Reservation::STATUS_ACCEPTED) {
            $this->addFlash('warning', 'Statut de reservation incompatible avec le paiement.');

            return $this->redirectToRoute('app_seance_revision');
        }

        if (!$paymentService->isPaymentConfigured()) {
            $this->addFlash(
                'error',
                'Paiement non configure. Configure le provider actif (Stripe/Konnect) ou utilise PAYMENT_PROVIDER=mock.'
            );

            return $this->redirectToRoute('app_seance_revision');
        }

        if ($paymentService->isStripeProvider()) {
            return $this->redirectToRoute('app_payment_stripe_elements', ['id' => $reservation->getId()]);
        }

        try {
            $checkoutUrl = $paymentService->createCheckoutForReservation($reservation);

            return $this->redirect($checkoutUrl);
        } catch (\Throwable $exception) {
            $this->addFlash('error', 'Impossible de demarrer le paiement: ' . $exception->getMessage());

            return $this->redirectToRoute('app_seance_revision');
        }
    }

    #[Route('/payment/reservation/{id}/stripe-elements', name: 'app_payment_stripe_elements', methods: ['GET'])]
    public function stripeElementsPage(
        Reservation $reservation,
        PaymentService $paymentService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertParticipantOwnsReservation($reservation, $user);

        $status = (int) ($reservation->getStatus() ?? Reservation::STATUS_PENDING);
        if ($status === Reservation::STATUS_PENDING) {
            $this->addFlash('warning', 'Paiement indisponible: la reservation doit etre acceptee par le tuteur.');

            return $this->redirectToRoute('app_seance_revision');
        }
        if ($status === Reservation::STATUS_PAID) {
            $this->addFlash('info', 'Cette reservation est deja payee.');

            return $this->redirectToRoute('app_seance_revision');
        }
        if ($status !== Reservation::STATUS_ACCEPTED) {
            $this->addFlash('warning', 'Statut de reservation incompatible avec le paiement.');

            return $this->redirectToRoute('app_seance_revision');
        }
        if (!$paymentService->isStripeProvider()) {
            $this->addFlash('warning', 'Cette page est reservee au provider Stripe.');

            return $this->redirectToRoute('app_seance_revision');
        }

        try {
            $paymentData = $paymentService->prepareStripeElementsPayment($reservation);
        } catch (\Throwable $exception) {
            $this->addFlash('error', 'Impossible de preparer le paiement Stripe: ' . $exception->getMessage());

            return $this->redirectToRoute('app_seance_revision');
        }

        return $this->render('front/reservation/stripe_elements.html.twig', [
            'reservation' => $reservation,
            'seance' => $reservation->getSeance(),
            'stripePublishableKey' => (string) ($paymentData['publishableKey'] ?? ''),
            'stripeClientSecret' => (string) ($paymentData['clientSecret'] ?? ''),
            'paymentIntentId' => (string) ($paymentData['paymentIntentId'] ?? ''),
            'amountCents' => (int) ($paymentData['amountCents'] ?? 0),
            'currency' => strtoupper((string) ($paymentData['currency'] ?? 'TND')),
            'returnUrl' => $this->generateUrl(
                'app_payment_success',
                ['id' => $reservation->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            'cancelUrl' => $this->generateUrl(
                'app_payment_cancel',
                ['id' => $reservation->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
        ]);
    }

    #[Route('/payment/reservation/{id}/success', name: 'app_payment_success', methods: ['GET'])]
    public function paymentSuccess(
        Reservation $reservation,
        Request $request,
        PaymentService $paymentService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertParticipantOwnsReservation($reservation, $user);

        try {
            $sessionId = trim((string) $request->query->get('session_id', ''));
            $paymentRef = trim((string) $request->query->get('payment_ref', ''));
            $paymentIntentId = trim((string) $request->query->get('payment_intent', ''));
            $externalRef = $paymentIntentId !== ''
                ? $paymentIntentId
                : ($sessionId !== '' ? $sessionId : $paymentRef);
            $transaction = $externalRef !== ''
                ? $paymentService->synchronizePaymentReference($externalRef)
                : $paymentService->synchronizeLatestPendingForReservation($reservation);

            if ($transaction instanceof PaymentTransaction && $transaction->getStatus() === PaymentTransaction::STATUS_PAID) {
                $this->addFlash('success', 'Paiement confirme avec succes.');
            } else {
                $this->addFlash('warning', 'Paiement en cours de confirmation.');
            }
        } catch (\Throwable $exception) {
            $this->addFlash('warning', 'Paiement non confirme automatiquement: ' . $exception->getMessage());
        }

        return $this->redirectToRoute('app_seance_revision');
    }

    #[Route('/payment/reservation/{id}/cancel', name: 'app_payment_cancel', methods: ['GET'])]
    public function paymentCancel(
        Reservation $reservation,
        PaymentService $paymentService,
        Request $request,
        PaymentTransactionRepository $paymentTransactionRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        $this->assertParticipantOwnsReservation($reservation, $user);
        $finalTransaction = null;
        $sessionId = trim((string) $request->query->get('session_id', ''));
        $paymentRef = trim((string) $request->query->get('payment_ref', ''));
        $paymentIntentId = trim((string) $request->query->get('payment_intent', ''));
        $externalRef = $paymentIntentId !== ''
            ? $paymentIntentId
            : ($sessionId !== '' ? $sessionId : $paymentRef);
        if ($externalRef !== '') {
            try {
                $finalTransaction = $paymentService->synchronizePaymentReference($externalRef);
            } catch (\Throwable) {
                $paymentService->markReservationCheckoutCanceled($reservation);
            }
        } else {
            $latest = $paymentTransactionRepository->findLatestByReservation($reservation);
            if (!$latest instanceof PaymentTransaction || $latest->getStatus() !== PaymentTransaction::STATUS_PAID) {
                $paymentService->markReservationCheckoutCanceled($reservation);
            } else {
                $finalTransaction = $latest;
            }
        }

        if ($finalTransaction instanceof PaymentTransaction && $finalTransaction->getStatus() === PaymentTransaction::STATUS_PAID) {
            $this->addFlash('success', 'Paiement confirme avec succes.');
        } else {
            $this->addFlash('info', 'Paiement annule.');
        }

        return $this->redirectToRoute('app_seance_revision');
    }

    private function assertParticipantOwnsReservation(Reservation $reservation, User $user): void
    {
        if ($reservation->getParticipant()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas payer cette reservation.');
        }
    }
}
