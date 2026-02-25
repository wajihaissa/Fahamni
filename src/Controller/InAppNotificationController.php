<?php

namespace App\Controller;

use App\Entity\InAppNotification;
use App\Entity\User;
use App\Repository\InAppNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/seance/revision/notifications')]
final class InAppNotificationController extends AbstractController
{
    #[Route('/live', name: 'app_in_app_notification_live', methods: ['GET'])]
    public function live(InAppNotificationRepository $inAppNotificationRepository): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifie.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isTutorRole()) {
            return $this->json([
                'unreadCount' => 0,
                'notifications' => [],
            ]);
        }

        $notifications = $inAppNotificationRepository->findLatestForRecipient($user, 18);
        $unreadCount = $inAppNotificationRepository->countUnreadForRecipient($user);

        return $this->json([
            'unreadCount' => $unreadCount,
            'notifications' => array_map(
                fn(InAppNotification $notification): array => $this->serializeNotification($notification),
                $notifications
            ),
            'serverTime' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    #[Route('/{id}/read', name: 'app_in_app_notification_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markRead(
        int $id,
        Request $request,
        InAppNotificationRepository $inAppNotificationRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifie.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isTutorRole()) {
            return $this->json(['message' => 'Acces reserve aux tuteurs.'], Response::HTTP_FORBIDDEN);
        }

        $token = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-TOKEN', ''));
        if (!$this->isCsrfTokenValid('in_app_notif', $token)) {
            return $this->json(['message' => 'CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        $notification = $inAppNotificationRepository->findOneBy([
            'id' => $id,
            'recipient' => $user,
        ]);

        if (!$notification instanceof InAppNotification) {
            return $this->json(['message' => 'Notification introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if (!$notification->isRead()) {
            $notification
                ->setIsRead(true)
                ->setReadAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        return $this->json([
            'ok' => true,
            'notification' => $this->serializeNotification($notification),
            'unreadCount' => $inAppNotificationRepository->countUnreadForRecipient($user),
        ]);
    }

    #[Route('/read-all', name: 'app_in_app_notification_read_all', methods: ['POST'])]
    public function markAllRead(
        Request $request,
        InAppNotificationRepository $inAppNotificationRepository
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifie.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isTutorRole()) {
            return $this->json(['message' => 'Acces reserve aux tuteurs.'], Response::HTTP_FORBIDDEN);
        }

        $token = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-TOKEN', ''));
        if (!$this->isCsrfTokenValid('in_app_notif', $token)) {
            return $this->json(['message' => 'CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        $updated = $inAppNotificationRepository->markAllAsReadForRecipient($user, new \DateTimeImmutable());

        return $this->json([
            'ok' => true,
            'updated' => $updated,
            'unreadCount' => 0,
        ]);
    }

    private function isTutorRole(): bool
    {
        return $this->isGranted('ROLE_TUTEUR') || $this->isGranted('ROLE_TUTOR');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNotification(InAppNotification $notification): array
    {
        $data = $notification->getData();

        return [
            'id' => $notification->getId(),
            'type' => (string) ($notification->getType() ?? ''),
            'title' => (string) ($notification->getTitle() ?? ''),
            'message' => (string) ($notification->getMessage() ?? ''),
            'isRead' => $notification->isRead(),
            'createdAt' => $notification->getCreatedAt()?->format(DATE_ATOM),
            'createdAtLabel' => $notification->getCreatedAt()?->format('d/m/Y H:i'),
            'data' => is_array($data) ? $data : [],
        ];
    }
}
