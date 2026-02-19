<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontOfficeController extends AbstractController
{
    #[Route('/home', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('front/index.html.twig', [
            'controller_name' => 'QuizController',
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/calendar', name: 'app_calendar')]
    public function calendar(): Response
    {
        return $this->render('front/reservation/calendar.html.twig', [
            'controller_name' => 'QuizController',
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/articles', name: 'app_articles')]
    public function articles(): Response
    {
        return $this->render('front/article/articles.html.twig', [
            'controller_name' => 'QuizController',
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/tutor', name: 'app_tutor')]
    public function tutor(): Response
    {
        return $this->render('front/reservation/tutor.html.twig', [
            'controller_name' => 'QuizController',
            'user' => $this->getUser(),
        ]);
    }

    /**
     * Profil utilisateur (module user à intégrer).
     * Pour l’instant : page placeholder. À remplacer par le vrai module profil.
     */
    #[Route('/profile/{id}', name: 'app_profile', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function profile(int $id): Response
    {
        return $this->render('front/profile/placeholder.html.twig', [
            'user_id' => $id,
        ]);
    }
}
