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

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function dashboard(Request $request, EntityManagerInterface $entityManager): Response
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

        // Get pending users (inactive status)
        $pendingUsers = $entityManager->getRepository(User::class)
            ->findBy(['Status' => false], ['createdAt' => 'DESC']);

        // Paginated users for users management block
        $usersPerPage = 8;
        $requestedPage = max(1, (int) $request->query->get('users_page', 1));
        $usersTotal = $entityManager->getRepository(User::class)->count([]);
        $usersTotalPages = max(1, (int) ceil($usersTotal / $usersPerPage));
        $usersPage = min($requestedPage, $usersTotalPages);
        $usersOffset = ($usersPage - 1) * $usersPerPage;
        $users = $entityManager->getRepository(User::class)
            ->findBy([], ['createdAt' => 'DESC'], $usersPerPage, $usersOffset);

        return $this->render('back/index.html.twig', [
            'totalUsers' => $totalUsers,
            'totalStudents' => $totalStudents,
            'totalTutors' => $totalTutors,
            'totalArticles' => $totalArticles,
            'recentUsers' => $recentUsers,
            'recentArticles' => $recentArticles,
            'pendingUsers' => $pendingUsers,
            'users' => $users,
            'users_page' => $usersPage,
            'users_total_pages' => $usersTotalPages,
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
