<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Student;

final class FrontOfficeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
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
    #[Route('/profile', name: 'app_profile_current', methods: ['GET'])]
    public function currentProfile(Request $request): Response
    {
        $session = $request->getSession();
        $user = $session->get('user');
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }
        
        return $this->redirectToRoute('app_profile', ['id' => $user['id']]);
    }

    #[Route('/profile/{id}', name: 'app_profile', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function profile(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $sessionUser = $request->getSession()->get('user');
        if (!$sessionUser) {
            return $this->redirectToRoute('app_login');
        }

        $sessionUserId = (int) ($sessionUser['id'] ?? 0);
        $sessionRoles = (array) ($sessionUser['roles'] ?? []);
        $isAdmin = in_array('ROLE_ADMIN', $sessionRoles, true);

        if (!$isAdmin && $sessionUserId !== $id) {
            $this->addFlash('error', 'You are not allowed to edit this profile.');
            return $this->redirectToRoute('app_profile', ['id' => $sessionUserId]);
        }

        $user = $entityManager->getRepository(User::class)->find($id);
        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }
        
        $student = $user->getProfile();
        
        if ($request->isMethod('POST')) {
            // Handle profile update
            if (!$this->isCsrfTokenValid('profile_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token');
                return $this->redirectToRoute('app_profile', ['id' => $id]);
            }
            
            $formType = $request->request->get('_form');
            
            if ($formType === 'personal') {
                // Update user data
                $firstName = $request->request->get('firstName');
                $lastName = $request->request->get('lastName');
                $phone = $request->request->get('phone');
                $bio = $request->request->get('bio');
                
                if ($firstName && $lastName) {
                    $user->setFullName($firstName . ' ' . $lastName);
                }
                
                if ($student) {
                    if ($phone) {
                        $student->setPhone((int)$phone);
                    }
                    $student->setBio($bio);
                    $entityManager->persist($student);
                }
                
                $entityManager->persist($user);
                $entityManager->flush();
                
                $this->addFlash('success', 'Personal information updated successfully');
            } elseif ($formType === 'account') {
                // Account status values are managed by admins only.
                $this->addFlash('info', 'Account status is managed by the administration team.');
            } elseif ($formType === 'certifications') {
                // Update certifications
                $certificationsStr = $request->request->get('certifications');
                $certifications = [];
                if ($certificationsStr) {
                    $certifications = array_map('trim', explode(',', $certificationsStr));
                    $certifications = array_filter($certifications); // Remove empty
                }
                
                if ($student) {
                    $student->setCertifications($certifications);
                    $entityManager->persist($student);
                    $entityManager->flush();
                }
                
                $this->addFlash('success', 'Certifications updated successfully');
            }
            
            return $this->redirectToRoute('app_profile', ['id' => $id]);
        }
        
        return $this->render('front/profile/profile.html.twig', [
            'user' => $user,
            'student' => $student,
        ]);
    }
}
