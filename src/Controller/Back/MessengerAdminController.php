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
use App\Service\ConversationSummaryService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Nucleos\DompdfBundle\Wrapper\DompdfWrapperInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[Route('/admin/messenger', name: 'admin_messenger_')]
//#[IsGranted('ROLE_ADMIN')]
final class MessengerAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationRepository $conversationRepository,
        private readonly ConversationReportRepository $conversationReportRepository,
        private readonly MessageReportRepository $messageReportRepository,
        private readonly MessageRepository $messageRepository,
        private readonly ConversationSummaryService $conversationSummaryService,
        private readonly MailerInterface $mailer,
        private readonly DompdfWrapperInterface $dompdf,
    ) {
    }

    /**
     * Statistics : vue dâ€™ensemble (cartes uniquement).
     */
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(ChartBuilderInterface $chartBuilder): Response
    {
        $totalConversations = $this->entityManager->getRepository(Conversation::class)->count([]);
        $totalMessages = $this->entityManager->getRepository(Message::class)->count([]);
        $totalConversationReports = $this->entityManager->getRepository(ConversationReport::class)->count([]);
        $totalMessageReports = $this->entityManager->getRepository(MessageReport::class)->count([]);

        $labels = $this->buildDateLabels(self::STATS_DAYS);
        $messagesByDay = $this->messageRepository->countByDay(self::STATS_DAYS);
        $conversationsByDay = $this->conversationRepository->countByDay(self::STATS_DAYS);
        $convReportsByDay = $this->conversationReportRepository->countByDay(self::STATS_DAYS);
        $msgReportsByDay = $this->messageReportRepository->countByDay(self::STATS_DAYS);
        $groupVsPrivate = $this->conversationRepository->countGroupVsPrivate();

        $chartMessages = $chartBuilder->createChart(Chart::TYPE_LINE);
        $chartMessages->setData([
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Messages',
                'backgroundColor' => 'rgba(79, 172, 254, 0.2)',
                'borderColor' => 'rgb(79, 172, 254)',
                'borderWidth' => 2,
                'fill' => true,
                'tension' => 0.35,
                'data' => $this->fillDataForLabels($labels, $messagesByDay),
            ]],
        ]);
        $chartMessages->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'x' => ['grid' => ['display' => false]],
            ],
        ]);

        $chartConversations = $chartBuilder->createChart(Chart::TYPE_BAR);
        $chartConversations->setData([
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Conversations',
                'backgroundColor' => 'rgba(102, 126, 234, 0.85)',
                'borderColor' => 'rgb(102, 126, 234)',
                'borderWidth' => 1,
                'data' => $this->fillDataForLabels($labels, $conversationsByDay),
            ]],
        ]);
        $chartConversations->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'x' => ['grid' => ['display' => false]],
            ],
        ]);

        $chartReports = $chartBuilder->createChart(Chart::TYPE_LINE);
        $chartReports->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Conv. signalées',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.2)',
                    'borderColor' => 'rgb(245, 158, 11)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.35,
                    'data' => $this->fillDataForLabels($labels, $convReportsByDay),
                ],
                [
                    'label' => 'Messages signalÃ©s',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                    'borderColor' => 'rgb(239, 68, 68)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.35,
                    'data' => $this->fillDataForLabels($labels, $msgReportsByDay),
                ],
            ],
        ]);
        $chartReports->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'x' => ['grid' => ['display' => false]],
            ],
        ]);

        $chartTypes = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chartTypes->setData([
            'labels' => ['PrivÃ©es', 'Groupes'],
            'datasets' => [[
                'data' => [$groupVsPrivate['private'], $groupVsPrivate['group']],
                'backgroundColor' => ['rgb(102, 126, 234)', 'rgb(118, 75, 162)'],
                'borderWidth' => 2,
                'borderColor' => '#fff',
            ]],
        ]);
        $chartTypes->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['position' => 'bottom']],
        ]);

        return $this->render('back/messenger/index.html.twig', [
            'totalConversations' => $totalConversations,
            'totalMessages' => $totalMessages,
            'totalConversationReports' => $totalConversationReports,
            'totalMessageReports' => $totalMessageReports,
            'chartMessages' => $chartMessages,
            'chartConversations' => $chartConversations,
            'chartReports' => $chartReports,
            'chartTypes' => $chartTypes,
        ]);
    }

    /**
     * Export des statistiques messagerie en PDF.
     */
    #[Route('/export/stats.pdf', name: 'export_stats_pdf', methods: ['GET'])]
    public function exportStatsPdf(): StreamedResponse
    {
        $totalConversations = $this->entityManager->getRepository(Conversation::class)->count([]);
        $totalMessages = $this->entityManager->getRepository(Message::class)->count([]);
        $totalConversationReports = $this->entityManager->getRepository(ConversationReport::class)->count([]);
        $totalMessageReports = $this->entityManager->getRepository(MessageReport::class)->count([]);
        $labels = $this->buildDateLabels(self::STATS_DAYS);
        $messagesByDay = $this->messageRepository->countByDay(self::STATS_DAYS);
        $conversationsByDay = $this->conversationRepository->countByDay(self::STATS_DAYS);
        $convReportsByDay = $this->conversationReportRepository->countByDay(self::STATS_DAYS);
        $msgReportsByDay = $this->messageReportRepository->countByDay(self::STATS_DAYS);
        $groupVsPrivate = $this->conversationRepository->countGroupVsPrivate();

        $html = $this->renderView('back/messenger/pdf_stats.html.twig', [
            'totalConversations' => $totalConversations,
            'totalMessages' => $totalMessages,
            'totalConversationReports' => $totalConversationReports,
            'totalMessageReports' => $totalMessageReports,
            'labels' => $labels,
            'messagesByDay' => $this->fillDataForLabels($labels, $messagesByDay),
            'conversationsByDay' => $this->fillDataForLabels($labels, $conversationsByDay),
            'convReportsByDay' => $this->fillDataForLabels($labels, $convReportsByDay),
            'msgReportsByDay' => $this->fillDataForLabels($labels, $msgReportsByDay),
            'groupVsPrivate' => $groupVsPrivate,
            'generatedAt' => new \DateTimeImmutable(),
        ]);
        $filename = 'statistiques-messagerie-' . date('Y-m-d') . '.pdf';
        $response = $this->dompdf->getStreamResponse($html, $filename);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    private const STATS_DAYS = 30;

    /** @return list<string> */
    private function buildDateLabels(int $days): array
    {
        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = (new \DateTimeImmutable())->modify("-{$i} days")->format('d/m');
        }
        return $labels;
    }

    /**
     * @param list<string> $labels
     * @param array<string, int> $byDay
     * @return list<int>
     */
    private function fillDataForLabels(array $labels, array $byDay): array
    {
        $data = [];
        for ($i = count($labels) - 1; $i >= 0; $i--) {
            $date = (new \DateTimeImmutable())->modify("-{$i} days")->format('Y-m-d');
            $data[] = $byDay[$date] ?? 0;
        }
        return $data;
    }

    /**
     * Liste des conversations avec bouton de visualisation (pagination KnpPaginator).
     */
    #[Route('/conversations', name: 'conversations', methods: ['GET'])]
    public function conversations(Request $request, PaginatorInterface $paginator): Response
    {
        $query = $this->conversationRepository->getQueryOrderedByLastMessage();
        $pagination = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            15
        );
        return $this->render('back/messenger/conversations.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    /**
     * Export de la liste des conversations en PDF.
     */
    #[Route('/export/conversations.pdf', name: 'export_conversations_pdf', methods: ['GET'])]
    public function exportConversationsPdf(): StreamedResponse
    {
        $conversations = $this->conversationRepository->getQueryOrderedByLastMessage()
            ->setMaxResults(500)
            ->getResult();
        $html = $this->renderView('back/messenger/pdf_conversations.html.twig', [
            'conversations' => $conversations,
            'generatedAt' => new \DateTimeImmutable(),
        ]);
        $filename = 'liste-conversations-' . date('Y-m-d') . '.pdf';
        $response = $this->dompdf->getStreamResponse($html, $filename);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    /**
     * Contenu dâ€™une conversation (JSON) pour le modal admin.
     */
    #[Route('/conversation/{id}/content', name: 'conversation_content', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function conversationContent(int $id): JsonResponse
    {
        $conversation = $this->conversationRepository->find($id);
        if (!$conversation instanceof Conversation) {
            return $this->json(['error' => 'Conversation introuvable'], 404);
        }
        $messages = $this->messageRepository->findByConversation($conversation);
        $participants = [];
        foreach ($conversation->getParticipants() as $p) {
            $participants[] = ['id' => $p->getId(), 'fullName' => $p->getFullName(), 'email' => $p->getEmail()];
        }
        $title = $conversation->getTitle();
        if ($title === null || $title === '') {
            $names = array_map(fn($p) => $p['fullName'], $participants);
            $title = implode(', ', $names) ?: 'Conversation #' . $conversation->getId();
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
        return $this->json([
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
     * RÃ©sumÃ© de la conversation (admin).
     * GET : retourne le rÃ©sumÃ© en cache (ok, summary, cached).
     * POST : gÃ©nÃ¨re le rÃ©sumÃ© via Gemini et le sauvegarde.
     */
    #[Route('/conversation/{id}/summary', name: 'conversation_summary', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function conversationSummary(Request $request, int $id): JsonResponse
    {
        $conversation = $this->conversationRepository->find($id);
        if (!$conversation instanceof Conversation) {
            return $this->json(['ok' => false, 'error' => 'Conversation introuvable.'], 404);
        }

        if ($request->isMethod('GET')) {
            $summary = $conversation->getSummary();
            return $this->json([
                'ok' => true,
                'summary' => $summary,
                'cached' => $summary !== null && $summary !== '',
            ]);
        }

        try {
            $summary = $this->conversationSummaryService->generateSummary($conversation);
            $conversation->setSummary($summary);
            $conversation->setUpdetedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            return $this->json(['ok' => true, 'summary' => $summary, 'cached' => false]);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            $isQuota = ($code === 429 || str_contains($e->getMessage(), 'quota') || str_contains($e->getMessage(), '429'));
            $errorMessage = $isQuota
                ? 'Quota API Gemini atteint. RÃ©essayez dans 1 Ã  2 minutes.'
                : 'Impossible de gÃ©nÃ©rer le rÃ©sumÃ©. RÃ©essayez plus tard.';
            return $this->json([
                'ok' => false,
                'error' => $errorMessage,
            ], $code >= 400 && $code < 600 ? (int) $code : 500);
        }
    }

    /**
     * Alertes : conversations et messages signalÃ©s.
     */
    #[Route('/alerts', name: 'alerts', methods: ['GET'])]
    public function alerts(): Response
    {
        $reportedConversations = $this->conversationReportRepository->findAllOrderedByCreatedAt();
        $reportedMessages = $this->messageReportRepository->findAllOrderedByCreatedAt();
        return $this->render('back/messenger/alerts.html.twig', [
            'reportedConversations' => $reportedConversations,
            'reportedMessages' => $reportedMessages,
        ]);
    }

    /**
     * Supprimer un signal (conversation report) aprÃ¨s confirmation.
     */
    #[Route('/report/{id}/remove', name: 'conversation_report_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeConversationReport(Request $request, int $id): JsonResponse|Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('remove_report_' . $id, $token)) {
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['ok' => false, 'error' => 'Token invalide.'], 403);
            }
            $this->addFlash('error', 'Token de sÃ©curitÃ© invalide.');
            return $this->redirectToRoute('admin_messenger_alerts');
        }

        $report = $this->conversationReportRepository->find($id);
        if (!$report) {
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['ok' => false, 'error' => 'Signal introuvable.'], 404);
            }
            $this->addFlash('error', 'Signal introuvable.');
            return $this->redirectToRoute('admin_messenger_alerts');
        }

        $this->entityManager->remove($report);
        $this->entityManager->flush();

        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json(['ok' => true, 'message' => 'Signal supprimÃ©.']);
        }
        $this->addFlash('success', 'Le signal a Ã©tÃ© supprimÃ©.');
        return $this->redirectToRoute('admin_messenger_alerts');
    }

    /**
     * Token CSRF pour le formulaire d'envoi d'avertissement (modal partagé).
     */
    #[Route('/report/{id}/warn-token', name: 'conversation_report_warn_token', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function warnToken(int $id): JsonResponse
    {
        return $this->json(['token' => $this->container->get('security.csrf.token_manager')->getToken('warn_report_' . $id)->getValue()]);
    }

    /**
     * Aperçu du contenu de l'email d'avertissement (pour le modal).
     */
    #[Route('/report/{id}/warn-preview', name: 'conversation_report_warn_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function warnPreview(int $id): JsonResponse
    {
        $report = $this->conversationReportRepository->find($id);
        if (!$report instanceof ConversationReport) {
            return $this->json(['error' => 'Signal introuvable.'], 404);
        }
        $conversation = $report->getConversation();
        if (!$conversation instanceof Conversation) {
            return $this->json(['error' => 'Conversation introuvable.'], 404);
        }

        $conversationTitle = $conversation->getTitle() ?: ('Conversation #' . $conversation->getId());
        $reportReason = $report->getReason() ?? 'â€”';
        $reportedBy = $report->getReportedBy();
        $reportedById = $reportedBy?->getId();
        $recipients = [];
        foreach ($conversation->getParticipants() as $participant) {
            if ($participant instanceof User && $participant->getId() !== $reportedById) {
                $recipients[] = [
                    'email' => $participant->getEmail(),
                    'fullName' => $participant->getFullName() ?? $participant->getEmail(),
                ];
            }
        }

        $bodyHtml = $this->renderView('emails/conversation_warning.html.twig', [
            'recipientName' => 'Destinataire',
            'conversationTitle' => $conversationTitle,
            'reportReason' => $reportReason,
        ]);
        $subject = '[Fahimni] Avertissement - Conversation signalée : ' . $conversationTitle;

        return $this->json([
            'subject' => $subject,
            'bodyHtml' => $bodyHtml,
            'recipients' => $recipients,
        ]);
    }

    /**
     * Envoi de l'avertissement par email aux participants de la conversation.
     */
    #[Route('/report/{id}/warn', name: 'conversation_report_warn', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendWarning(Request $request, int $id): JsonResponse|Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('warn_report_' . $id, $token)) {
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['ok' => false, 'error' => 'Token invalide.'], 403);
            }
            $this->addFlash('error', 'Token de sÃ©curitÃ© invalide.');
            return $this->redirectToRoute('admin_messenger_alerts');
        }

        $report = $this->conversationReportRepository->find($id);
        if (!$report instanceof ConversationReport) {
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['ok' => false, 'error' => 'Signal introuvable.'], 404);
            }
            $this->addFlash('error', 'Signal introuvable.');
            return $this->redirectToRoute('admin_messenger_alerts');
        }

        $conversation = $report->getConversation();
        if (!$conversation instanceof Conversation) {
            return $this->json(['ok' => false, 'error' => 'Conversation introuvable.'], 404);
        }

        $conversationTitle = $conversation->getTitle() ?: ('Conversation #' . $conversation->getId());
        $reportReason = $report->getReason() ?? '';
        $reportedById = $report->getReportedBy()?->getId();

        $fromAddress = $this->getParameter('mailer_from_address');
        $fromName = $this->getParameter('mailer_from_name');

        $sent = 0;
        foreach ($conversation->getParticipants() as $participant) {
            if (!$participant instanceof User || $participant->getId() === $reportedById) {
                continue;
            }
            $emailAddress = $participant->getEmail();
            if ($emailAddress === null || $emailAddress === '') {
                continue;
            }
            $recipientName = $participant->getFullName() ?? $emailAddress;
            $bodyHtml = $this->renderView('emails/conversation_warning.html.twig', [
                'recipientName' => $recipientName,
                'conversationTitle' => $conversationTitle,
                'reportReason' => $reportReason,
            ]);
            $email = (new Email())
                ->from(Address::create($fromAddress, $fromName ?? ''))
                ->to($emailAddress)
                ->subject('[Fahimni] Avertissement - Conversation signalée : ' . $conversationTitle)
                ->html($bodyHtml);
            $this->mailer->send($email);
            $sent++;
        }

        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json(['ok' => true, 'message' => 'Avertissement envoyé par email à ' . $sent . ' participant(s).']);
        }
        $this->addFlash('success', 'Avertissement envoyé par email à  ' . $sent . ' participant(s).');
        return $this->redirectToRoute('admin_messenger_alerts');
    }

    /**
     * Aperçu de l'email d'avertissement pour un message signalé (destinataire = auteur du message).
     */
    #[Route('/message-report/{id}/warn-preview', name: 'message_report_warn_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function messageReportWarnPreview(int $id): JsonResponse
    {
        $report = $this->messageReportRepository->find($id);
        if (!$report instanceof MessageReport) {
            return $this->json(['error' => 'Signal introuvable.'], 404);
        }
        $message = $report->getMessage();
        $sender = $message?->getSender();
        if (!$sender instanceof User) {
            return $this->json(['error' => 'Auteur du message introuvable.'], 404);
        }

        $reportReason = $report->getReason() ?? '—';
        $recipients = [[
            'email' => $sender->getEmail(),
            'fullName' => $sender->getFullName() ?? $sender->getEmail(),
        ]];

        $bodyHtml = $this->renderView('emails/message_warning.html.twig', [
            'recipientName' => 'Destinataire',
            'reportReason' => $reportReason,
        ]);
        $subject = '[Fahimni] Avertissement - Message signalé';

        return $this->json([
            'subject' => $subject,
            'bodyHtml' => $bodyHtml,
            'recipients' => $recipients,
        ]);
    }

    /**
     * Envoi de l'avertissement par email à l'auteur du message signalé.
     */
    #[Route('/message-report/{id}/warn', name: 'message_report_warn', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function messageReportWarn(Request $request, int $id): JsonResponse|Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('warn_msg_report_' . $id, $token)) {
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['ok' => false, 'error' => 'Token invalide.'], 403);
            }
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('admin_messenger_alerts');
        }

        $report = $this->messageReportRepository->find($id);
        if (!$report instanceof MessageReport) {
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['ok' => false, 'error' => 'Signal introuvable.'], 404);
            }
            $this->addFlash('error', 'Signal introuvable.');
            return $this->redirectToRoute('admin_messenger_alerts');
        }

        $message = $report->getMessage();
        $sender = $message?->getSender();
        if (!$sender instanceof User) {
            return $this->json(['ok' => false, 'error' => 'Auteur du message introuvable.'], 404);
        }

        $emailAddress = $sender->getEmail();
        if ($emailAddress === null || $emailAddress === '') {
            return $this->json(['ok' => false, 'error' => 'L\'auteur du message n\'a pas d\'email.'], 400);
        }

        $reportReason = $report->getReason() ?? '';
        $recipientName = $sender->getFullName() ?? $emailAddress;
        $bodyHtml = $this->renderView('emails/message_warning.html.twig', [
            'recipientName' => $recipientName,
            'reportReason' => $reportReason,
        ]);

        $fromAddress = $this->getParameter('mailer_from_address');
        $fromName = $this->getParameter('mailer_from_name');
        $email = (new Email())
            ->from(Address::create($fromAddress, $fromName ?? ''))
            ->to($emailAddress)
            ->subject('[Fahimni] Avertissement - Message signalé')
            ->html($bodyHtml);
        $this->mailer->send($email);

        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json(['ok' => true, 'message' => 'Avertissement envoyé par email à l\'auteur du message.']);
        }
        $this->addFlash('success', 'Avertissement envoyé par email à l\'auteur du message.');
        return $this->redirectToRoute('admin_messenger_alerts');
    }

    /**
     * Supprimer un signalement de message après confirmation.
     */
    #[Route('/message-report/{id}/remove', name: 'message_report_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeMessageReport(Request $request, int $id): JsonResponse|Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('remove_msg_report_' . $id, $token)) {
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['ok' => false, 'error' => 'Token invalide.'], 403);
            }
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('admin_messenger_alerts');
        }

        $report = $this->messageReportRepository->find($id);
        if (!$report instanceof MessageReport) {
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['ok' => false, 'error' => 'Signal introuvable.'], 404);
            }
            $this->addFlash('error', 'Signal introuvable.');
            return $this->redirectToRoute('admin_messenger_alerts');
        }

        $this->entityManager->remove($report);
        $this->entityManager->flush();

        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json(['ok' => true, 'message' => 'Signal supprimé.']);
        }
        $this->addFlash('success', 'Le signal a été supprimé.');
        return $this->redirectToRoute('admin_messenger_alerts');
    }
}
