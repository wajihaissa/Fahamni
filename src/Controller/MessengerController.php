<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\MessageReaction;
use App\Entity\User;
use App\Repository\ConversationReportRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessageReactionRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\MessengerActorResolver;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly MessageRepository $messageRepository,
        private readonly MessageReactionRepository $reactionRepository,
        private readonly UserRepository $userRepository,
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
        return $this->render('front/messenger/messages.html.twig', [
            'actor' => $actor,
            'conversations' => $conversations,
            'current_conversation' => $conversation,
            'messages' => $messages,
            'other' => $other,
            'other_display_name' => $otherDisplayName,
            'current_nickname' => $currentNickname,
            'show_archived' => false,
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
        if ($content === '') {
            $this->addFlash('warning', 'Le message ne peut pas être vide.');
            return $this->redirectToRoute('app_messenger_compose', ['with' => $other->getId()]);
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
        if ($content === '') {
            $this->addFlash('warning', 'Le message ne peut pas être vide.');
            return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
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
        $this->em->flush();
        $this->addFlash('success', 'Message envoyé.');
        return $this->redirectToRoute('app_messenger_show', ['id' => $id]);
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
}
