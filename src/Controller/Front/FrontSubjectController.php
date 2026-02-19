<?php

namespace App\Controller\Front;

use App\Repository\MatiereRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/explore')]
class FrontSubjectController extends AbstractController
{
    #[Route('/subjects', name: 'app_front_subjects_index')]
    public function index(MatiereRepository $matiereRepository): Response
    {
        return $this->render('front/subjects/index.html.twig', [
            'matieres' => $matiereRepository->findAll(),
        ]);
    }

    #[Route('/subject/{id}', name: 'app_front_subject_show')]
    public function show(int $id, MatiereRepository $matiereRepository): Response
    {
        $matiere = $matiereRepository->find($id);
        
        if (!$matiere) {
            throw $this->createNotFoundException('Subject not found');
        }

        return $this->render('front/subjects/show.html.twig', [
            'matiere' => $matiere,
        ]);
    }
}