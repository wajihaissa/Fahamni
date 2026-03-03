<?php

namespace App\Controller;

use App\Service\PaymentService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class KonnectWebhookController extends AbstractController
{
    #[Route('/payment/konnect/webhook', name: 'app_payment_konnect_webhook', methods: ['GET', 'POST'])]
    public function handleKonnectWebhook(
        Request $request,
        PaymentService $paymentService,
        LoggerInterface $logger
    ): JsonResponse {
        if (!$paymentService->isKonnectProvider()) {
            return $this->json(['message' => 'Provider Konnect inactif.'], Response::HTTP_ACCEPTED);
        }

        $paymentRef = trim((string) $request->query->get('payment_ref', ''));
        if ($paymentRef === '') {
            $payload = json_decode((string) $request->getContent(), true);
            if (is_array($payload)) {
                $paymentRef = trim((string) ($payload['payment_ref'] ?? $payload['paymentRef'] ?? ''));
            }
        }

        if ($paymentRef === '') {
            return $this->json(['message' => 'payment_ref manquant.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $transaction = $paymentService->synchronizePaymentReference($paymentRef);
            if ($transaction === null) {
                $logger->warning('Webhook Konnect: transaction locale introuvable.', ['payment_ref' => $paymentRef]);
            }
        } catch (\Throwable $exception) {
            $logger->error('Erreur traitement webhook Konnect.', [
                'payment_ref' => $paymentRef,
                'message' => $exception->getMessage(),
            ]);

            return $this->json(['message' => 'Erreur traitement webhook.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['received' => true], Response::HTTP_OK);
    }
}
