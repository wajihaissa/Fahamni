<?php

namespace App\Controller;

use App\Repository\BlogRepository;
use App\Repository\InteractionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    #[Route('/articles', name: 'app_articles')]
    public function index(Request $request, BlogRepository $blogRepository, InteractionRepository $interactionRepository): Response
    {
        // Récupérer le paramètre de recherche
        $search = $request->query->get('search');

        if ($search) {
            $blogs = $blogRepository->createQueryBuilder('b')
                ->where('b.status = :status')
                ->andWhere('b.titre LIKE :search OR b.content LIKE :search')
                ->setParameter('status', 'published')
                ->setParameter('search', '%' . $search . '%')
                ->orderBy('b.publishedAt', 'DESC')
                ->getQuery()
                ->getResult();
        } else {
            $blogs = $blogRepository->findBy(
                ['status' => 'published'],
                ['publishedAt' => 'DESC']
            );
        }

        // Récupérer les commentaires pour l'affichage
        $interactions = [];
        foreach ($blogs as $blog) {
            $interactions[$blog->getId()] = [
                'comments' => $interactionRepository->findCommentsByBlog($blog->getId()),
            ];
        }

        // Rediriger vers le bon template selon le rôle
        if ($this->isGranted('ROLE_TUTOR')) {
            return $this->render('front/article/articles.html.twig', [
                'blogs' => $blogs,
                'interactions' => $interactions,
            ]);
        }

        // ROLE_ETUDIANT ou tout autre rôle -> vue étudiant
        return $this->render('front/article/articleEtud.html.twig', [
            'blogs' => $blogs,
            'interactions' => $interactions,
        ]);
    }
}
