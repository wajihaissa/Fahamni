<?php

namespace App\Controller;

use App\Entity\Blog;
use App\Form\BlogType;
use App\Repository\BlogRepository;
use App\Repository\InteractionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\AiContentGenerator;
use App\Service\BadWordDetector;
use App\Service\SpellCheckerService;

#[Route('/article')]
class BlogController extends AbstractController
{
    // 📖 READ - Liste de tous les articles TUTEUR
    #[Route('/', name: 'app_blog_index', methods: ['GET'])]
    public function index(Request $request, BlogRepository $blogRepository, InteractionRepository $interactionRepository): Response
    {
        // Récupérer le paramètre de recherche
        $search = $request->query->get('search');

        if ($search) {
            // Recherche par titre ou contenu
            $blogs = $blogRepository->createQueryBuilder('b')
                ->where('b.status = :status')
                ->andWhere('b.titre LIKE :search OR b.content LIKE :search')
                ->setParameter('status', 'published')
                ->setParameter('search', '%' . $search . '%')
                ->orderBy('b.publishedAt', 'DESC')
                ->getQuery()
                ->getResult();
        } else {
            // Afficher tous les articles publiés
            $blogs = $blogRepository->findBy(
                ['status' => 'published'],
                ['publishedAt' => 'DESC']
            );
        }

        // 💙 Récupérer seulement les commentaires pour l'affichage (les compteurs sont dans Blog)
        $interactions = [];
        foreach ($blogs as $blog) {
            $interactions[$blog->getId()] = [
                'comments' => $interactionRepository->findCommentsByBlog($blog->getId()),
            ];
        }

        return $this->render('front/article/articles.html.twig', [
            'blogs' => $blogs,
            'interactions' => $interactions,
        ]);
    }

    // ✏️ CREATE - Créer un nouvel article
    #[Route('/new', name: 'app_blog_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger,
        BadWordDetector $badWordDetector
    ): Response {
        $blog = new Blog();
        $blog->setStatus('pending');
        $form = $this->createForm(BlogType::class, $blog);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Vérification des mots inappropriés dans le titre uniquement
            $badInTitle = $badWordDetector->findBadWords($blog->getTitre() ?? '');

            if (!empty($badInTitle)) {
                $this->addFlash('error', 'Titre contient des mots inappropriés : "' . implode('", "', $badInTitle) . '".');
                return $this->render('back/blog/new.html.twig', ['blog' => $blog, 'form' => $form]);
            }

            $blog->setCreatedAt(new \DateTimeImmutable());

            // Définir le publisher
            if ($this->getUser()) {
                $blog->setPublisher($this->getUser());
            } else {
                $firstUser = $entityManager->getRepository(\App\Entity\User::class)->findOneBy([]);
                if ($firstUser) {
                    $blog->setPublisher($firstUser);
                } else {
                    $this->addFlash('error', 'Erreur : Aucun utilisateur disponible.');
                    return $this->redirectToRoute('app_blog_index');
                }
            }

            // Si le statut est "published", définir publishedAt
            if ($blog->getStatus() === 'published') {
                $blog->setPublishedAt(new \DateTimeImmutable());
            }

            // Gestion des images
            $imageFiles = $form->get('images')->getData();
            if ($imageFiles) {
                $imageNames = [];
                foreach ($imageFiles as $imageFile) {
                    $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

                    try {
                        $imageFile->move(
                            $this->getParameter('images_directory'),
                            $newFilename
                        );
                        $imageNames[] = $newFilename;
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Erreur lors de l\'upload : ' . $e->getMessage());
                    }
                }
                $blog->setImages($imageNames);
            }

            $entityManager->persist($blog);
            $entityManager->flush();

            $this->addFlash('success', 'Votre article a été créé avec succès !');
            $this->addFlash('warning', 'Il est actuellement en attente de validation par un administrateur avant d\'être publié.');
            return $this->redirectToRoute('app_blog_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/blog/new.html.twig', [
            'blog' => $blog,
            'form' => $form,
        ]);
    }

    // 👁️ READ - Voir un article
    #[Route('/{id}', name: 'app_blog_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Blog $blog): Response
    {
        return $this->render('back/blog/show.html.twig', [
            'blog' => $blog,
        ]);
    }

    // ✏️ UPDATE - Modifier un article
    #[Route('/{id}/edit', name: 'app_blog_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        Blog $blog,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger
    ): Response {
        // Vérifier que l'utilisateur est le propriétaire
        if ($this->getUser() && $blog->getPublisher() !== $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas modifier cet article car vous n\'en êtes pas l\'auteur.');
            return $this->redirectToRoute('app_blog_index');
        }

        $form = $this->createForm(BlogType::class, $blog);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Si on passe de draft à published, définir publishedAt
            if ($blog->getStatus() === 'published' && !$blog->getPublishedAt()) {
                $blog->setPublishedAt(new \DateTimeImmutable());
            }

            // Gestion des nouvelles images
            $imageFiles = $form->get('images')->getData();
            if ($imageFiles) {
                $existingImages = $blog->getImages() ?? [];
                $imageNames = $existingImages;
                
                foreach ($imageFiles as $imageFile) {
                    $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

                    try {
                        $imageFile->move(
                            $this->getParameter('images_directory'),
                            $newFilename
                        );
                        $imageNames[] = $newFilename;
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Erreur lors de l\'upload des images : ' . $e->getMessage());
                    }
                }
                $blog->setImages($imageNames);
            }

            $entityManager->flush();

            $this->addFlash('success', '✅ Article modifié avec succès!');
            return $this->redirectToRoute('app_blog_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('back/blog/edit.html.twig', [
            'blog' => $blog,
            'form' => $form,
        ]);
    }

    // 🗑️ DELETE - Supprimer un article
    #[Route('/{id}/delete', name: 'app_blog_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Blog $blog, EntityManagerInterface $entityManager): Response
    {
        // Vérifier que l'utilisateur est le propriétaire
        if ($this->getUser() && $blog->getPublisher() !== $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer cet article car vous n\'en êtes pas l\'auteur.');
            return $this->redirectToRoute('app_blog_index');
        }

        if ($this->isCsrfTokenValid('delete'.$blog->getId(), $request->request->get('_token'))) {
            // Supprimer les images du serveur
            if ($blog->getImages()) {
                foreach ($blog->getImages() as $image) {
                    $imagePath = $this->getParameter('images_directory').'/'.$image;
                    if (file_exists($imagePath)) {
                        unlink($imagePath);
                    }
                }
            }

            $entityManager->remove($blog);
            $entityManager->flush();
            
            $this->addFlash('success', 'Article supprimé avec succès!');
        } else {
            $this->addFlash('error', 'Token CSRF invalide');
        }

        return $this->redirectToRoute('app_blog_index', [], Response::HTTP_SEE_OTHER);
    }

    // 🤖 Générer du contenu avec AI
    #[Route('/generate-ai', name: 'app_blog_generate_ai', methods: ['POST'])]
    public function generateAi(Request $request, AiContentGenerator $generator): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $title = $data['title'] ?? '';
        $category = $data['category'] ?? null;

        if (empty(trim($title))) {
            return $this->json(['error' => 'Veuillez saisir un titre avant de générer le contenu.'], 400);
        }

        try {
            $content = $generator->generate($title, $category);
            return $this->json(['content' => $content]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la génération : ' . $e->getMessage()], 500);
        }
    }

    // 📝 Corriger les fautes d'orthographe avec AI
    #[Route('/correct-spelling', name: 'app_blog_correct_spelling', methods: ['POST'])]
    public function correctSpelling(Request $request, SpellCheckerService $spellChecker): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $title = $data['title'] ?? '';
        $text = $data['text'] ?? '';

        if (empty(trim($title)) && empty(trim($text))) {
            return $this->json(['error' => 'Aucun texte à corriger.'], 400);
        }

        try {
            $titleResult = null;
            $contentResult = null;
            $allFixes = [];

            if (!empty(trim($title))) {
                $titleResult = $spellChecker->correct($title);
                if ($titleResult['hasChanges']) {
                    foreach ($titleResult['fixes'] as $fix) {
                        $allFixes[] = '[Titre] ' . $fix;
                    }
                }
            }

            if (!empty(trim($text))) {
                $contentResult = $spellChecker->correct($text);
                if ($contentResult['hasChanges']) {
                    foreach ($contentResult['fixes'] as $fix) {
                        $allFixes[] = '[Contenu] ' . $fix;
                    }
                }
            }

            return $this->json([
                'titleCorrected' => $titleResult ? $titleResult['corrected'] : $title,
                'contentCorrected' => $contentResult ? $contentResult['corrected'] : $text,
                'fixes' => $allFixes,
                'fixCount' => count($allFixes),
                'hasChanges' => count($allFixes) > 0,
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de la correction : ' . $e->getMessage()], 500);
        }
    }
}