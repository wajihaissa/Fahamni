<?php

namespace App\Controller\Back;

use App\Entity\Conversation;
use App\Entity\ConversationReport;
use App\Entity\Message;
use App\Entity\MessageReport;
use App\Repository\ConversationReportRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageReportRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/notifications', name: 'admin_notifications_')]
final class AdminNotificationController extends AbstractController
{
    public function __construct(
        private readonly ConversationReportRepository $conversationReportRepository,
        private readonly MessageReportRepository $messageReportRepository,
        private readonly ConversationRepository $conversationRepository,
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Liste des notifications non lues (signalements conversations + messages) pour la cloche.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $convReports = $this->conversationReportRepository->findUnreadOrderedByCreatedAt();
        $msgReports = $this->messageReportRepository->findUnreadOrderedByCreatedAt();

        $items = [];
        foreach ($convReports as $r) {
            $c = $r->getConversation();
            $title = $c->getTitle();
            if ($title === null || $title === '') {
                $names = $c->getParticipants()->map(fn($p) => $p->getFullName())->toArray();
                $title = implode(', ', $names) ?: 'Conversation #' . $c->getId();
            }
            $items[] = [
                'type' => 'conversation',
                'id' => $r->getId(),
                'title' => 'Conversation signalée : ' . $title,
                'reason' => $r->getReason(),
                'createdAt' => $r->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'reportedBy' => $r->getReportedBy()?->getFullName(),
            ];
        }
        foreach ($msgReports as $r) {
            $m = $r->getMessage();
            $preview = $m ? mb_substr($m->getContent() ?? '', 0, 60) . (mb_strlen($m->getContent() ?? '') > 60 ? '…' : '') : '';
            $items[] = [
                'type' => 'message',
                'id' => $r->getId(),
                'title' => 'Message signalé' . ($preview ? ' : ' . $preview : ''),
                'reason' => $r->getReason(),
                'createdAt' => $r->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'reportedBy' => $r->getReportedBy()?->getFullName(),
            ];
        }

        usort($items, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));

        return $this->json([
            'count' => count($items),
            'items' => $items,
        ]);
    }

    /**
     * Détail d'un signalement de conversation (pour le modal).
     */
    #[Route('/conversation-report/{id}', name: 'conversation_report_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function conversationReportDetail(int $id): JsonResponse
    {
        $report = $this->conversationReportRepository->find($id);
        if (!$report instanceof ConversationReport) {
            return $this->json(['error' => 'Signalement introuvable.'], 404);
        }
        $conversation = $report->getConversation();
        if (!$conversation instanceof Conversation) {
            return $this->json(['error' => 'Conversation introuvable.'], 404);
        }

        $messages = $this->messageRepository->findByConversation($conversation);
        $participants = [];
        foreach ($conversation->getParticipants() as $p) {
            $participants[] = ['id' => $p->getId(), 'fullName' => $p->getFullName(), 'email' => $p->getEmail()];
        }
        $title = $conversation->getTitle();
        if ($title === null || $title === '') {
            $title = implode(', ', array_column($participants, 'fullName')) ?: 'Conversation #' . $conversation->getId();
        }
        $messagesData = array_map(function (Message $m) {
            return [
                'id' => $m->getId(),
                'content' => $m->getContent(),
                'createdAt' => $m->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'senderId' => $m->getSender()?->getId(),
                'senderName' => $m->getSender()?->getFullName(),
                'deletedAt' => $m->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $messages);

        $reportId = $report->getId();
        $csrf = $this->container->get('security.csrf.token_manager');
        return $this->json([
            'type' => 'conversation',
            'reportId' => $reportId,
            'reason' => $report->getReason(),
            'reportedBy' => $report->getReportedBy()?->getFullName(),
            'reportedAt' => $report->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'warnPreviewUrl' => $this->generateUrl('admin_messenger_conversation_report_warn_preview', ['id' => $reportId]),
            'warnUrl' => $this->generateUrl('admin_messenger_conversation_report_warn', ['id' => $reportId]),
            'warnToken' => $csrf->getToken('warn_report_' . $reportId)->getValue(),
            'conversation' => [
                'id' => $conversation->getId(),
                'title' => $title,
                'isGroup' => $conversation->isGroup(),
                'createdAt' => $conversation->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'lastMessageAt' => $conversation->getLastMessageAt()?->format(\DateTimeInterface::ATOM),
                'participants' => $participants,
                'summary' => $conversation->getSummary(),
            ],
            'messages' => $messagesData,
        ]);
    }

    /**
     * Détail d'un signalement de message (pour le modal).
     */
    #[Route('/message-report/{id}', name: 'message_report_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function messageReportDetail(int $id): JsonResponse
    {
        $report = $this->messageReportRepository->find($id);
        if (!$report instanceof MessageReport) {
            return $this->json(['error' => 'Signalement introuvable.'], 404);
        }
        $message = $report->getMessage();
        if (!$message instanceof Message) {
            return $this->json(['error' => 'Message introuvable.'], 404);
        }
        $conversation = $message->getConversation();
        $convTitle = $conversation?->getTitle();
        if ($convTitle === null || $convTitle === '') {
            $parts = $conversation?->getParticipants();
            $convTitle = $parts ? implode(', ', array_map(fn($p) => $p->getFullName(), $parts->toArray())) : ('Conversation #' . ($conversation?->getId() ?? ''));
        }

        $reportId = $report->getId();
        $csrf = $this->container->get('security.csrf.token_manager');
        $contentText = $message->getContent();
        $contentPreview = $contentText !== null && $contentText !== '' ? strip_tags($contentText) : '';
        $attachmentTypes = [];
        foreach ($message->getAttachments() as $att) {
            if ($att->isImage()) {
                $attachmentTypes[] = 'Image';
            } elseif ($att->getMimeType() === 'application/pdf') {
                $attachmentTypes[] = 'PDF';
            } elseif ($att->getMimeType() && (str_contains($att->getMimeType(), 'word') || str_contains($att->getMimeType(), 'msword'))) {
                $attachmentTypes[] = 'Document Word';
            } else {
                $attachmentTypes[] = 'Fichier';
            }
        }
        $attachmentTypes = array_unique($attachmentTypes);

        return $this->json([
            'type' => 'message',
            'reportId' => $reportId,
            'reason' => $report->getReason(),
            'reportedBy' => $report->getReportedBy()?->getFullName(),
            'reportedAt' => $report->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'warnPreviewUrl' => $this->generateUrl('admin_messenger_message_report_warn_preview', ['id' => $reportId]),
            'warnUrl' => $this->generateUrl('admin_messenger_message_report_warn', ['id' => $reportId]),
            'warnToken' => $csrf->getToken('warn_msg_report_' . $reportId)->getValue(),
            'message' => [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'contentPreview' => mb_substr($contentPreview, 0, 150) . (mb_strlen($contentPreview) > 150 ? '…' : ''),
                'attachmentTypes' => array_values($attachmentTypes),
                'createdAt' => $message->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'senderName' => $message->getSender()?->getFullName(),
                'deletedAt' => $message->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'conversation' => [
                'id' => $conversation?->getId(),
                'title' => $convTitle,
                'isGroup' => $conversation?->isGroup(),
            ],
        ]);
    }

    /**
     * Marquer un signalement de conversation comme lu (disparaît de la cloche).
     */
    #[Route('/conversation-report/{id}/read', name: 'conversation_report_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markConversationReportRead(Request $request, int $id): JsonResponse
    {
        $report = $this->conversationReportRepository->find($id);
        if (!$report instanceof ConversationReport) {
            return $this->json(['ok' => false, 'error' => 'Signalement introuvable.'], 404);
        }
        $report->setReadAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        return $this->json(['ok' => true]);
    }

    /**
     * Marquer un signalement de message comme lu (disparaît de la cloche).
     */
    #[Route('/message-report/{id}/read', name: 'message_report_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markMessageReportRead(Request $request, int $id): JsonResponse
    {
        $report = $this->messageReportRepository->find($id);
        if (!$report instanceof MessageReport) {
            return $this->json(['ok' => false, 'error' => 'Signalement introuvable.'], 404);
        }
        $report->setReadAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        return $this->json(['ok' => true]);
    }
}
