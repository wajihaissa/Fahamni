<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Blog;
use App\Entity\Student;
use App\Service\UserAiSummaryService;
use App\Service\RegistrationFraudScoringService;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function dashboard(
        Request $request,
        EntityManagerInterface $entityManager,
        UserAiSummaryService $userAiSummaryService,
        RegistrationFraudScoringService $registrationFraudScoringService
    ): Response
    {
        // Get statistics for dashboard
        $totalUsers = $entityManager->getRepository(User::class)->count([]);
        $countUsersByRole = static function (EntityManagerInterface $entityManager, string $role): int {
            return (int) $entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->where('u.roles LIKE :role')
                ->setParameter('role', '%"' . $role . '"%')
                ->getQuery()
                ->getSingleScalarResult();
        };
        $totalStudents = $countUsersByRole($entityManager, 'ROLE_ETUDIANT');
        $totalTutors = $countUsersByRole($entityManager, 'ROLE_TUTOR');
        $totalArticles = $entityManager->getRepository(Blog::class)->count([]);
        
        // Get recent users (limit 5)
        $recentUsers = $entityManager->getRepository(User::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);
        
        // Get recent articles (limit 3)
        $recentArticles = $entityManager->getRepository(Blog::class)
            ->findBy([], ['createdAt' => 'DESC'], 3);

        // Pending review queue: only registrations still awaiting decision.
        $pendingUsers = $entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->leftJoin('u.profile', 'p')->addSelect('p')
            ->where('u.status = :inactive')
            ->andWhere('p.validationStatus = :pending')
            ->setParameter('inactive', false)
            ->setParameter('pending', 'pending')
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        $pendingFraudScores = [];
        foreach ($pendingUsers as $pendingUser) {
            if ($pendingUser instanceof User && $pendingUser->getId() !== null) {
                $pendingFraudScores[$pendingUser->getId()] = $registrationFraudScoringService->score(
                    $pendingUser,
                    $pendingUser->getProfile()
                );
            }
        }

        // Secondary list scopes for reviewed accounts.
        $usersScope = (string) $request->query->get('users_scope', 'all');
        $allowedScopes = ['all', 'approved', 'declined'];
        if (!in_array($usersScope, $allowedScopes, true)) {
            $usersScope = 'all';
        }

        // Paginated secondary list with selected scope.
        $usersPerPage = 8;
        $requestedPage = max(1, (int) $request->query->get('users_page', 1));
        $usersTotalQb = $entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->leftJoin('u.profile', 'p');
        if ($usersScope === 'approved') {
            $usersTotalQb->where('p.validationStatus = :approved')->setParameter('approved', 'approved');
        } elseif ($usersScope === 'declined') {
            $usersTotalQb->where('p.validationStatus = :rejected')->setParameter('rejected', 'rejected');
        } else {
            $usersTotalQb
                ->where('(p.validationStatus IS NULL OR p.validationStatus != :pending)')
                ->setParameter('pending', 'pending');
        }
        $usersTotal = (int) $usersTotalQb->getQuery()->getSingleScalarResult();
        $usersTotalPages = max(1, (int) ceil($usersTotal / $usersPerPage));
        $usersPage = min($requestedPage, $usersTotalPages);
        $usersOffset = ($usersPage - 1) * $usersPerPage;
        $usersQb = $entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->leftJoin('u.profile', 'p')
            ->addSelect('p');
        if ($usersScope === 'approved') {
            $usersQb->where('p.validationStatus = :approved')->setParameter('approved', 'approved');
        } elseif ($usersScope === 'declined') {
            $usersQb->where('p.validationStatus = :rejected')->setParameter('rejected', 'rejected');
        } else {
            $usersQb
                ->where('(p.validationStatus IS NULL OR p.validationStatus != :pending)')
                ->setParameter('pending', 'pending');
        }
        $users = $usersQb
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($usersOffset)
            ->setMaxResults($usersPerPage)
            ->getQuery()
            ->getResult();
        $aiUserSummaries = [];
        $usersFraudScores = [];
        foreach ($users as $dashboardUser) {
            if ($dashboardUser instanceof User && $dashboardUser->getId() !== null) {
                $aiUserSummaries[$dashboardUser->getId()] = $userAiSummaryService->summarize($dashboardUser);
                $usersFraudScores[$dashboardUser->getId()] = $registrationFraudScoringService->score(
                    $dashboardUser,
                    $dashboardUser->getProfile()
                );
            }
        }

        return $this->render('back/index.html.twig', [
            'totalUsers' => $totalUsers,
            'totalStudents' => $totalStudents,
            'totalTutors' => $totalTutors,
            'totalArticles' => $totalArticles,
            'recentUsers' => $recentUsers,
            'recentArticles' => $recentArticles,
            'pendingUsers' => $pendingUsers,
            'pendingFraudScores' => $pendingFraudScores,
            'users' => $users,
            'users_scope' => $usersScope,
            'users_page' => $usersPage,
            'users_total_pages' => $usersTotalPages,
            'aiUserSummaries' => $aiUserSummaries,
            'usersFraudScores' => $usersFraudScores,
        ]);
    }

    #[Route('/users', name: 'users')]
    public function users(): Response
    {
        return $this->redirect($this->generateUrl('admin_dashboard') . '#users');
    }

    #[Route('/articles', name: 'articles')]
    public function articles(EntityManagerInterface $entityManager): Response
    {
        $articles = $entityManager->getRepository(Blog::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return $this->render('back/articles/index.html.twig', [
            'articles' => $articles,
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
