<?php

namespace App\Controller\Back;

use App\Entity\User;
use App\Entity\Student;
use App\Service\RegistrationFraudScoringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/users', name: 'admin_users_')]
#[IsGranted('ROLE_ADMIN')]
final class UsersCrudController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(): Response
    {
        return $this->redirectToDashboardUsers();
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->redirectToDashboardUsers();
        }

        if ($request->isMethod('POST')) {
            // Validate CSRF token
            if (!$this->isCsrfTokenValid('add_user', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token');
                return $this->redirectToDashboardUsers();
            }
            
            $fullName = trim((string) $request->request->get('fullName', ''));
            $email = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirmPassword', '');
            $rolesInput = $request->request->all('roles');
            $roleInput = is_array($rolesInput) && !empty($rolesInput) ? (string) $rolesInput[0] : (string) $request->request->get('role');
            $allowedRoles = ['ROLE_ETUDIANT', 'ROLE_TUTOR', 'ROLE_ADMIN'];
            $roles = in_array($roleInput, $allowedRoles, true) ? [$roleInput] : ['ROLE_ETUDIANT'];

            $errors = [];
            $inputData = [
                'fullName' => $fullName,
                'email' => $email,
                'password' => $password,
                'confirmPassword' => $confirmPassword,
                'role' => $roleInput,
            ];

            $inputViolations = $validator->validate($inputData, new Assert\Collection([
                'fullName' => new Assert\Sequentially([
                    new Assert\NotBlank(message: 'Full name is required'),
                    new Assert\Length(min: 3, minMessage: 'Full name must be at least 3 characters'),
                    new Assert\Regex(
                        pattern: '/^[\p{L}\s\'\-]+$/u',
                        message: 'Name can only contain letters, spaces, apostrophes and hyphens'
                    ),
                ]),
                'email' => new Assert\Sequentially([
                    new Assert\NotBlank(message: 'Valid email is required'),
                    new Assert\Email(message: 'Valid email is required'),
                    new Assert\Length(max: 180, maxMessage: 'Email is too long'),
                ]),
                'password' => new Assert\Sequentially([
                    new Assert\NotBlank(message: 'Password is required'),
                    new Assert\Length(min: 6, minMessage: 'Password must be at least 6 characters'),
                ]),
                'confirmPassword' => new Assert\Sequentially([
                    new Assert\NotBlank(message: 'Please confirm the password'),
                ]),
                'role' => new Assert\Sequentially([
                    new Assert\Choice(choices: $allowedRoles, message: 'Invalid role selected'),
                ]),
            ]));
            $inputViolations->addAll($validator->validate($inputData, new Assert\Callback(
                function (array $data, ExecutionContextInterface $context) use ($entityManager): void {
                    if (($data['password'] ?? '') !== ($data['confirmPassword'] ?? '')) {
                        $context->buildViolation('Passwords do not match')
                            ->atPath('[confirmPassword]')
                            ->addViolation();
                    }

                    $email = trim((string) ($data['email'] ?? ''));
                    if ($email === '') {
                        return;
                    }

                    $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
                    if ($existingUser instanceof User) {
                        $context->buildViolation('This email is already registered')
                            ->atPath('[email]')
                            ->addViolation();
                    }
                }
            )));

            foreach ($inputViolations as $violation) {
                $errors[] = $violation->getMessage();
            }

            if (!empty($errors)) {
                $errors = array_values(array_unique($errors));
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            } else {
                $user = new User();
                $user->setFullName($fullName);
                $user->setEmail($email);
                $user->setRoles($roles);
                $user->setStatus(true);
                $user->setCreatedAt(new \DateTimeImmutable());

                $hashedPassword = $passwordHasher->hashPassword($user, $password);
                $user->setPassword($hashedPassword);

                // Create student profile
                $student = new Student();
                $student->setUserId($user);
                $student->setRoles(in_array('ROLE_TUTOR', $roles) ? 'tutor' : 'student');
                $student->setIsActive(true);
                $student->setValidationStatus('approved');
                $student->setPhone(0);

                $entityManager->persist($user);
                $entityManager->persist($student);
                $entityManager->flush();

                $this->addFlash('success', 'User created successfully!');
                $returnTo = $request->request->get('return_to');
                if (!empty($returnTo) && is_string($returnTo)) {
                    try {
                        return $this->redirectToRoute($returnTo);
                    } catch (\Exception $e) {
                        // fallback if route doesn't exist
                    }
                }

                return $this->redirectToDashboardUsers();
            }
        }

        return $this->redirectToDashboardUsers();
    }

    #[Route('/{id}/show', name: 'show', methods: ['GET'])]
    public function show(): Response
    {
        return $this->redirectToDashboardUsers();
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->redirectToDashboardUsers();
        }

        if ($request->isMethod('POST')) {
            // Validate CSRF token
            if (!$this->isCsrfTokenValid('edit_user_' . $user->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token');
                return $this->redirectToDashboardUsers();
            }
            
            $fullName = $request->request->get('fullName');
            $email = $request->request->get('email');
            $status = $request->request->get('status') === '1';
            $rolesInput = $request->request->get('roles');
            $roles = $rolesInput ? (is_array($rolesInput) ? $rolesInput : [$rolesInput]) : [];
            $allowedRoles = ['ROLE_ETUDIANT', 'ROLE_TUTOR', 'ROLE_ADMIN'];
            $roles = array_values(array_intersect($roles, $allowedRoles));
            $password = $request->request->get('password');

            // Validation
            $errors = [];
            if (empty($fullName)) {
                $errors[] = 'Full name is required';
            }

            if (!empty($password) && strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters';
            }

            // Check email uniqueness (if changed)
            if ($email !== $user->getEmail()) {
                $existingUser = $entityManager->getRepository(User::class)
                    ->findOneBy(['email' => $email]);
                if ($existingUser) {
                    $errors[] = 'This email is already in use';
                }
            }

            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            } else {
                $user->setFullName($fullName);
                $user->setEmail($email);
                $user->setStatus($status);
                if (!empty($roles)) {
                    $user->setRoles($roles);
                }
                if (!empty($password)) {
                    $hashedPassword = $passwordHasher->hashPassword($user, $password);
                    $user->setPassword($hashedPassword);
                }

                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'User updated successfully!');
                return $this->redirectToDashboardUsers();
            }
        }

        return $this->redirectToDashboardUsers();
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            if ($this->getUser() instanceof User && $this->getUser()->getId() === $user->getId()) {
                $this->addFlash('error', 'You cannot delete your own account.');
                return $this->redirectToDashboardUsers();
            }

            // Delete related student profile first
            if ($user->getProfile()) {
                $entityManager->remove($user->getProfile());
            }

                $entityManager->remove($user);
                $entityManager->flush();

            $this->addFlash('success', 'User deleted successfully!');
        }

        return $this->redirectToDashboardUsers();
    }

    #[Route('/{id}/toggle-status', name: 'toggle_status', methods: ['POST'])]
    public function toggleStatus(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager,
        RegistrationFraudScoringService $registrationFraudScoringService
    ): Response {
        $token = (string) $request->request->get('_token');
        if ($this->isCsrfTokenValid('toggle_status_' . $user->getId(), $token) || $this->isCsrfTokenValid('toggle' . $user->getId(), $token)) {
            if ($this->getUser() instanceof User && $this->getUser()->getId() === $user->getId() && $user->isStatus() === true) {
                $this->addFlash('error', 'You cannot deactivate your own account.');
                return $this->redirectToDashboardUsers();
            }

            $newStatus = !$user->isStatus();
            if ($newStatus === true) {
                $fraud = $registrationFraudScoringService->score($user, $user->getProfile());
                if ((int) ($fraud['score'] ?? 0) >= 50) {
                    $this->addFlash('error', sprintf(
                        'Activation blocked: fraud score is %d/100 (must be below 50).',
                        (int) $fraud['score']
                    ));
                    return $this->redirectToDashboardUsers();
                }
            }
            $user->setStatus($newStatus);

            $profile = $user->getProfile();
            if ($profile instanceof Student) {
                if ($newStatus === false) {
                    $profile->setIsActive(false);
                    if ($profile->getValidationStatus() === 'approved' || $profile->getValidationStatus() === null) {
                        $profile->setValidationStatus('suspended');
                    }
                } elseif ($profile->getValidationStatus() === 'suspended') {
                    $profile->setValidationStatus('approved');
                }
                $entityManager->persist($profile);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            $status = $newStatus ? 'activated' : 'deactivated';
            $this->addFlash('success', "Account {$status} successfully.");
        }

        return $this->redirectToDashboardUsers();
    }

    #[Route('/{id}/toggle-activity', name: 'toggle_activity', methods: ['POST'])]
    public function toggleActivity(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager
    ): Response {
        $profile = $user->getProfile();
        if (!$profile instanceof Student) {
            $this->addFlash('error', 'This user has no profile activity state.');
            return $this->redirectToDashboardUsers();
        }

        if (!$this->isCsrfTokenValid('toggle_activity_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToDashboardUsers();
        }

        if (!$user->isStatus()) {
            $this->addFlash('error', 'Cannot activate activity while account status is inactive.');
            return $this->redirectToDashboardUsers();
        }

        $newActivity = !$profile->isActive();
        $profile->setIsActive($newActivity);
        if ($newActivity) {
            if ($profile->getValidationStatus() === null || $profile->getValidationStatus() === 'rejected' || $profile->getValidationStatus() === 'suspended') {
                $profile->setValidationStatus('approved');
            }
        } else {
            if ($profile->getValidationStatus() === 'approved' || $profile->getValidationStatus() === null) {
                $profile->setValidationStatus('inactive');
            }
        }

        $entityManager->persist($profile);
        $entityManager->flush();

        $state = $newActivity ? 'active' : 'inactive';
        $this->addFlash('success', "Profile activity set to {$state}.");

        return $this->redirectToDashboardUsers();
    }

    #[Route('/bulk-status', name: 'bulk_status', methods: ['POST'])]
    public function bulkStatus(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid('bulk_users_status', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToDashboardUsers();
        }

        $action = (string) $request->request->get('bulk_action', '');
        $ids = $request->request->all('user_ids');
        $userIds = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
        if (empty($userIds)) {
            $this->addFlash('error', 'Select at least one user.');
            return $this->redirectToDashboardUsers();
        }

        if (!in_array($action, ['activate', 'deactivate'], true)) {
            $this->addFlash('error', 'Invalid bulk action.');
            return $this->redirectToDashboardUsers();
        }

        $users = $entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->leftJoin('u.profile', 'p')->addSelect('p')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getResult();

        $currentUserId = $this->getUser() instanceof User ? $this->getUser()->getId() : null;
        $changed = 0;
        $skipped = 0;

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if ($action === 'deactivate' && $currentUserId !== null && $currentUserId === $user->getId()) {
                $skipped++;
                continue;
            }

            $targetStatus = $action === 'activate';
            if ($user->isStatus() === $targetStatus) {
                $skipped++;
                continue;
            }

            $user->setStatus($targetStatus);

            $profile = $user->getProfile();
            if ($profile instanceof Student) {
                if ($targetStatus) {
                    if ($profile->getValidationStatus() === 'suspended') {
                        $profile->setValidationStatus('approved');
                    }
                } else {
                    $profile->setIsActive(false);
                    if ($profile->getValidationStatus() === 'approved' || $profile->getValidationStatus() === null) {
                        $profile->setValidationStatus('suspended');
                    }
                }
                $entityManager->persist($profile);
            }

            $entityManager->persist($user);
            $changed++;
        }

        $entityManager->flush();

        if ($changed > 0) {
            $this->addFlash('success', sprintf('Bulk action completed: %d user(s) updated.', $changed));
        }
        if ($skipped > 0) {
            $this->addFlash('error', sprintf('%d user(s) skipped (already in target state or protected).', $skipped));
        }

        return $this->redirectToDashboardUsers();
    }

    #[Route('/{id}/accept', name: 'accept', methods: ['POST'])]
    public function acceptRegistration(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager,
        RegistrationFraudScoringService $registrationFraudScoringService
    ): Response
    {
        if (!$this->isCsrfTokenValid('accept' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token');
            return $this->redirectToRoute('admin_dashboard');
        }

        $fraud = $registrationFraudScoringService->score($user, $user->getProfile());
        if ((int) ($fraud['score'] ?? 0) >= 50) {
            $this->addFlash('error', sprintf(
                'Acceptance blocked: fraud score is %d/100 (must be below 50).',
                (int) $fraud['score']
            ));
            return $this->redirectToRoute('admin_dashboard');
        }

        $user->setStatus(true);
        if ($user->getProfile()) {
            $student = $user->getProfile();
            $student->setIsActive(true);
            $student->setValidationStatus('approved');
            $entityManager->persist($student);
        }

        $entityManager->persist($user);
        $entityManager->flush();

        $this->addFlash('success', 'Registration accepted. User activated.');
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/{id}/decline', name: 'decline', methods: ['POST'])]
    public function declineRegistration(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('decline' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token');
            return $this->redirectToRoute('admin_dashboard');
        }

        // Keep user status false; mark student as rejected if exists
        if ($user->getProfile()) {
            $student = $user->getProfile();
            $student->setIsActive(false);
            $student->setValidationStatus('rejected');
            $entityManager->persist($student);
        }

        $entityManager->persist($user);
        $entityManager->flush();

        $this->addFlash('success', 'Registration declined.');
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/{id}/move-to-pending', name: 'move_to_pending', methods: ['POST'])]
    public function moveToPendingReview(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('move_to_pending' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token');
            return $this->redirectToDashboardUsers();
        }

        $user->setStatus(false);
        if ($user->getProfile()) {
            $student = $user->getProfile();
            $student->setIsActive(false);
            $student->setValidationStatus('pending');
            $entityManager->persist($student);
        }

        $entityManager->persist($user);
        $entityManager->flush();

        $this->addFlash('success', 'User moved to pending review queue.');

        return $this->redirectToDashboardUsers();
    }

    private function redirectToDashboardUsers(): RedirectResponse
    {
        return $this->redirect($this->generateUrl('admin_dashboard') . '#users');
    }

}
