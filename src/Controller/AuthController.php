<?php

namespace App\Controller;

use App\Entity\Student;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class AuthController extends AbstractController
{
   #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(
        Request $request,
        AuthenticationUtils $authenticationUtils,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
        EventDispatcherInterface $eventDispatcher
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $email = trim((string)$request->request->get('email'));
            $password = (string)$request->request->get('password');

            if (empty($email) || empty($password)) {
                return new JsonResponse(['success' => false, 'message' => 'Email and password are required'], Response::HTTP_BAD_REQUEST);
            }

            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid email or password'], Response::HTTP_UNAUTHORIZED);
            }

            // Optional: check status flag if your app requires it
            if (method_exists($user, 'isStatus') && $user->isStatus() === false) {
                return new JsonResponse(['success' => false, 'message' => 'Your account is not active. Contact support.'], Response::HTTP_FORBIDDEN);
            }

            // Connexion via le système de sécurité Symfony (requis pour Security::getUser(), firewall, ROLE_USER, etc.)
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            $tokenStorage->setToken($token);
            $request->getSession()->set('_security_main', serialize($token));
            $eventDispatcher->dispatch(new InteractiveLoginEvent($request, $token));

            $userData = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'fullName' => method_exists($user, 'getFullName') ? $user->getFullName() : null,
                'roles' => $user->getRoles(),
            ];

            return new JsonResponse(['success' => true, 'redirect' => $this->generateUrl('app_home'), 'user' => $userData]);
        }

        // GET request - render the login form
        return $this->render('front/auth/login.html.twig', [
            'error' => null,
            'last_email' => null,
        ]);
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger
    ): Response {
       

        // Handle POST request (form submission)
        if ($request->isMethod('POST')) {
            $fullName = $request->request->get('fullName');
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirmPassword');
            $role = $request->request->get('role', 'student');

            $logger->info('Registration attempt', [
                'email' => $email,
                'fullName' => $fullName,
                'role' => $role
            ]);

            // Validation
            $errors = [];

            if (empty($fullName)) {
                $errors['fullName'] = 'Full name is required';
            } elseif (strlen($fullName) < 3) {
                $errors['fullName'] = 'Full name must be at least 3 characters';
            }

            if (empty($email)) {
                $errors['email'] = 'Email is required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Please enter a valid email address';
            }

            // Check if email already exists
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                $errors['email'] = 'This email is already registered';
            }

            if (empty($password)) {
                $errors['password'] = 'Password is required';
            } elseif (strlen($password) < 6) {
                $errors['password'] = 'Password must be at least 6 characters';
            }

            if ($password !== $confirmPassword) {
                $errors['confirmPassword'] = 'Passwords do not match';
            }

            if (!in_array($role, ['student', 'tutor'])) {
                $errors['role'] = 'Invalid role selected';
            }

            // Return errors if validation fails
            if (!empty($errors)) {
                $logger->warning('Validation failed for registration', $errors);
                return new JsonResponse([
                    'success' => false,
                    'errors' => $errors
                ], Response::HTTP_BAD_REQUEST);
            }

            try {
                // Create new user
                $user = new User();
                $user->setEmail($email);
                $user->setFullName($fullName);
                $user->setRoles($role === 'tutor' ? ['ROLE_TUTOR'] : ['ROLE_ETUDIANT']);
                $user->setStatus(false); // Not active until email verification
                $user->setCreatedAt(new \DateTimeImmutable());

                // Hash password
                $hashedPassword = $passwordHasher->hashPassword($user, $password);
                $user->setPassword($hashedPassword);

                // Create student profile
                $student = new Student();
                $student->setUserId($user);
                $student->setRoles($role);
                $student->setIsActive(false);
                $student->setValidationStatus('pending');
                $student->setPhone(0);

                // Persist entities
                $entityManager->persist($user);
                $entityManager->persist($student);
                $entityManager->flush();

                $logger->info('User registered successfully', [
                    'userId' => $user->getId(),
                    'email' => $email,
                    'role' => $role
                ]);

                // Do NOT auto-login the newly registered user; require manual login
                if ($request->getAcceptableContentTypes() && in_array('application/json', $request->getAcceptableContentTypes())) {
                    return new JsonResponse([
                        'success' => true,
                        'message' => 'Registration successful',
                        'redirect' => $this->generateUrl('app_login')
                    ]);
                }

                return $this->redirectToRoute('app_login');
            } catch (\Exception $e) {
                $logger->error('Registration error: ' . $e->getMessage(), [
                    'exception' => $e,
                    'email' => $email,
                    'trace' => $e->getTraceAsString()
                ]);
                
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                    'errors' => ['general' => 'Failed to create account: ' . $e->getMessage()]
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return $this->render('front/auth/register.html.twig', [
            'controller_name' => 'AuthController',
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(Request $request, TokenStorageInterface $tokenStorage): Response
    {
        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('app_login');
    }

    #[Route('/api/check-email', name: 'api_check_email', methods: ['POST'])]
    public function checkEmailExists(
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $email = $request->request->get('email');

        if (empty($email)) {
            return new JsonResponse([
                'exists' => false
            ]);
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        return new JsonResponse([
            'exists' => $user !== null
        ]);
    }
}
