<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\ConversationReport;
use App\Entity\Message;
use App\Entity\MessageReport;
use App\Entity\MessageAttachment;
use App\Entity\MessageReaction;
use App\Entity\User;
use App\Repository\ConversationReportRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageAttachmentRepository;
use App\Repository\MessageReportRepository;
use App\Repository\MessageReactionRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ConversationSummaryService;
use App\Service\MessengerActorResolver;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/messenger')]
#[IsGranted('ROLE_USER')]
class MessengerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessengerActorResolver $actorResolver,
        private readonly ConversationRepository $conversationRepository,
        private readonly ConversationReportRepository $conversationReportRepository,
        private readonly MessageReportRepository $messageReportRepository,
        private readonly MessageRepository $messageRepository,
        private readonly MessageReactionRepository $reactionRepository,
        private readonly MessageAttachmentRepository $messageAttachmentRepository,
        private readonly UserRepository $userRepository,
        private readonly ConversationSummaryService $conversationSummaryService,
    ) {
    }

    private function getActor(): ?User
    {
        return $this->actorResolver->getActor();
    }

    /**
     * Contenu HTML de la Chat Sidebar (liste des conversations) pour l’icône navbar.
     */
    #[Route('/sidebar-content', name: 'app_messenger_sidebar_content', methods: ['GET'])]
    public function sidebarContent(): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        $conversations = $this->conversationRepository->findByParticipant($actor, false, false, null, false);
        return $this->render('front/messenger/_chat_sidebar_content.html.twig', [
            'actor' => $actor,
            'conversations' => $conversations,
        ]);
    }

    /**
     * Contenu HTML de la liste des conversations (colonne gauche page Messenger) pour mise à jour AJAX.
     * GET ?archived=1 pour les archivées, ?current=ID pour marquer la conversation active.
     */
    #[Route('/threads-list-content', name: 'app_messenger_threads_list_content', methods: ['GET'])]
    public function threadsListContent(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        $showArchived = $request->query->getBoolean('archived');
        $currentId = $request->query->getInt('current', 0);
        $conversations = $this->conversationRepository->findByParticipant($actor, false, false, null, $showArchived);
        $currentConversation = null;
        if ($currentId > 0) {
            foreach ($conversations as $c) {
                if ($c->getId() === $currentId) {
                    $currentConversation = $c;
                    break;
                }
            }
        }
        return $this->render('front/messenger/_threads_list_content.html.twig', [
            'conversations' => $conversations,
            'actor' => $actor,
            'current_conversation' => $currentConversation,
        ]);
    }

    /**
     * Liste des conversations signalées par l'utilisateur connecté (tous les utilisateurs).
     */
    #[Route('/reported', name: 'app_messenger_reported_conversations', methods: ['GET'])]
    public function reportedConversations(): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_login');
        }
        $reports = $this->conversationReportRepository->findByReportedBy($actor);
        return $this->render('front/messenger/reported_conversations.html.twig', [
            'actor' => $actor,
            'reports' => $reports,
        ]);
    }

    /**
     * Signaler une conversation. POST attend un champ "reason" (optionnel). Retourne JSON en AJAX.
     */
    /**
     * Résumé de la conversation.
     * GET : retourne le résumé en cache s'il existe (ok, summary, cached: true/false).
     * POST : génère un nouveau résumé via Gemini, le sauvegarde et le retourne.
     */
    #[Route('/{id}/summary', name: 'app_messenger_summary', requirements: ['id' => '\d+'], methods: ['POST', 'GET'])]
    public function conversationSummary(Request $request, int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->json(['ok' => false, 'error' => 'Non autorisé'], 401);
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
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
            $this->em->flush();
            return $this->json(['ok' => true, 'summary' => $summary, 'cached' => false]);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            $isQuota = ($code === 429 || str_contains($e->getMessage(), 'quota') || str_contains($e->getMessage(), '429'));
            $errorMessage = $isQuota
                ? 'Quota d\'utilisation de l\'API Gemini atteint. Réessayez dans 1 à 2 minutes, ou consultez votre plan et facturation sur Google AI Studio.'
                : 'Impossible de générer le résumé. Réessayez plus tard.';
            return $this->json([
                'ok' => false,
                'error' => $errorMessage,
                'debug' => $e->getMessage(),
            ], $code >= 400 && $code < 600 ? (int) $code : 500);
        }
    }

    #[Route('/{id}/report', name: 'app_messenger_report_conversation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reportConversation(Request $request, int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['ok' => false, 'error' => 'Non autorisé'], 401);
            }
            return $this->redirectToRoute('app_login');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['ok' => false, 'error' => 'Conversation introuvable'], 404);
            }
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $existing = $this->conversationReportRepository->findOneByConversationAndReportedBy($conversation, $actor);
        if ($existing) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['ok' => false, 'error' => 'Vous avez déjà signalé cette conversation.']);
            }
            $this->addFlash('warning', 'Vous avez déjà signalé cette conversation.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
        }
        $reason = trim((string) ($request->request->get('reason') ?? ''));
        $report = new ConversationReport();
        $report->setConversation($conversation);
        $report->setReportedBy($actor);
        $report->setReason($reason !== '' ? $reason : null);
        $report->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($report);
        $this->em->flush();
        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => true, 'message' => 'Conversation signalée. Merci pour votre retour.']);
        }
        $this->addFlash('success', 'Conversation signalée. Merci pour votre retour.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
    }

    /**
     * Signaler un message. POST attend un champ "reason" (optionnel). Retourne JSON en AJAX.
     */
    #[Route('/{convId}/message/{messageId}/report', name: 'app_messenger_report_message', requirements: ['convId' => '\d+', 'messageId' => '\d+'], methods: ['POST'])]
    public function reportMessage(Request $request, int $convId, int $messageId): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['ok' => false, 'error' => 'Non autorisé'], 401);
            }
            return $this->redirectToRoute('app_login');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($convId, $actor);
        if (!$conversation) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['ok' => false, 'error' => 'Conversation introuvable'], 404);
            }
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $message = $this->messageRepository->findOneByConversationAndId($conversation, $messageId);
        if (!$message) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['ok' => false, 'error' => 'Message introuvable'], 404);
            }
            $this->addFlash('error', 'Message introuvable.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        $existing = $this->messageReportRepository->findOneByMessageAndReportedBy($message, $actor);
        if ($existing) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['ok' => false, 'error' => 'Vous avez déjà signalé ce message.']);
            }
            $this->addFlash('warning', 'Vous avez déjà signalé ce message.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        $token = (string) ($request->request->get('_token') ?? '');
        if (!$this->isCsrfTokenValid('report_msg_conv_' . $convId, $token)) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['ok' => false, 'error' => 'Jeton de sécurité invalide.'], 400);
            }
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        $reason = trim((string) ($request->request->get('reason') ?? ''));
        $report = new MessageReport();
        $report->setMessage($message);
        $report->setReportedBy($actor);
        $report->setReason($reason !== '' ? $reason : null);
        $report->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($report);
        $this->em->flush();
        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => true, 'message' => 'Message signalé. Merci pour votre retour.']);
        }
        $this->addFlash('success', 'Message signalé. Merci pour votre retour.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
    }

    #[Route('', name: 'app_messenger_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_login');
        }
        $showArchived = $request->query->getBoolean('archived');
        $conversations = $this->conversationRepository->findByParticipant($actor, false, false, null, $showArchived);
        return $this->render('front/messenger/messages.html.twig', [
            'actor' => $actor,
            'conversations' => $conversations,
            'current_conversation' => null,
            'messages' => [],
            'show_archived' => $showArchived,
        ]);
    }

    /**
     * Recherche par utilisateur : si une conversation existe avec lui, elle s'affiche ;
     * sinon on propose de démarrer une nouvelle conversation (lien qui crée la conv et redirige).
     */
    #[Route('/search-users', name: 'app_messenger_search_users', methods: ['GET'])]
    public function searchUsers(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->json(['results' => []], 401);
        }
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return $this->json(['results' => []]);
        }
        $term = '%' . addcslashes($q, '%_') . '%';
        $users = $this->userRepository->createQueryBuilder('u')
            ->where('u.id != :current')
            ->andWhere('u.FullName LIKE :term OR u.email LIKE :term')
            ->setParameter('current', $actor->getId())
            ->setParameter('term', $term)
            ->orderBy('u.FullName', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        $results = [];
        foreach ($users as $other) {
            if (!$other instanceof User) {
                continue;
            }
            $conversation = $this->conversationRepository->findPrivateConversationBetween($actor, $other);
            $results[] = [
                'userId' => $other->getId(),
                'fullName' => $other->getFullName(),
                'email' => $other->getEmail(),
                'conversationId' => $conversation ? $conversation->getId() : null,
                'showUrl' => $conversation ? $this->generateUrl('app_messenger_show', ['id' => $conversation->getId()]) : null,
                'newConversationUrl' => $this->generateUrl('app_messenger_compose', ['with' => $other->getId()]),
            ];
        }
        return $this->json(['results' => $results]);
    }

    #[Route('/search-conversations', name: 'app_messenger_search_conversations', methods: ['GET'])]
    public function searchConversations(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->json(['results' => []], 401);
        }
        $q = $request->query->get('q', '');
        $archivedOnly = $request->query->getBoolean('archived');
        $conversations = $this->conversationRepository->findByParticipant(
            $actor,
            $archivedOnly,
            false,
            $q !== '' ? $q : null,
            $archivedOnly
        );
        $results = [];
        foreach ($conversations as $c) {
            $other = null;
            foreach ($c->getParticipants() as $p) {
                if ($p->getId() !== $actor->getId()) {
                    $other = $p;
                    break;
                }
            }
            $lastMsg = null;
            foreach ($c->getMessages() as $m) {
                if ($m->getDeletedAt() !== null) {
                    continue;
                }
                if ($lastMsg === null || $m->getCreatedAt() > $lastMsg->getCreatedAt()) {
                    $lastMsg = $m;
                }
            }
            $preview = $lastMsg ? (mb_strlen($lastMsg->getContent()) > 55 ? mb_substr($lastMsg->getContent(), 0, 55) . '…' : $lastMsg->getContent()) : 'Aucun message';
            $results[] = [
                'id' => $c->getId(),
                'title' => $c->getTitle() ?? ($other ? $other->getFullName() : 'Conversation'),
                'otherName' => $other ? $other->getFullName() : '',
                'otherEmail' => $other ? $other->getEmail() : '',
                'lastMessageAt' => $c->getLastMessageAt() ? $c->getLastMessageAt()->format('H:i') : '-',
                'preview' => $preview,
                'isArchived' => $c->isArchived(),
                'showUrl' => $this->generateUrl('app_messenger_show', ['id' => $c->getId()]),
            ];
        }
        return $this->json(['results' => $results]);
    }

    #[Route('/{id}/float-content', name: 'app_messenger_float_content', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function floatContent(int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->json(['error' => 'Non autorisé'], 401);
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            return $this->json(['error' => 'Conversation introuvable'], 404);
        }
        $messages = $this->messageRepository->findByConversation($conversation);
        $other = null;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getId() !== $actor->getId()) {
                $other = $p;
                break;
            }
        }
        $otherDisplayName = $other ? $other->getFullName() : 'Conversation';
        $currentNickname = null;
        if ($other) {
            $currentNickname = $actor->getConversationNickname($conversation->getId(), $other->getId());
            if ($currentNickname !== null) {
                $otherDisplayName = $currentNickname;
            }
        }
        return $this->render('front/messenger/_float_conversation.html.twig', [
            'actor' => $actor,
            'current_conversation' => $conversation,
            'messages' => $messages,
            'other' => $other,
            'other_display_name' => $otherDisplayName,
            'current_nickname' => $currentNickname,
        ]);
    }

    #[Route('/{id}', name: 'app_messenger_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $messages = $this->messageRepository->findByConversation($conversation);
        $showArchived = $conversation->isArchived();
        $conversations = $this->conversationRepository->findByParticipant($actor, false, false, null, $showArchived);
        $other = null;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getId() !== $actor->getId()) {
                $other = $p;
                break;
            }
        }
        $otherDisplayName = $other ? $other->getFullName() : 'Conversation';
        $currentNickname = null;
        if ($other) {
            $currentNickname = $actor->getConversationNickname($conversation->getId(), $other->getId());
            if ($currentNickname !== null) {
                $otherDisplayName = $currentNickname;
            }
        }
        $conversationAlreadyReported = $this->conversationReportRepository->findOneByConversationAndReportedBy($conversation, $actor) !== null;
        $messageReportedIds = $this->messageReportRepository->findReportedMessageIdsByUserInConversation($actor, $conversation);
        $attachmentInlineSrcs = $this->buildInlineImageDataUrls($messages, $this->getParameter('kernel.project_dir'));
        return $this->render('front/messenger/messages.html.twig', [
            'actor' => $actor,
            'conversations' => $conversations,
            'current_conversation' => $conversation,
            'messages' => $messages,
            'other' => $other,
            'other_display_name' => $otherDisplayName,
            'current_nickname' => $currentNickname,
            'show_archived' => $showArchived,
            'conversation_already_reported' => $conversationAlreadyReported,
            'message_reported_ids' => $messageReportedIds,
            'attachment_inline_srcs' => $attachmentInlineSrcs,
        ]);
    }

    #[Route('/{id}/nickname', name: 'app_messenger_set_nickname', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setNickname(Request $request, int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->json(['ok' => false, 'error' => 'Non autorisé'], 401);
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            return $this->json(['ok' => false, 'error' => 'Conversation introuvable'], 404);
        }
        $targetId = (int) ($request->request->get('target_user_id') ?? 0);
        $other = null;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getId() === $targetId && $p->getId() !== $actor->getId()) {
                $other = $p;
                break;
            }
        }
        if (!$other) {
            return $this->json(['ok' => false, 'error' => 'Contact invalide'], 400);
        }
        $nickname = trim((string) ($request->request->get('nickname') ?? ''));
        $actor->setConversationNickname($conversation->getId(), $other->getId(), $nickname === '' ? null : $nickname);
        $this->em->flush();
        $displayName = $nickname === '' ? $other->getFullName() : $nickname;
        return $this->json(['ok' => true, 'display_name' => $displayName]);
    }

    #[Route('/{id}/search', name: 'app_messenger_search_messages', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function searchMessages(int $id, Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->json(['results' => []], 401);
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            return $this->json(['results' => []], 404);
        }
        $q = $request->query->get('q', '');
        $messages = $this->messageRepository->searchInConversation($conversation, $q, 30);
        $results = array_map(function (Message $m) use ($actor) {
            return [
                'id' => $m->getId(),
                'content' => $m->getContent(),
                'createdAt' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'senderName' => $m->getSender()->getFullName(),
                'isMine' => $m->getSender()->getId() === $actor->getId(),
            ];
        }, $messages);
        return $this->json(['results' => $results]);
    }

    /**
     * Vue « brouillon » : affiche l’interface pour envoyer le premier message à un utilisateur.
     * La conversation n’est créée qu’à l’envoi du premier message.
     */
    #[Route('/compose', name: 'app_messenger_compose', methods: ['GET'])]
    public function compose(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $withId = $request->query->get('with');
        if ($withId === null || $withId === '') {
            $this->addFlash('warning', 'Indiquez le destinataire.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $other = $this->userRepository->find((int) $withId);
        if (!$other instanceof User || $other->getId() === $actor->getId()) {
            $this->addFlash('error', 'Utilisateur invalide.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $existingConversation = $this->conversationRepository->findPrivateConversationBetween($actor, $other);
        if ($existingConversation !== null) {
            return $this->redirectToRoute('app_messenger_show', ['id' => $existingConversation->getId()]);
        }
        $showArchived = $request->query->getBoolean('archived');
        $conversations = $this->conversationRepository->findByParticipant($actor, false, false, null, $showArchived);
        return $this->render('front/messenger/messages.html.twig', [
            'actor' => $actor,
            'conversations' => $conversations,
            'current_conversation' => null,
            'compose_with' => $other,
            'messages' => [],
            'show_archived' => $showArchived,
        ]);
    }

    /**
     * Envoi du premier message : crée la conversation puis enregistre le message et redirige vers la conversation.
     */
    #[Route('/send-first-message', name: 'app_messenger_send_first_message', methods: ['POST'])]
    public function sendFirstMessage(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $withId = (int) ($request->request->get('with') ?? 0);
        $other = $withId > 0 ? $this->userRepository->find($withId) : null;
        if (!$other instanceof User || $other->getId() === $actor->getId()) {
            $this->addFlash('error', 'Destinataire invalide.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $content = trim((string) ($request->request->get('content') ?? ''));
        $uploadedFiles = $this->getUploadedAttachments($request);
        if ($content === '' && $uploadedFiles === []) {
            $this->addFlash('warning', 'Le message ne peut pas être vide.');
            return $this->redirectToRoute('app_messenger_compose', ['with' => $other->getId()]);
        }
        if ($content === '') {
            $content = ' '; // pièces jointes seules
        }
        $conversation = $this->conversationRepository->findPrivateConversationBetween($actor, $other);
        if (!$conversation) {
            $conversation = new Conversation();
            $conversation->setIsGroup(false);
            $conversation->setCreatedAt(new \DateTimeImmutable());
            $conversation->setIsDeleted(false);
            $conversation->setCreatedBy($actor);
            $conversation->addParticipant($actor);
            $conversation->addParticipant($other);
            $this->em->persist($conversation);
        }
        $message = new Message();
        $message->setContent($content);
        $message->setSender($actor);
        $message->setConversation($conversation);
        $message->setCreatedAt(new \DateTimeImmutable());
        $message->setIsRead(false);
        $conversation->addMessage($message);
        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $conversation->setUpdetedAt(new \DateTimeImmutable());
        $this->em->persist($message);
        $uploadError = $this->processAttachments($message, $uploadedFiles);
        if ($uploadError !== null) {
            $this->addFlash('warning', $uploadError);
            return $this->redirectToRoute('app_messenger_compose', ['with' => $other->getId()]);
        }
        $this->em->flush();
        $this->addFlash('success', 'Message envoyé.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $conversation->getId()]);
    }

    #[Route('/new', name: 'app_messenger_new_conversation', methods: ['GET', 'POST'])]
    public function newConversation(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $withId = $request->request->get('with') ?? $request->query->get('with');
        if ($withId === null || $withId === '') {
            $users = $this->userRepository->createQueryBuilder('u')
                ->where('u.id != :current')
                ->setParameter('current', $actor->getId())
                ->orderBy('u.FullName', 'ASC')
                ->getQuery()
                ->getResult();
            return $this->render('front/messenger/new_conversation.html.twig', [
                'actor' => $actor,
                'users' => $users,
            ]);
        }
        // Ne plus créer la conversation ici : rediriger vers compose (création à l’envoi du premier message)
        $other = $this->userRepository->find((int) $withId);
        if ($other instanceof User && $other->getId() !== $actor->getId()) {
            $existingConversation = $this->conversationRepository->findPrivateConversationBetween($actor, $other);
            if ($existingConversation !== null) {
                return $this->redirectToRoute('app_messenger_show', ['id' => $existingConversation->getId()]);
            }
        }
        return $this->redirectToRoute('app_messenger_compose', ['with' => (int) $withId]);
    }

    /**
     * Création d'un groupe (depuis n'importe où).
     * - GET : affiche le formulaire avec multi-sélection d'utilisateurs.
     * - POST : crée une conversation de groupe avec les participants choisis.
     */
    #[Route('/group/new', name: 'app_messenger_new_group', methods: ['GET', 'POST'])]
    public function newGroup(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }

        if ($request->isMethod('POST')) {
            /** @var list<string>|string|null $ids */
            $ids = $request->request->all('participants');
            if (!is_array($ids)) {
                $ids = $ids !== null && $ids !== '' ? [$ids] : [];
            }
            // Nettoyage / cast en int / suppression des doublons et de l'acteur
            $participantIds = [];
            foreach ($ids as $rawId) {
                $intId = (int) $rawId;
                if ($intId > 0 && $intId !== $actor->getId()) {
                    $participantIds[$intId] = $intId;
                }
            }
            if (count($participantIds) < 2) {
                $this->addFlash('warning', 'Un groupe doit contenir au moins deux autres personnes.');
                return $this->redirectToRoute('app_messenger_new_group');
            }
            $title = trim((string) ($request->request->get('title') ?? ''));
            $participants = $this->userRepository->createQueryBuilder('u')
                ->where('u.id IN (:ids)')
                ->setParameter('ids', array_values($participantIds))
                ->orderBy('u.FullName', 'ASC')
                ->getQuery()
                ->getResult();
            if (count($participants) === 0) {
                $this->addFlash('error', 'Participants invalides.');
                return $this->redirectToRoute('app_messenger_new_group');
            }
            if ($title === '') {
                $names = array_map(static fn(User $u) => $u->getFullName() ?? 'Utilisateur', $participants);
                $maxVisible = 3;
                $visibleNames = array_slice($names, 0, $maxVisible);
                $extraCount = count($names) - $maxVisible;
                $title = implode(', ', $visibleNames);
                if ($extraCount > 0) {
                    $title .= ' +' . $extraCount;
                }
            }
            $conversation = new Conversation();
            $conversation->setIsGroup(true);
            $conversation->setTitle($title);
            $conversation->setCreatedAt(new \DateTimeImmutable());
            $conversation->setIsDeleted(false);
            $conversation->setCreatedBy($actor);
            $conversation->addParticipant($actor);
            foreach ($participants as $user) {
                $conversation->addParticipant($user);
            }
            $this->em->persist($conversation);
            $this->em->flush();
            $this->addFlash('success', 'Groupe créé.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $conversation->getId()]);
        }

        // GET : affichage du formulaire
        $fromId = (int) ($request->query->get('from') ?? 0);
        $preselectedIds = [];
        $preselectedNames = [];
        if ($fromId > 0) {
            $baseConv = $this->conversationRepository->findOneByParticipant($fromId, $actor);
            if ($baseConv) {
                foreach ($baseConv->getParticipants() as $p) {
                    if ($p->getId() !== $actor->getId()) {
                        $preselectedIds[] = $p->getId();
                        $preselectedNames[] = $p->getFullName() ?? '';
                    }
                }
            }
        }
        $users = $this->userRepository->createQueryBuilder('u')
            ->where('u.id != :current')
            ->setParameter('current', $actor->getId())
            ->orderBy('u.FullName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('front/messenger/new_group.html.twig', [
            'actor' => $actor,
            'users' => $users,
            'preselected_ids' => $preselectedIds,
            'preselected_names' => $preselectedNames,
        ]);
    }

    #[Route('/{id}/message', name: 'app_messenger_send_message', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendMessage(Request $request, int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $content = trim((string) ($request->request->get('content') ?? ''));
        $uploadedFiles = $this->getUploadedAttachments($request);
        if ($content === '' && $uploadedFiles === []) {
            $this->addFlash('warning', 'Le message ne peut pas être vide.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
        }
        if ($content === '') {
            $content = ' '; // pièces jointes seules
        }
        $message = new Message();
        $message->setContent($content);
        $message->setSender($actor);
        $message->setConversation($conversation);
        $message->setCreatedAt(new \DateTimeImmutable());
        $message->setIsRead(false);
        $replyToId = $request->request->get('reply_to');
        if ($replyToId !== null && $replyToId !== '') {
            $replyTo = $this->messageRepository->findOneByConversationAndId($conversation, (int) $replyToId);
            if ($replyTo !== null) {
                $message->setReplyTo($replyTo);
            }
        }
        $conversation->addMessage($message);
        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $conversation->setUpdetedAt(new \DateTimeImmutable());
        $this->em->persist($message);
        $uploadError = $this->processAttachments($message, $uploadedFiles);
        if ($uploadError !== null) {
            $this->addFlash('warning', $uploadError);
            return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
        }
        $this->em->flush();
        $this->addFlash('success', 'Message envoyé.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
    }

    /**
     * Téléchargement d'une pièce jointe : réservé aux participants de la conversation.
     */
    #[Route('/attachment/{id}', name: 'app_messenger_attachment', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadAttachment(int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_login');
        }
        $attachment = $this->messageAttachmentRepository->find($id);
        if (!$attachment instanceof MessageAttachment) {
            $this->addFlash('error', 'Pièce jointe introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $message = $attachment->getMessage();
        $conversation = $message?->getConversation();
        if (!$conversation || !$this->conversationRepository->findOneByParticipant($conversation->getId(), $actor)) {
            $this->addFlash('error', 'Accès refusé.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $path = $this->getParameter('kernel.project_dir') . '/public/uploads/messenger/' . $attachment->getFileName();
        if (!is_file($path)) {
            $this->addFlash('error', 'Fichier introuvable.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $conversation->getId()]);
        }
        $response = new StreamedResponse(static function () use ($path) {
            $handle = fopen($path, 'rb');
            if ($handle) {
                while (!feof($handle)) {
                    echo fread($handle, 8192);
                    flush();
                }
                fclose($handle);
            }
        });
        $response->headers->set('Content-Type', $attachment->getMimeType() ?? 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'inline; filename="' . addslashes($attachment->getOriginalName() ?? 'attachment') . '"');
        return $response;
    }

    /**
     * Fragment HTML du formulaire d'édition (pour le widget flottant).
     */
    #[Route('/{convId}/message/{messageId}/edit-form', name: 'app_messenger_edit_message_form', requirements: ['convId' => '\d+', 'messageId' => '\d+'], methods: ['GET'])]
    public function editMessageForm(int $convId, int $messageId): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        $conversation = $this->conversationRepository->findOneByParticipant($convId, $actor);
        if (!$conversation) {
            return new Response('', 404);
        }
        $message = $this->messageRepository->findOneByConversationAndId($conversation, $messageId);
        if (!$message || $message->getSender()?->getId() !== $actor->getId()) {
            return new Response('', 404);
        }
        return $this->render('front/messenger/_edit_message_form.html.twig', [
            'current_conversation' => $conversation,
            'editing_message' => $message,
        ]);
    }

    #[Route('/{convId}/message/{messageId}/edit', name: 'app_messenger_edit_message', requirements: ['convId' => '\d+', 'messageId' => '\d+'], methods: ['GET', 'POST'])]
    public function editMessage(Request $request, int $convId, int $messageId): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($convId, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $message = $this->messageRepository->findOneByConversationAndId($conversation, $messageId);
        if (!$message || $message->getSender()?->getId() !== $actor->getId()) {
            $this->addFlash('error', 'Message introuvable ou non modifiable.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        if ($request->isMethod('POST')) {
            $content = trim((string) ($request->request->get('content') ?? ''));
            if ($content !== '') {
                $message->setContent($content);
                $message->setUpdatedAt(new \DateTimeImmutable());
                $this->em->flush();
                $this->addFlash('success', 'Message modifié.');
            }
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        $messages = $this->messageRepository->findByConversation($conversation);
        $conversations = $this->conversationRepository->findByParticipant($actor, true, false);
        $other = null;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getId() !== $actor->getId()) {
                $other = $p;
                break;
            }
        }
        $otherDisplayName = $other ? $other->getFullName() : 'Conversation';
        $currentNickname = null;
        if ($other) {
            $currentNickname = $actor->getConversationNickname($conversation->getId(), $other->getId());
            if ($currentNickname !== null) {
                $otherDisplayName = $currentNickname;
            }
        }
        $attachmentInlineSrcs = $this->buildInlineImageDataUrls($messages, $this->getParameter('kernel.project_dir'));
        return $this->render('front/messenger/messages.html.twig', [
            'actor' => $actor,
            'conversations' => $conversations,
            'current_conversation' => $conversation,
            'messages' => $messages,
            'other' => $other,
            'other_display_name' => $otherDisplayName,
            'current_nickname' => $currentNickname,
            'editing_message' => $message,
            'show_archived' => false,
            'attachment_inline_srcs' => $attachmentInlineSrcs,
        ]);
    }

    #[Route('/{convId}/message/{messageId}/delete', name: 'app_messenger_delete_message', requirements: ['convId' => '\d+', 'messageId' => '\d+'], methods: ['POST'])]
    public function deleteMessage(int $convId, int $messageId): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($convId, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $message = $this->messageRepository->findOneByConversationAndId($conversation, $messageId);
        if (!$message || $message->getSender()?->getId() !== $actor->getId()) {
            $this->addFlash('error', 'Message introuvable ou non supprimable.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        $message->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Message supprimé.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
    }

    #[Route('/{id}/mark-read', name: 'app_messenger_mark_conversation_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markConversationRead(Request $request, int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        foreach ($conversation->getMessages() as $message) {
            if ($message->getDeletedAt() === null && !$message->getReadBy()->contains($actor)) {
                $message->addReadBy($actor);
                $message->setIsRead(true);
            }
        }
        $this->em->flush();
        $this->addFlash('success', 'Conversation marquée comme lue.');
        $referer = $request->headers->get('Referer');
        if ($referer) {
            return $this->redirect($referer);
        }
        return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
    }

    /**
     * Contenu HTML du modal de transfert (pour le mode réduit / widget flottant).
     * GET ?exclude=ID pour exclure la conversation ID de la liste.
     */
    #[Route('/transfer-modal-content', name: 'app_messenger_transfer_modal_content', methods: ['GET'])]
    public function transferModalContent(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return new Response('', 401);
        }
        $excludeId = $request->query->getInt('exclude', 0);
        $conversations = $this->conversationRepository->findByParticipant($actor, true, false, null, false);
        $currentConversation = null;
        if ($excludeId > 0) {
            foreach ($conversations as $c) {
                if ($c->getId() === $excludeId) {
                    $currentConversation = $c;
                    break;
                }
            }
        }
        return $this->render('front/messenger/_transfer_modal.html.twig', [
            'conversations' => $conversations,
            'current_conversation' => $currentConversation,
            'actor' => $actor,
        ]);
    }

    #[Route('/forward', name: 'app_messenger_forward_message', methods: ['POST'])]
    public function forwardMessage(Request $request): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $messageId = (int) $request->request->get('message_id');
        $targetConvId = (int) $request->request->get('target_conv_id');
        $sourceMessage = $this->messageRepository->find($messageId);
        if (!$sourceMessage || $sourceMessage->getDeletedAt() !== null) {
            $this->addFlash('error', 'Message introuvable.');
            return $this->redirect($request->headers->get('Referer', $this->generateUrl('app_messenger_index')));
        }
        $sourceConv = $sourceMessage->getConversation();
        if (!$this->conversationRepository->findOneByParticipant($sourceConv->getId(), $actor)) {
            $this->addFlash('error', 'Accès refusé.');
            return $this->redirect($request->headers->get('Referer', $this->generateUrl('app_messenger_index')));
        }
        $targetConv = $this->conversationRepository->findOneByParticipant($targetConvId, $actor);
        if (!$targetConv) {
            $this->addFlash('error', 'Conversation de destination introuvable.');
            return $this->redirect($request->headers->get('Referer', $this->generateUrl('app_messenger_index')));
        }
        $forwarded = new Message();
        $forwarded->setContent($sourceMessage->getContent());
        $forwarded->setSender($actor);
        $forwarded->setConversation($targetConv);
        $forwarded->setCreatedAt(new \DateTimeImmutable());
        $forwarded->setIsRead(false);
        $targetConv->addMessage($forwarded);
        $targetConv->setLastMessageAt(new \DateTimeImmutable());
        $targetConv->setUpdetedAt(new \DateTimeImmutable());
        $this->em->persist($forwarded);
        $this->em->flush();
        $this->addFlash('success', 'Message transféré.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $targetConvId]);
    }

    #[Route('/{id}/delete', name: 'app_messenger_delete_conversation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteConversation(int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation->setIsDeleted(true);
        $conversation->setUpdetedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Conversation supprimée.');
        return $this->redirectToRoute('app_messenger_index');
    }

    #[Route('/{id}/archive', name: 'app_messenger_archive_conversation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archiveConversation(int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation->setIsArchived(true);
        $conversation->setUpdetedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Conversation archivée.');
        return $this->redirectToRoute('app_messenger_index');
    }

    #[Route('/{id}/unarchive', name: 'app_messenger_unarchive_conversation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unarchiveConversation(int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation->setIsArchived(false);
        $conversation->setUpdetedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Conversation désarchivée.');
        return $this->redirectToRoute('app_messenger_index');
    }

    /**
     * Quitter un groupe : retire l'utilisateur des participants et redirige vers la liste.
     */
    #[Route('/{id}/leave-group', name: 'app_messenger_leave_group', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function leaveGroup(int $id): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($id, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        if (!$conversation->isGroup()) {
            $this->addFlash('error', 'Cette conversation n\'est pas un groupe.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
        }
        $conversation->removeParticipant($actor);
        $this->em->flush();
        return $this->redirectToRoute('app_messenger_index');
    }

    #[Route('/{convId}/message/{messageId}/reaction', name: 'app_messenger_add_reaction', requirements: ['convId' => '\d+', 'messageId' => '\d+'], methods: ['POST'])]
    public function addReaction(Request $request, int $convId, int $messageId): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($convId, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $message = $this->messageRepository->findOneByConversationAndId($conversation, $messageId);
        if (!$message) {
            $this->addFlash('error', 'Message introuvable.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        $emoji = (int) ($request->request->get('emoji') ?? -1);
        if ($emoji < 0 || $emoji > 5) {
            $this->addFlash('warning', 'Réaction invalide.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        // Un message ne peut avoir qu'une seule réaction par utilisateur : on remplace l'éventuelle précédente
        $existing = $this->reactionRepository->findOneByMessageAndReactor($message, $actor);
        if ($existing) {
            $message->removeReaction($existing);
            $this->em->remove($existing);
        }
        $reaction = new MessageReaction();
        $reaction->setMessage($message);
        $reaction->setReactor($actor);
        $reaction->setEmoji($emoji);
        $message->addReaction($reaction);
        $this->em->persist($reaction);
        $this->em->flush();
        $this->addFlash('success', 'Réaction ajoutée.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
    }

    #[Route('/{convId}/reaction/{reactionId}/delete', name: 'app_messenger_remove_reaction', requirements: ['convId' => '\d+', 'reactionId' => '\d+'], methods: ['POST'])]
    public function removeReaction(int $convId, int $reactionId): Response
    {
        $actor = $this->getActor();
        if ($actor === null) {
            return $this->redirectToRoute('app_messenger_index');
        }
        $conversation = $this->conversationRepository->findOneByParticipant($convId, $actor);
        if (!$conversation) {
            $this->addFlash('error', 'Conversation introuvable.');
            return $this->redirectToRoute('app_messenger_index');
        }
        $reaction = $this->reactionRepository->find($reactionId);
        if (!$reaction instanceof MessageReaction || $reaction->getReactor()?->getId() !== $actor->getId()) {
            $this->addFlash('error', 'Réaction introuvable ou non supprimable.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
        }
        $message = $reaction->getMessage();
        if ($message) {
            $message->removeReaction($reaction);
        }
        $this->em->remove($reaction);
        $this->em->flush();
        $this->addFlash('success', 'Réaction supprimée.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $convId]);
    }

    private const MAX_ATTACHMENTS = 5;
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 Mo
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** @return list<UploadedFile> */
    private function getUploadedAttachments(Request $request): array
    {
        $files = $request->files->all('attachments');
        if (!\is_array($files)) {
            $one = $request->files->get('attachments');
            return $one instanceof UploadedFile ? [$one] : [];
        }
        $list = [];
        foreach ($files as $f) {
            if ($f instanceof UploadedFile) {
                $list[] = $f;
            }
        }
        return $list;
    }

    /**
     * Crée les MessageAttachment et les attache au message. Retourne un message d'erreur ou null.
     */
    private function processAttachments(Message $message, array $uploadedFiles): ?string
    {
        if ($uploadedFiles === []) {
            return null;
        }
        if (\count($uploadedFiles) > self::MAX_ATTACHMENTS) {
            return sprintf('Maximum %d pièce(s) jointe(s) autorisée(s).', self::MAX_ATTACHMENTS);
        }
        foreach ($uploadedFiles as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return 'Un fichier est invalide ou trop volumineux.';
            }
            if ($file->getSize() > self::MAX_FILE_SIZE) {
                return 'Un fichier dépasse la taille maximale (10 Mo).';
            }
            $mime = $file->getMimeType();
            if ($mime === null || !\in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                return 'Type de fichier non autorisé (images, PDF, Word uniquement).';
            }
            $attachment = new MessageAttachment();
            $attachment->setMessage($message);
            $attachment->setOriginalName($file->getClientOriginalName());
            $attachment->setMimeType($mime);
            $attachment->setSize($file->getSize());
            $attachment->setAttachmentFile($file);
            $message->addAttachment($attachment);
            $this->em->persist($attachment);
        }
        return null;
    }

    /**
     * Construit une map [ attachmentId => dataUrl ] pour les images de la conversation.
     * Inline dans le HTML = affichage immédiat sans requête. Limité en taille (200 Ko) et nombre (30).
     *
     * @param Message[] $messages
     * @return array<int, string>
     */
    private function buildInlineImageDataUrls(array $messages, string $projectDir, int $maxSize = 200000, int $maxCount = 30): array
    {
        $basePath = $projectDir . '/public/uploads/messenger/';
        $result = [];
        $count = 0;
        foreach ($messages as $message) {
            foreach ($message->getAttachments() as $att) {
                if ($count >= $maxCount) {
                    return $result;
                }
                if (!$att->isImage() || $att->getFileName() === null) {
                    continue;
                }
                $size = $att->getSize() ?? 0;
                if ($size > $maxSize) {
                    continue;
                }
                $path = $basePath . $att->getFileName();
                if (!is_file($path) || !is_readable($path)) {
                    continue;
                }
                $data = @file_get_contents($path);
                if ($data === false) {
                    continue;
                }
                $mime = $att->getMimeType() ?? 'image/jpeg';
                $result[$att->getId()] = 'data:' . $mime . ';base64,' . base64_encode($data);
                $count++;
            }
        }
        return $result;
    }
}
