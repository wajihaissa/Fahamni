<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Student;
use App\Entity\Blog;
use App\Entity\Interaction;
use App\Repository\InteractionRepository;

#[Route('/admin', name: 'admin_')]
final class AdminController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function dashboard(EntityManagerInterface $entityManager): Response
    {
        // Get statistics for dashboard
        $totalUsers = $entityManager->getRepository(User::class)->count([]);
        $totalStudents = $entityManager->getRepository(Student::class)->count([]);
        $totalArticles = $entityManager->getRepository(Blog::class)->count([]);
        
        // Get recent users (limit 5)
        $recentUsers = $entityManager->getRepository(User::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);
        
        // Get recent articles (limit 3)
        $recentArticles = $entityManager->getRepository(Blog::class)
            ->findBy([], ['createdAt' => 'DESC'], 3);

        // Get pending articles for moderation
        $pendingArticles = $entityManager->getRepository(Blog::class)
            ->findBy(['status' => 'pending'], ['createdAt' => 'DESC']);

        return $this->render('back/index.html.twig', [
            'totalUsers' => $totalUsers,
            'totalStudents' => $totalStudents,
            'totalArticles' => $totalArticles,
            'recentUsers' => $recentUsers,
            'recentArticles' => $recentArticles,
            'pendingArticles' => $pendingArticles,
        ]);
    }

    #[Route('/users', name: 'users')]
    public function users(EntityManagerInterface $entityManager): Response
    {
        $users = $entityManager->getRepository(User::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return $this->render('back/users/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/articles', name: 'articles')]
    public function articles(EntityManagerInterface $entityManager): Response
    {
        $articles = $entityManager->getRepository(Blog::class)
            ->findBy([], ['createdAt' => 'DESC']);

        $pendingArticles = $entityManager->getRepository(Blog::class)
            ->findBy(['status' => 'pending'], ['createdAt' => 'DESC']);

        $rejectedArticles = $entityManager->getRepository(Blog::class)
            ->findBy(['status' => 'rejected'], ['createdAt' => 'DESC']);

        return $this->render('back/articles/index.html.twig', [
            'articles' => $articles,
            'pendingArticles' => $pendingArticles,
            'rejectedArticles' => $rejectedArticles,
        ]);
    }

    #[Route('/article/{id}/approve', name: 'article_approve', methods: ['POST'])]
    public function approveArticle(Request $request, Blog $blog, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('approve' . $blog->getId(), $request->request->get('_token'))) {
            $blog->setStatus('published');
            $blog->setPublishedAt(new \DateTimeImmutable());
            $blog->setIsStatusNotifRead(false);
            $entityManager->flush();
            $this->addFlash('success', 'Article "' . $blog->getTitre() . '" approuve et publie.');
        }

        return $this->redirectToRoute('admin_articles');
    }

    #[Route('/article/{id}/reject', name: 'article_reject', methods: ['POST'])]
    public function rejectArticle(Request $request, Blog $blog, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('reject' . $blog->getId(), $request->request->get('_token'))) {
            $blog->setStatus('rejected');
            $blog->setIsStatusNotifRead(false);
            $entityManager->flush();
            $this->addFlash('success', 'Article "' . $blog->getTitre() . '" rejete.');
        }

        return $this->redirectToRoute('admin_articles');
    }

    #[Route('/comments', name: 'comments')]
    public function comments(InteractionRepository $interactionRepo, \App\Service\BadWordDetector $detector): Response
    {
        $flaggedComments  = $interactionRepo->findFlaggedComments();
        $archivedComments = $interactionRepo->findArchivedComments();
        $flaggedCount     = count($flaggedComments);

        $badWordsMap = [];
        foreach ($flaggedComments as $comment) {
            if ($comment->getComment()) {
                $badWordsMap[$comment->getId()] = $detector->findBadWords($comment->getComment());
            }
        }

        return $this->render('back/comments/index.html.twig', [
            'flaggedComments'  => $flaggedComments,
            'archivedComments' => $archivedComments,
            'flaggedCount'     => $flaggedCount,
            'badWordsMap'      => $badWordsMap,
        ]);
    }

    #[Route('/comment/{id}/delete', name: 'comment_delete', methods: ['POST'])]
    public function deleteComment(Request $request, Interaction $interaction, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete_comment' . $interaction->getId(), $request->request->get('_token'))) {
            // Archiver comme preuve plutôt que supprimer définitivement
            $interaction->setIsDeletedByAdmin(true);
            $entityManager->flush();
            $this->addFlash('success', 'Commentaire archive comme preuve.');
        } else {
            $this->addFlash('error', 'Token invalide. Veuillez reessayer.');
        }

        return $this->redirectToRoute('admin_comments');
    }

    #[Route('/comment/{id}/approve', name: 'comment_approve', methods: ['POST'])]
    public function approveComment(Request $request, Interaction $interaction, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('approve_comment' . $interaction->getId(), $request->request->get('_token'))) {
            $interaction->setIsFlagged(false);
            $entityManager->flush();
            $this->addFlash('success', 'Commentaire approuve.');
        }

        return $this->redirectToRoute('admin_comments');
    }

    #[Route('/comments/count', name: 'comments_count', methods: ['GET'])]
    public function commentsCount(InteractionRepository $interactionRepo): JsonResponse
    {
        $count = $interactionRepo->countFlaggedComments();
        return $this->json(['count' => $count]);
    }

    #[Route('/comments/latest-flagged', name: 'comments_latest_flagged', methods: ['GET'])]
    public function latestFlaggedComment(InteractionRepository $interactionRepo, \App\Service\BadWordDetector $detector): JsonResponse
    {
        $comments = $interactionRepo->findFlaggedComments();
        if (empty($comments)) {
            return $this->json(['comment' => null]);
        }

        $latest = $comments[0]; // déjà trié par createdAt DESC
        $badWords = $detector->findBadWords($latest->getComment() ?? '');
        $user = $latest->getInnteractor();
        $userName = $user->getFullName() ?: explode('@', $user->getEmail())[0];

        return $this->json([
            'comment' => [
                'id'        => $latest->getId(),
                'user'      => $userName,
                'article'   => $latest->getBlog()->getTitre(),
                'articleId' => $latest->getBlog()->getId(),
                'date'      => $latest->getCreatedAt()->format('d/m/Y H:i'),
                'badWords'  => $badWords,
            ],
        ]);
    }

    #[Route('/articles/pending-count', name: 'articles_pending_count', methods: ['GET'])]
    public function articlesPendingCount(EntityManagerInterface $entityManager): JsonResponse
    {
        $count = $entityManager->getRepository(Blog::class)->count(['status' => 'pending']);
        return $this->json(['count' => $count]);
    }

    #[Route('/article/{id}', name: 'article_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showArticle(Blog $blog): Response
    {
        return $this->render('back/blog/show.html.twig', [
            'blog' => $blog,
        ]);
    }

    #[Route('/statistics', name: 'statistics')]
    public function statistics(EntityManagerInterface $entityManager): Response
    {
        $totalUsers = $entityManager->getRepository(User::class)->count([]);
        $activeStudents = $entityManager->getRepository(Student::class)
            ->count(['isActive' => true]);
        $totalArticles = $entityManager->getRepository(Blog::class)->count([]);

        return $this->render('back/statistics/index.html.twig', [
            'totalUsers' => $totalUsers,
            'activeStudents' => $activeStudents,
            'totalArticles' => $totalArticles,
        ]);
    }
}