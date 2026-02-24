<?php

namespace App\Controller;

use App\Entity\Blog;
use App\Entity\Interaction;
use App\Repository\BlogRepository;
use App\Repository\InteractionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/notifications')]
class NotificationController extends AbstractController
{
    #[Route('/count', name: 'app_notifications_count', methods: ['GET'])]
    public function count(InteractionRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            $user = $em->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        $count = $repo->countUnreadNotifications($user);

        return $this->json(['count' => $count]);
    }

    #[Route('/list', name: 'app_notifications_list', methods: ['GET'])]
    public function list(InteractionRepository $repo, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            $user = $em->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        $notifications = $repo->findUnreadNotifications($user);

        return $this->render('front/_notifications_panel.html.twig', [
            'notifications' => $notifications,
        ]);
    }

    #[Route('/mark-read/{id}', name: 'app_notifications_mark_read', methods: ['POST'])]
    public function markRead(Interaction $interaction, EntityManagerInterface $em): JsonResponse
    {
        $interaction->setIsNotifRead(true);
        $em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/mark-all-read', name: 'app_notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(InteractionRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            $user = $em->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        $unread = $repo->findUnreadNotifications($user, true);
        foreach ($unread as $interaction) {
            $interaction->setIsNotifRead(true);
        }
        $em->flush();

        return $this->json(['success' => true]);
    }

    // --- Notifications Tuteur (combinées : statut articles + interactions) ---

    #[Route('/tutor/count', name: 'app_notifications_tutor_count', methods: ['GET'])]
    public function tutorCount(InteractionRepository $interactionRepo, BlogRepository $blogRepo, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            $user = $em->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        $statusCount = $blogRepo->countStatusNotifications($user);
        $interactionCount = $interactionRepo->countUnreadNotifications($user);

        return $this->json([
            'count' => $statusCount + $interactionCount,
            'statusCount' => $statusCount,
            'interactionCount' => $interactionCount,
        ]);
    }

    #[Route('/tutor/list', name: 'app_notifications_tutor_list', methods: ['GET'])]
    public function tutorList(InteractionRepository $interactionRepo, BlogRepository $blogRepo, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            $user = $em->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        $statusNotifs = $blogRepo->findStatusNotifications($user);
        $interactionNotifs = $interactionRepo->findUnreadNotifications($user);

        return $this->render('front/_tutor_notifications_panel.html.twig', [
            'statusNotifs' => $statusNotifs,
            'interactionNotifs' => $interactionNotifs,
        ]);
    }

    #[Route('/tutor/mark-status-read/{id}', name: 'app_notifications_tutor_mark_status_read', methods: ['POST'])]
    public function markStatusRead(Blog $blog, EntityManagerInterface $em): JsonResponse
    {
        $blog->setIsStatusNotifRead(true);
        $em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/tutor/mark-all-read', name: 'app_notifications_tutor_mark_all_read', methods: ['POST'])]
    public function tutorMarkAllRead(InteractionRepository $interactionRepo, BlogRepository $blogRepo, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            $user = $em->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        // Marquer les notifications de statut comme lues
        $statusNotifs = $blogRepo->findStatusNotifications($user, true);
        foreach ($statusNotifs as $blog) {
            $blog->setIsStatusNotifRead(true);
        }

        // Marquer les notifications d'interactions comme lues
        $unread = $interactionRepo->findUnreadNotifications($user, true);
        foreach ($unread as $interaction) {
            $interaction->setIsNotifRead(true);
        }

        $em->flush();

        return $this->json(['success' => true]);
    }
}
