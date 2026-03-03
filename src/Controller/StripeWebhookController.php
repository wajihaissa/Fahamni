<?php

namespace App\Controller;

use App\Service\PaymentService;
use App\Service\StripeCheckoutService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    #[Route('/payment/stripe/webhook', name: 'app_payment_stripe_webhook', methods: ['POST'])]
    public function handleStripeWebhook(
        Request $request,
        StripeCheckoutService $stripeCheckoutService,
        PaymentService $paymentService,
        LoggerInterface $logger
    ): JsonResponse {
        if (!$paymentService->isStripeProvider()) {
            return $this->json(['message' => 'Provider Stripe inactif.'], Response::HTTP_ACCEPTED);
        }

        $payload = (string) $request->getContent();
        $signature = (string) $request->headers->get('Stripe-Signature', '');

        if ($stripeCheckoutService->getWebhookSecret() === '') {
            return $this->json(['message' => 'Webhook Stripe non configure.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$stripeCheckoutService->verifyWebhookSignature($payload, $signature)) {
            return $this->json(['message' => 'Signature webhook invalide.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($event)) {
            return $this->json(['message' => 'Event Stripe invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $eventType = (string) ($event['type'] ?? '');
        $eventObject = $event['data']['object'] ?? null;

        if (!is_array($eventObject)) {
            return $this->json(['received' => true, 'ignored' => true], Response::HTTP_OK);
        }

        try {
            switch ($eventType) {
                case 'checkout.session.completed':
                case 'checkout.session.expired':
                case 'checkout.session.async_payment_failed':
                case 'checkout.session.async_payment_succeeded':
                    $paymentService->applyStripeSessionPayload($eventObject);
                    break;
                case 'payment_intent.succeeded':
                case 'payment_intent.payment_failed':
                case 'payment_intent.canceled':
                    $paymentService->applyStripePaymentIntentPayload($eventObject);
                    break;
                default:
                    break;
            }
        } catch (\Throwable $exception) {
            $logger->error('Erreur traitement webhook Stripe', [
                'event_type' => $eventType,
                'message' => $exception->getMessage(),
            ]);

            return $this->json(['message' => 'Erreur traitement webhook.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['received' => true], Response::HTTP_OK);
    }
}
