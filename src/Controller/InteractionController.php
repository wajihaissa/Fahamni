<?php

namespace App\Controller;

use App\Entity\Blog;
use App\Entity\Interaction;
use App\Service\BadWordDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/interaction')]
class InteractionController extends AbstractController
{
    // 👍 Ajouter/Retirer un Like
    #[Route('/like/{id}', name: 'app_interaction_like', methods: ['POST'])]
    public function like(Request $request, Blog $blog, EntityManagerInterface $entityManager): Response
    {
        // 🔐 Vérification du token CSRF
        if (!$this->isCsrfTokenValid('like'.$blog->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide');
            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }
            return $this->redirectToRoute('app_blog_index');
        }

        // 🧪 MODE TEST : Utiliser le premier utilisateur si personne n'est connecté
        $user = $this->getUser();
        if (!$user) {
            $user = $entityManager->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        // Vérifier si l'utilisateur a déjà liké
        $existingLike = $entityManager->getRepository(Interaction::class)
            ->findOneBy([
                'blog' => $blog,
                'innteractor' => $user,
                'reaction' => 1 // 1 = like
            ]);

        if ($existingLike) {
            // Retirer le like
            $entityManager->remove($existingLike);
            $blog->decrementLikesCount(); // 📉 Décrémenter le compteur
            $message = 'Like retiré';
        } else {
            // Ajouter le like
            $interaction = new Interaction();
            $interaction->setBlog($blog);
            $interaction->setInnteractor($user);
            $interaction->setReaction(1); // 1 = like
            $interaction->setCreatedAt(new \DateTimeImmutable());
            $interaction->setIsNotifRead(false);

            $entityManager->persist($interaction);
            $blog->incrementLikesCount();
            $message = 'Like ajouté';
        }

        $entityManager->flush();

        // Réponse AJAX
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'liked' => !$existingLike,
                'likesCount' => $blog->getLikesCount(),
                'message' => $message,
            ]);
        }

        $this->addFlash('success', $message);

        // 🔄 Rediriger vers la page d'origine (tuteur ou étudiant)
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }
        return $this->redirectToRoute('app_blog_index');
    }

    // 💬 Ajouter un commentaire
    #[Route('/comment/{id}', name: 'app_interaction_comment', methods: ['POST'])]
    public function comment(Request $request, Blog $blog, EntityManagerInterface $entityManager, BadWordDetector $badWordDetector): Response
    {
        // 🔐 Vérification du token CSRF
        if (!$this->isCsrfTokenValid('comment'.$blog->getId(), $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Token CSRF invalide'], 403);
            }
            $this->addFlash('error', 'Token CSRF invalide');
            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }
            return $this->redirectToRoute('app_blog_index');
        }

        $commentText = $request->request->get('comment');

        if (empty(trim($commentText))) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Le commentaire ne peut pas être vide'], 400);
            }
            $this->addFlash('error', 'Le commentaire ne peut pas être vide');
            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }
            return $this->redirectToRoute('app_blog_show', ['id' => $blog->getId()]);
        }

        // 🧪 MODE TEST : Utiliser le premier utilisateur si personne n'est connecté
        $user = $this->getUser();
        if (!$user) {
            $user = $entityManager->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        // 🚫 Vérification des mots interdits — sauvegarder comme signalé pour l'admin
        $badWords = $badWordDetector->findBadWords($commentText);
        if (!empty($badWords)) {
            // Sauvegarder le commentaire flaggé (visible par l'admin, pas par le public)
            $flagged = new Interaction();
            $flagged->setBlog($blog);
            $flagged->setInnteractor($user);
            $flagged->setComment($commentText);
            $flagged->setCreatedAt(new \DateTimeImmutable());
            $flagged->setIsNotifRead(false);
            $flagged->setIsFlagged(true);
            $entityManager->persist($flagged);
            $entityManager->flush();

            $message = 'Commentaire refusé : contient des mots inappropriés.';
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $message], 400);
            }
            $this->addFlash('error', $message);
            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }
            return $this->redirectToRoute('app_blog_index');
        }

        $interaction = new Interaction();
        $interaction->setBlog($blog);
        $interaction->setInnteractor($user);
        $interaction->setComment($commentText);
        $interaction->setCreatedAt(new \DateTimeImmutable());
        $interaction->setIsNotifRead(false);

        $entityManager->persist($interaction);
        $blog->incrementCommentsCount();
        $entityManager->flush();

        // Réponse AJAX
        if ($request->isXmlHttpRequest()) {
            $authorName = $user->getFullName() ?: explode('@', $user->getEmail())[0];
            $authorInitials = mb_strtoupper(mb_substr($user->getFullName() ?: $user->getEmail(), 0, 2));
            return new JsonResponse([
                'success' => true,
                'commentsCount' => $blog->getCommentsCount(),
                'comment' => [
                    'id' => $interaction->getId(),
                    'text' => $interaction->getComment(),
                    'author' => $authorName,
                    'initials' => $authorInitials,
                    'date' => $interaction->getCreatedAt()->format('d/m/Y H:i'),
                    'isFlagged' => $interaction->isFlagged(),
                ],
            ]);
        }

        $this->addFlash('success', 'Commentaire ajouté avec succès!');

        // 🔄 Rediriger vers la page d'origine (tuteur ou étudiant)
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }
        return $this->redirectToRoute('app_blog_index');
    }

    // 🗑️ Supprimer un commentaire
    #[Route('/comment/{id}/delete', name: 'app_interaction_delete', methods: ['POST'])]
    public function deleteComment(Request $request, Interaction $interaction, EntityManagerInterface $entityManager): Response
    {
        // 🧪 MODE TEST : Vérifier que l'utilisateur est le propriétaire du commentaire
        $user = $this->getUser();
        if (!$user) {
            $user = $entityManager->getRepository(\App\Entity\User::class)->findOneBy([]);
        }

        if ($interaction->getInnteractor() !== $user) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer ce commentaire car vous n\'en êtes pas l\'auteur.');
            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }
            return $this->redirectToRoute('app_blog_index');
        }

        if ($this->isCsrfTokenValid('delete'.$interaction->getId(), $request->request->get('_token'))) {
            $blog = $interaction->getBlog();
            $entityManager->remove($interaction);
            $blog->decrementCommentsCount(); // 📉 Décrémenter le compteur de commentaires
            $entityManager->flush();

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => true,
                    'commentsCount' => $blog->getCommentsCount(),
                ]);
            }

            $this->addFlash('success', 'Commentaire supprimé');

            // 🔄 Rediriger vers la page d'origine (tuteur ou étudiant)
            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }
            return $this->redirectToRoute('app_blog_index');
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'message' => 'Token CSRF invalide'], 403);
        }

        // 🔄 Rediriger vers la page d'origine si le token est invalide
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }
        return $this->redirectToRoute('app_blog_index');
    }
}