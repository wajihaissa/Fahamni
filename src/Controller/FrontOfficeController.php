<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Student;
use App\Entity\QuizResult;
use App\Service\KeywordQuizProvisioner;

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
    public function profile(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        KeywordQuizProvisioner $keywordQuizProvisioner
    ): Response
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
                $certificationsStr = (string) $request->request->get('certifications', '');
                $requestedKeywords = $keywordQuizProvisioner->parseKeywordsFromInput($certificationsStr);

                if ($student) {
                    $createdCount = 0;
                    $reusedCount = 0;
                    $failedKeywords = [];

                    foreach ($requestedKeywords as $keyword) {
                        try {
                            $result = $keywordQuizProvisioner->ensureQuizForKeyword($keyword);
                            if ($result['created']) {
                                $createdCount++;
                            } else {
                                $reusedCount++;
                            }
                        } catch (\Throwable) {
                            $failedKeywords[] = $keyword;
                        }
                    }

                    $passedKeywordsRows = $entityManager->createQueryBuilder()
                        ->select('DISTINCT LOWER(q.keyword) AS keyword')
                        ->from(QuizResult::class, 'qr')
                        ->join('qr.quiz', 'q')
                        ->where('qr.user = :user')
                        ->andWhere('qr.passed = :passed')
                        ->andWhere('q.keyword IS NOT NULL')
                        ->setParameter('user', $user)
                        ->setParameter('passed', true)
                        ->getQuery()
                        ->getArrayResult();

                    $passedKeywords = array_map(
                        static fn (array $row): string => (string) ($row['keyword'] ?? ''),
                        $passedKeywordsRows
                    );
                    $passedKeywords = $keywordQuizProvisioner->normalizeKeywords($passedKeywords);
                    $validatedKeywords = array_values(array_intersect($requestedKeywords, $passedKeywords));
                    $pendingKeywords = array_values(array_diff($requestedKeywords, $validatedKeywords));

                    $student->setCertifications($validatedKeywords);
                    $student->setCertificationKeywords($pendingKeywords);
                    $entityManager->persist($student);
                    $entityManager->flush();

                    if ($failedKeywords !== []) {
                        $this->addFlash('error', 'Could not generate quizzes for: ' . implode(', ', $failedKeywords));
                    }
                    $this->addFlash(
                        'success',
                        sprintf(
                            'Certifications updated. %d keyword(s) reused, %d new quiz(es) generated.',
                            $reusedCount,
                            $createdCount
                        )
                    );

                    if ($pendingKeywords !== []) {
                        return $this->redirectToRoute('app_quiz_list', ['skills' => 1]);
                    }
                }
            }
            
            return $this->redirectToRoute('app_profile', ['id' => $id]);
        }
        
        $validatedKeywords = $keywordQuizProvisioner->normalizeKeywords((array) ($student?->getCertifications() ?? []));
        $pendingKeywords = $keywordQuizProvisioner->normalizeKeywords((array) ($student?->getCertificationKeywords() ?? []));
        $certificationKeywords = array_values(array_unique(array_merge($validatedKeywords, $pendingKeywords)));
        $certificationInput = implode(', ', $certificationKeywords);

        return $this->render('front/profile/profile.html.twig', [
            'user' => $user,
            'student' => $student,
            'validatedKeywords' => $validatedKeywords,
            'pendingKeywords' => $pendingKeywords,
            'certificationInput' => $certificationInput,
        ]);
    }
}
