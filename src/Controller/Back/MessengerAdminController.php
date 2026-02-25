<?php

namespace App\Controller\Back;

use App\Entity\Conversation;
use App\Entity\ConversationReport;
use App\Entity\Message;
use App\Entity\MessageReport;
use App\Repository\ConversationReportRepository;
use App\Repository\MessageReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/messenger', name: 'admin_messenger_')]
//#[IsGranted('ROLE_ADMIN')]
final class MessengerAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationReportRepository $conversationReportRepository,
        private readonly MessageReportRepository $messageReportRepository,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $totalConversations = $this->entityManager->getRepository(Conversation::class)->count([]);
        $totalMessages = $this->entityManager->getRepository(Message::class)->count([]);
        $totalConversationReports = $this->entityManager->getRepository(ConversationReport::class)->count([]);
        $totalMessageReports = $this->entityManager->getRepository(MessageReport::class)->count([]);

        $reportedConversations = $this->conversationReportRepository->findAllOrderedByCreatedAt();
        $reportedMessages = $this->messageReportRepository->findAllOrderedByCreatedAt();

        return $this->render('back/messenger/index.html.twig', [
            'totalConversations' => $totalConversations,
            'totalMessages' => $totalMessages,
            'totalConversationReports' => $totalConversationReports,
            'totalMessageReports' => $totalMessageReports,
            'reportedConversations' => $reportedConversations,
            'reportedMessages' => $reportedMessages,
        ]);
    }
}
