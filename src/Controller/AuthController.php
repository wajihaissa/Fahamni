<?php

namespace App\Controller;

use App\Entity\Student;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class AuthController extends AbstractController
{
    private const RESET_SESSION_PREFIX = 'forgot_password_';
    private const RESET_CODE_TTL_SECONDS = 900;
    private const PASSKEY_REGISTER_CHALLENGE = 'passkey_register_challenge';
    private const PASSKEY_LOGIN_CHALLENGE = 'passkey_login_challenge';
    private const PASSKEY_LOGIN_USER_ID = 'passkey_login_user_id';
    private const PASSKEY_RP_ID = 'localhost';

   #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(
        Request $request,
        AuthenticationUtils $authenticationUtils,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage
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

            $userData = $this->createLoginSession($request, $user, $tokenStorage);

            return new JsonResponse(['success' => true, 'redirect' => $this->generateUrl('app_home'), 'user' => $userData]);
        }

        // GET request - render the login form
        return $this->render('front/auth/login.html.twig', [
            'error' => null,
            'last_email' => null,
        ]);
    }

    #[Route('/login-check', name: 'app_login_check', methods: ['POST'])]
    public function loginCheck(): void
    {
        throw new \LogicException('This method should never be reached directly.');
    }

    #[Route('/passkey/register/options', name: 'app_passkey_register_options', methods: ['POST'])]
    public function passkeyRegisterOptions(Request $request): JsonResponse
    {
        $sessionUser = $request->getSession()->get('user');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            return new JsonResponse(['success' => false, 'message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $challenge = $this->base64UrlEncode(random_bytes(32));
        $request->getSession()->set(self::PASSKEY_REGISTER_CHALLENGE, $challenge);

        return new JsonResponse([
            'success' => true,
            'publicKey' => [
                'challenge' => $challenge,
                'rp' => [
                    'name' => 'FAHIMNI',
                    'id' => self::PASSKEY_RP_ID,
                ],
                'user' => [
                    'id' => $this->base64UrlEncode((string) $sessionUser['id']),
                    'name' => (string) ($sessionUser['email'] ?? ''),
                    'displayName' => (string) ($sessionUser['fullName'] ?? $sessionUser['email'] ?? 'User'),
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],   // ES256
                    ['type' => 'public-key', 'alg' => -257], // RS256
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'authenticatorSelection' => [
                    'authenticatorAttachment' => 'platform',
                    'residentKey' => 'preferred',
                    'userVerification' => 'required',
                ],
            ],
        ]);
    }

    #[Route('/passkey/register/verify', name: 'app_passkey_register_verify', methods: ['POST'])]
    public function passkeyRegisterVerify(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $session = $request->getSession();
        $sessionUser = $session->get('user');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            return new JsonResponse(['success' => false, 'message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $storedChallenge = (string) $session->get(self::PASSKEY_REGISTER_CHALLENGE, '');
        if ($storedChallenge === '') {
            return new JsonResponse(['success' => false, 'message' => 'No passkey challenge found'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $credentialId = (string) ($payload['id'] ?? '');
        $publicKeyPem = (string) ($payload['publicKeyPem'] ?? '');
        $clientDataBase64 = (string) ($payload['clientDataJSON'] ?? '');

        if ($credentialId === '' || $publicKeyPem === '' || $clientDataBase64 === '') {
            return new JsonResponse(['success' => false, 'message' => 'Incomplete passkey registration data'], Response::HTTP_BAD_REQUEST);
        }

        $clientDataJson = $this->base64UrlDecode($clientDataBase64);
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid client data'], Response::HTTP_BAD_REQUEST);
        }

        $incomingChallenge = (string) ($clientData['challenge'] ?? '');
        $origin = (string) ($clientData['origin'] ?? '');
        if (!$this->isAllowedPasskeyOrigin($origin, $request)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid passkey origin'], Response::HTTP_BAD_REQUEST);
        }
        $type = (string) ($clientData['type'] ?? '');
        if ($type !== 'webauthn.create' || !hash_equals($storedChallenge, $incomingChallenge)) {
            return new JsonResponse(['success' => false, 'message' => 'Passkey challenge mismatch'], Response::HTTP_BAD_REQUEST);
        }

        $user = $entityManager->getRepository(User::class)->find((int) $sessionUser['id']);
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $user->setPasskeyCredentialId($credentialId);
        $user->setPasskeyPublicKeyPem($publicKeyPem);
        $user->setPasskeySignCount(0);
        $entityManager->flush();

        $session->remove(self::PASSKEY_REGISTER_CHALLENGE);

        return new JsonResponse(['success' => true, 'message' => 'Face ID / passkey enabled']);
    }

    #[Route('/passkey/login/options', name: 'app_passkey_login_options', methods: ['POST'])]
    public function passkeyLoginOptions(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $email = trim((string) $request->request->get('email', ''));
        if ($email === '') {
            $payload = json_decode((string) $request->getContent(), true);
            if (is_array($payload)) {
                $email = trim((string) ($payload['email'] ?? ''));
            }
        }

        if ($email === '') {
            return new JsonResponse(['success' => false, 'message' => 'Email is required for passkey login'], Response::HTTP_BAD_REQUEST);
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (
            !$user instanceof User ||
            !$user->isStatus() ||
            !$user->getPasskeyCredentialId() ||
            !$user->getPasskeyPublicKeyPem()
        ) {
            return new JsonResponse(['success' => false, 'message' => 'No passkey available for this account'], Response::HTTP_BAD_REQUEST);
        }

        $challenge = $this->base64UrlEncode(random_bytes(32));
        $request->getSession()->set(self::PASSKEY_LOGIN_CHALLENGE, $challenge);
        $request->getSession()->set(self::PASSKEY_LOGIN_USER_ID, $user->getId());

        return new JsonResponse([
            'success' => true,
            'publicKey' => [
                'challenge' => $challenge,
                'rpId' => self::PASSKEY_RP_ID,
                'allowCredentials' => [
                    [
                        'type' => 'public-key',
                        'id' => $user->getPasskeyCredentialId(),
                    ],
                ],
                'timeout' => 60000,
                'userVerification' => 'required',
            ],
        ]);
    }

    #[Route('/passkey/login/verify', name: 'app_passkey_login_verify', methods: ['POST'])]
    public function passkeyLoginVerify(
        Request $request,
        EntityManagerInterface $entityManager,
        TokenStorageInterface $tokenStorage
    ): JsonResponse {
        $session = $request->getSession();
        $storedChallenge = (string) $session->get(self::PASSKEY_LOGIN_CHALLENGE, '');
        $userId = (int) $session->get(self::PASSKEY_LOGIN_USER_ID, 0);
        if ($storedChallenge === '' || $userId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'No passkey login challenge found'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $credentialId = (string) ($payload['id'] ?? '');
        $clientDataBase64 = (string) ($payload['clientDataJSON'] ?? '');
        $authenticatorDataBase64 = (string) ($payload['authenticatorData'] ?? '');
        $signatureBase64 = (string) ($payload['signature'] ?? '');

        if ($credentialId === '' || $clientDataBase64 === '' || $authenticatorDataBase64 === '' || $signatureBase64 === '') {
            return new JsonResponse(['success' => false, 'message' => 'Incomplete passkey assertion'], Response::HTTP_BAD_REQUEST);
        }

        $user = $entityManager->getRepository(User::class)->find($userId);
        if (
            !$user instanceof User ||
            !$user->isStatus() ||
            !$user->getPasskeyCredentialId() ||
            !$user->getPasskeyPublicKeyPem()
        ) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid account for passkey login'], Response::HTTP_UNAUTHORIZED);
        }

        if (!hash_equals((string) $user->getPasskeyCredentialId(), $credentialId)) {
            return new JsonResponse(['success' => false, 'message' => 'Credential mismatch'], Response::HTTP_UNAUTHORIZED);
        }

        $clientDataJson = $this->base64UrlDecode($clientDataBase64);
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid client data'], Response::HTTP_BAD_REQUEST);
        }

        if ((string) ($clientData['type'] ?? '') !== 'webauthn.get') {
            return new JsonResponse(['success' => false, 'message' => 'Invalid passkey assertion type'], Response::HTTP_BAD_REQUEST);
        }

        $origin = (string) ($clientData['origin'] ?? '');
        if (!$this->isAllowedPasskeyOrigin($origin, $request)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid passkey origin'], Response::HTTP_BAD_REQUEST);
        }

        $incomingChallenge = (string) ($clientData['challenge'] ?? '');
        if (!hash_equals($storedChallenge, $incomingChallenge)) {
            return new JsonResponse(['success' => false, 'message' => 'Passkey challenge mismatch'], Response::HTTP_BAD_REQUEST);
        }

        $authenticatorData = $this->base64UrlDecode($authenticatorDataBase64);
        if (strlen($authenticatorData) < 37) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid authenticator data'], Response::HTTP_BAD_REQUEST);
        }

        $flags = ord($authenticatorData[32]);
        $userPresent = ($flags & 0x01) === 0x01;
        if (!$userPresent) {
            return new JsonResponse(['success' => false, 'message' => 'User presence not verified'], Response::HTTP_UNAUTHORIZED);
        }

        $counterBytes = substr($authenticatorData, 33, 4);
        $counter = unpack('N', $counterBytes)[1] ?? 0;
        $storedCounter = (int) ($user->getPasskeySignCount() ?? 0);
        if ($counter > 0 && $counter <= $storedCounter) {
            return new JsonResponse(['success' => false, 'message' => 'Stale passkey signature counter'], Response::HTTP_UNAUTHORIZED);
        }

        $signature = $this->base64UrlDecode($signatureBase64);
        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authenticatorData . $clientDataHash;

        $verifyOk = openssl_verify($signedData, $signature, (string) $user->getPasskeyPublicKeyPem(), OPENSSL_ALGO_SHA256);
        if ($verifyOk !== 1) {
            return new JsonResponse(['success' => false, 'message' => 'Passkey signature verification failed'], Response::HTTP_UNAUTHORIZED);
        }

        $session->remove(self::PASSKEY_LOGIN_CHALLENGE);
        $session->remove(self::PASSKEY_LOGIN_USER_ID);

        if ($counter > 0) {
            $user->setPasskeySignCount($counter);
            $entityManager->flush();
        }

        $this->createLoginSession($request, $user, $tokenStorage);

        return new JsonResponse([
            'success' => true,
            'redirect' => $this->generateUrl('app_home'),
        ]);
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        ValidatorInterface $validator
    ): Response {
       

        // Handle POST request (form submission)
        if ($request->isMethod('POST')) {
            $fullName = trim((string) $request->request->get('fullName', ''));
            $email = trim((string) $request->request->get('email', ''));
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirmPassword');
            $role = (string) $request->request->get('role', 'student');

            $logger->info('Registration attempt', [
                'email' => $email,
                'fullName' => $fullName,
                'role' => $role
            ]);

            // Validation
            $errors = [];
            $inputData = [
                'fullName' => $fullName,
                'email' => $email,
                'password' => (string) $password,
                'confirmPassword' => (string) $confirmPassword,
                'role' => $role,
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
                    new Assert\NotBlank(message: 'Email is required'),
                    new Assert\Email(message: 'Please enter a valid email address'),
                    new Assert\Length(max: 180, maxMessage: 'Email is too long'),
                ]),
                'password' => new Assert\Sequentially([
                    new Assert\NotBlank(message: 'Password is required'),
                    new Assert\Length(min: 6, minMessage: 'Password must be at least 6 characters'),
                ]),
                'confirmPassword' => new Assert\Sequentially([
                    new Assert\NotBlank(message: 'Please confirm your password'),
                ]),
                'role' => new Assert\Sequentially([
                    new Assert\Choice(choices: ['student', 'tutor'], message: 'Invalid role selected'),
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
                $path = (string) $violation->getPropertyPath();
                $field = preg_match('/\[(.+)\]/', $path, $m) ? $m[1] : 'general';
                if (!isset($errors[$field])) {
                    $errors[$field] = $violation->getMessage();
                }
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
        // Clear session user data
        $request->getSession()->remove('user');
        $request->getSession()->remove('_security_main');
        $tokenStorage->setToken(null);
        
        // Redirect to login page
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

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger,
        HttpClientInterface $httpClient
    ): Response {
        $session = $request->getSession();
        $recaptchaSiteKey = (string) ($_ENV['RECAPTCHA_SITE_KEY'] ?? '');
        $recaptchaSecretKey = (string) ($_ENV['RECAPTCHA_SECRET_KEY'] ?? '');

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', ''));
            $recaptchaResponse = trim((string) $request->request->get('g-recaptcha-response', ''));
            $this->clearForgotPasswordSession($session);

            if ($recaptchaSiteKey === '' || $recaptchaSecretKey === '') {
                if ($this->wantsJson($request)) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'reCAPTCHA is not configured on server.',
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                $this->addFlash('error', 'reCAPTCHA is not configured on server.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if ($recaptchaResponse === '') {
                if ($this->wantsJson($request)) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Please confirm you are not a robot.',
                    ], Response::HTTP_BAD_REQUEST);
                }

                $this->addFlash('error', 'Please confirm you are not a robot.');
                return $this->redirectToRoute('app_forgot_password');
            }

            try {
                $verification = $httpClient->request('POST', 'https://www.google.com/recaptcha/api/siteverify', [
                    'body' => [
                        'secret' => $recaptchaSecretKey,
                        'response' => $recaptchaResponse,
                        'remoteip' => $request->getClientIp() ?? '',
                    ],
                ])->toArray(false);

                if (($verification['success'] ?? false) !== true) {
                    if ($this->wantsJson($request)) {
                        return new JsonResponse([
                            'success' => false,
                            'message' => 'reCAPTCHA verification failed. Please try again.',
                        ], Response::HTTP_BAD_REQUEST);
                    }

                    $this->addFlash('error', 'reCAPTCHA verification failed. Please try again.');
                    return $this->redirectToRoute('app_forgot_password');
                }
            } catch (\Throwable $e) {
                $logger->error('reCAPTCHA verification failed', ['error' => $e->getMessage()]);

                if ($this->wantsJson($request)) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Unable to verify reCAPTCHA right now.',
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                $this->addFlash('error', 'Unable to verify reCAPTCHA right now.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if ($this->wantsJson($request)) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Please enter a valid email address.',
                    ], Response::HTTP_BAD_REQUEST);
                }

                $this->addFlash('error', 'Please enter a valid email address.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            $code = (string) random_int(100000, 999999);
            $codeHash = hash('sha256', $code);
            $expiresAt = time() + self::RESET_CODE_TTL_SECONDS;

            // Always initialize session state to avoid leaking whether email exists.
            $session->set(self::RESET_SESSION_PREFIX . 'user_id', $user instanceof User ? $user->getId() : null);
            $session->set(self::RESET_SESSION_PREFIX . 'code_hash', $codeHash);
            $session->set(self::RESET_SESSION_PREFIX . 'expires_at', $expiresAt);
            $session->set(self::RESET_SESSION_PREFIX . 'verified', false);
            $session->set(self::RESET_SESSION_PREFIX . 'attempts', 0);
            $session->set(self::RESET_SESSION_PREFIX . 'email_mask', $this->maskEmail($email));

            if ($user instanceof User) {
                $mailerDsn = (string) ($_ENV['MAILER_DSN'] ?? '');
                $mailerFrom = (string) ($_ENV['MAILER_FROM'] ?? 'no-reply@fahamni.local');
                $usesPlaceholderConfig = str_contains($mailerDsn, 'YOUR_EMAIL')
                    || str_contains($mailerDsn, 'YOUR_16_CHAR_APP_PASSWORD')
                    || str_contains($mailerFrom, 'YOUR_EMAIL');

                if ($usesPlaceholderConfig) {
                    if ($this->wantsJson($request)) {
                        return new JsonResponse([
                            'success' => false,
                            'message' => 'Mailer is not configured. Replace placeholder values in .env.local.',
                        ], Response::HTTP_INTERNAL_SERVER_ERROR);
                    }

                    $this->addFlash('error', 'Mailer is not configured. Replace placeholder values in .env.local.');

                    return $this->redirectToRoute('app_forgot_password');
                }

                if (str_starts_with($mailerDsn, 'null://') && (bool) $this->getParameter('kernel.debug')) {
                    $this->addFlash('error', sprintf('Email is not configured. Dev code: %s', $code));
                } else {
                    try {
                        $message = (new TemplatedEmail())
                            ->from(new Address($mailerFrom, 'FAHIMNI'))
                            ->to($email)
                            ->subject('Your FAHIMNI password reset code')
                            ->htmlTemplate('emails/password_reset_code.html.twig')
                            ->context([
                                'code' => $code,
                                'minutes' => (int) (self::RESET_CODE_TTL_SECONDS / 60),
                            ]);

                        $mailer->send($message);
                    } catch (\Throwable $e) {
                        $logger->error('Forgot-password email send failed', [
                            'error' => $e->getMessage(),
                            'email' => $email,
                            'dsn_prefix' => strtok($mailerDsn, ':') ?: 'unknown',
                        ]);

                        $this->clearForgotPasswordSession($session);

                        $debugDetail = (bool) $this->getParameter('kernel.debug')
                            ? (' Details: ' . $e->getMessage())
                            : '';

                        if ($this->wantsJson($request)) {
                            return new JsonResponse([
                                'success' => false,
                                'message' => 'Unable to send email. Check mail configuration.' . $debugDetail,
                            ], Response::HTTP_INTERNAL_SERVER_ERROR);
                        }

                        $this->addFlash('error', 'Unable to send email. Check mail configuration.' . $debugDetail);

                        return $this->redirectToRoute('app_forgot_password');
                    }
                }
            }

            if ($this->wantsJson($request)) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'If an account exists, a verification code has been sent.',
                    'redirect' => $this->generateUrl('app_forgot_password_verify'),
                ]);
            }

            return $this->redirectToRoute('app_forgot_password_verify');
        }

        return $this->render('front/auth/forgot_password.html.twig', [
            'recaptcha_site_key' => $recaptchaSiteKey,
        ]);
    }

    #[Route('/forgot-password/verify', name: 'app_forgot_password_verify', methods: ['GET', 'POST'])]
    public function verifyForgotPasswordCode(Request $request): Response
    {
        $session = $request->getSession();
        $expiresAt = (int) $session->get(self::RESET_SESSION_PREFIX . 'expires_at', 0);
        $storedHash = (string) $session->get(self::RESET_SESSION_PREFIX . 'code_hash', '');
        $attempts = (int) $session->get(self::RESET_SESSION_PREFIX . 'attempts', 0);

        if ($storedHash === '' || $expiresAt < time()) {
            $this->clearForgotPasswordSession($session);
            $this->addFlash('error', 'Code expired. Request a new reset code.');

            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $code = preg_replace('/\D+/', '', (string) $request->request->get('code', ''));

            if (strlen($code) !== 6) {
                $this->addFlash('error', 'Please enter a valid 6-digit code.');
                return $this->redirectToRoute('app_forgot_password_verify');
            }

            if ($attempts >= 5) {
                $this->clearForgotPasswordSession($session);
                $this->addFlash('error', 'Too many attempts. Request a new code.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $incomingHash = hash('sha256', $code);

            if (!hash_equals($storedHash, $incomingHash)) {
                $session->set(self::RESET_SESSION_PREFIX . 'attempts', $attempts + 1);
                $this->addFlash('error', 'Invalid code. Please try again.');

                return $this->redirectToRoute('app_forgot_password_verify');
            }

            $session->set(self::RESET_SESSION_PREFIX . 'attempts', 0);
            $session->set(self::RESET_SESSION_PREFIX . 'verified', true);

            return $this->redirectToRoute('app_forgot_password_reset');
        }

        return $this->render('front/auth/forgot_password_verify.html.twig', [
            'maskedEmail' => $session->get(self::RESET_SESSION_PREFIX . 'email_mask', ''),
        ]);
    }

    #[Route('/forgot-password/reset', name: 'app_forgot_password_reset', methods: ['GET', 'POST'])]
    public function forgotPasswordReset(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $session = $request->getSession();
        $verified = (bool) $session->get(self::RESET_SESSION_PREFIX . 'verified', false);
        $userId = $session->get(self::RESET_SESSION_PREFIX . 'user_id');

        if (!$verified || !$userId) {
            $this->addFlash('error', 'Please verify your reset code first.');

            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirmPassword = (string) $request->request->get('confirmPassword', '');

            if (strlen($password) < 6) {
                $this->addFlash('error', 'Password must be at least 6 characters.');

                return $this->redirectToRoute('app_forgot_password_reset');
            }

            if ($password !== $confirmPassword) {
                $this->addFlash('error', 'Passwords do not match.');

                return $this->redirectToRoute('app_forgot_password_reset');
            }

            $user = $entityManager->getRepository(User::class)->find($userId);
            if (!$user instanceof User) {
                $this->clearForgotPasswordSession($session);
                $this->addFlash('error', 'Unable to reset password. Please try again.');

                return $this->redirectToRoute('app_forgot_password');
            }

            $user->setPassword($passwordHasher->hashPassword($user, $password));
            $entityManager->flush();

            $this->clearForgotPasswordSession($session);
            $this->addFlash('success', 'Password updated successfully. You can now sign in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('front/auth/reset_password.html.twig');
    }

    #[Route('/legacy-reset-password/{token}', name: 'app_reset_password_legacy', methods: ['GET', 'POST'])]
    public function resetPassword(
        string $token
    ): Response {
        return $this->redirectToRoute('app_reset_password', ['token' => $token]);
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        return str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }

    private function clearForgotPasswordSession(\Symfony\Component\HttpFoundation\Session\SessionInterface $session): void
    {
        $session->remove(self::RESET_SESSION_PREFIX . 'user_id');
        $session->remove(self::RESET_SESSION_PREFIX . 'code_hash');
        $session->remove(self::RESET_SESSION_PREFIX . 'expires_at');
        $session->remove(self::RESET_SESSION_PREFIX . 'verified');
        $session->remove(self::RESET_SESSION_PREFIX . 'attempts');
        $session->remove(self::RESET_SESSION_PREFIX . 'email_mask');
    }

    /**
     * @return array{id:int|null,email:?string,fullName:?string,roles:array}
     */
    private function createLoginSession(Request $request, User $user, TokenStorageInterface $tokenStorage): array
    {
        $session = $request->getSession();
        $userData = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'fullName' => method_exists($user, 'getFullName') ? $user->getFullName() : null,
            'roles' => $user->getRoles(),
        ];

        $session->set('user', $userData);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        $session->set('_security_main', serialize($token));

        return $userData;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    private function isAllowedPasskeyOrigin(string $origin, Request $request): bool
    {
        $expected = $request->getSchemeAndHttpHost();
        return $origin !== '' && hash_equals($expected, $origin);
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }

        $local = $parts[0];
        $domain = $parts[1];
        $visible = strlen($local) > 2 ? substr($local, 0, 2) : substr($local, 0, 1);
        $masked = $visible . str_repeat('*', max(1, strlen($local) - strlen($visible)));

        return $masked . '@' . $domain;
    }
}

