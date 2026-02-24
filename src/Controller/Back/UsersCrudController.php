<?php

namespace App\Controller\Back;

use App\Entity\User;
use App\Entity\Student;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin/users', name: 'admin_users_')]
final class UsersCrudController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $users = $entityManager->getRepository(User::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return $this->render('back/users/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($request->isMethod('POST')) {
            // Validate CSRF token
            if (!$this->isCsrfTokenValid('add_user', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token');
                return $this->redirectToRoute('admin_users_index');
            }
            
            $fullName = $request->request->get('fullName');
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirmPassword');
            $rolesInput = $request->request->get('roles');
            $roles = $rolesInput ? (is_array($rolesInput) ? $rolesInput : [$rolesInput]) : ['ROLE_ETUDIANT'];

            $errors = [];

            if (empty($fullName)) {
                $errors[] = 'Full name is required';
            }

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Valid email is required';
            }

            if (empty($password) || strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters';
            }

            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match';
            }

            if (!empty($email)) {
                $existingUser = $entityManager->getRepository(User::class)
                    ->findOneBy(['email' => $email]);
                if ($existingUser) {
                    $errors[] = 'This email is already registered';
                }
            }

            if (!empty($errors)) {
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
                return $this->redirectToRoute('admin_users_index');
            }
        }

        return $this->render('back/users/create.html.twig');
    }

    #[Route('/{id}/show', name: 'show', methods: ['GET'])]
    public function show(User $user): Response
    {
        $student = $user->getProfile();

        return $this->render('back/users/show.html.twig', [
            'user' => $user,
            'student' => $student,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($request->isMethod('POST')) {
            // Validate CSRF token
            if (!$this->isCsrfTokenValid('edit_user_' . $user->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token');
                return $this->redirectToRoute('admin_users_index');
            }
            
            $fullName = $request->request->get('fullName');
            $email = $request->request->get('email');
            $status = $request->request->get('status') === '1';
            $rolesInput = $request->request->get('roles');
            $roles = $rolesInput ? (is_array($rolesInput) ? $rolesInput : [$rolesInput]) : [];
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
                return $this->redirectToRoute('admin_users_index');
            }
        }

        $student = $user->getProfile();

        return $this->render('back/users/edit.html.twig', [
            'user' => $user,
            'student' => $student,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            // Delete related student profile first
            if ($user->getProfile()) {
                $entityManager->remove($user->getProfile());
            }

            $entityManager->remove($user);
            $entityManager->flush();

            $this->addFlash('success', 'User deleted successfully!');
        }

        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/toggle-status', name: 'toggle_status', methods: ['POST'])]
    public function toggleStatus(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid('toggle' . $user->getId(), $request->request->get('_token'))) {
            $user->setStatus(!$user->isStatus());
            $entityManager->persist($user);
            $entityManager->flush();

            $status = $user->isStatus() ? 'activated' : 'deactivated';
            $this->addFlash('success', "User {$status} successfully!");
        }

        return $this->redirectToRoute('admin_users_index');
    }
}
