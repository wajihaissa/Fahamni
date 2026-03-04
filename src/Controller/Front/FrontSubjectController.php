<?php

namespace App\Controller\Front;

use App\Repository\CategoryRepository;
use App\Repository\MatiereRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/explore')]
class FrontSubjectController extends AbstractController
{
    #[Route('/subjects', name: 'app_front_subjects_index')]
    public function index(Request $request, MatiereRepository $matiereRepository, CategoryRepository $categoryRepository): Response
    {
        // 1. Get all categories for the sidebar
        $categories = $categoryRepository->findAll();

        // 2. Get Search Parameters from URL (e.g. ?q=math&category=5)
        $searchTerm = $request->query->get('q');
        $categoryId = $request->query->get('category');

        // 3. Resolve the Category Object
        $currentCategory = $categoryId ? $categoryRepository->find($categoryId) : null;

        // 4. Fetch Results using our smart Repository method
        // This handles: Text only, Category only, Both, or Empty (All)
        $matieres = $matiereRepository->search($searchTerm, $currentCategory);

        return $this->render('front/subjects/index.html.twig', [
            'matieres' => $matieres,
            'categories' => $categories,
            'currentCategory' => $currentCategory,
            'searchTerm' => $searchTerm // Pass back to view so the input stays filled
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