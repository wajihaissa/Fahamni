<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Student;
use App\Entity\Blog;

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

        return $this->render('back/index.html.twig', [
            'totalUsers' => $totalUsers,
            'totalStudents' => $totalStudents,
            'totalArticles' => $totalArticles,
            'recentUsers' => $recentUsers,
            'recentArticles' => $recentArticles,
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