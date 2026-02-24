<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Student;

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
    #[Route('/profile', name: 'app_profile_current', methods: ['GET'])]
    public function currentProfile(Request $request): Response
    {
        $session = $request->getSession();
        $user = $session->get('user');
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $section = (string) $request->query->get('section', '');
        $params = ['id' => $user['id']];
        if ($section !== '') {
            $params['section'] = $section;
        }

        return $this->redirectToRoute('app_profile', $params);
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
                /** @var UploadedFile|null $avatarFile */
                $avatarFile = $request->files->get('avatar');
                
                if ($firstName && $lastName) {
                    $user->setFullName($firstName . ' ' . $lastName);
                }

                if ($avatarFile instanceof UploadedFile && $avatarFile->isValid()) {
                    $maxBytes = 5 * 1024 * 1024;
                    if ($avatarFile->getSize() > $maxBytes) {
                        $this->addFlash('error', 'Profile image must be smaller than 5MB.');
                        return $this->redirectToRoute('app_profile', ['id' => $id]);
                    }

                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
                    if (!in_array((string) $avatarFile->getMimeType(), $allowedMimeTypes, true)) {
                        $this->addFlash('error', 'Only JPG, PNG, or WEBP images are allowed.');
                        return $this->redirectToRoute('app_profile', ['id' => $id]);
                    }

                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
                    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                        $this->addFlash('error', 'Could not create avatar upload directory.');
                        return $this->redirectToRoute('app_profile', ['id' => $id]);
                    }

                    $extension = $avatarFile->guessExtension() ?: 'jpg';
                    $fileName = sprintf('avatar_%d_%s.%s', $user->getId(), bin2hex(random_bytes(8)), $extension);
                    $avatarFile->move($uploadDir, $fileName);

                    $oldAvatar = $user->getAvatarPath();
                    if (is_string($oldAvatar) && str_starts_with($oldAvatar, '/uploads/avatars/')) {
                        $oldAvatarFile = $this->getParameter('kernel.project_dir') . '/public' . $oldAvatar;
                        if (is_file($oldAvatarFile)) {
                            @unlink($oldAvatarFile);
                        }
                    }

                    $user->setAvatarPath('/uploads/avatars/' . $fileName);
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

                $session = $request->getSession();
                $sessionData = (array) $session->get('user', []);
                $sessionData['fullName'] = $user->getFullName();
                $sessionData['avatarPath'] = $user->getAvatarPath();
                $session->set('user', $sessionData);
                
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
