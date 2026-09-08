<?php
namespace App\Src\Controllers;

use App\Src\Database;
use App\Src\Services\EmailService;
use App\Src\Services\GuestSessionService;
use App\Src\Services\PasswordResetService;
use App\Src\Services\RateLimiter;
use App\Src\Services\RememberMeService;
use App\Src\Services\TermsAcceptanceService;
use PDO;
use PDOException;

require_once __DIR__ . '/../whitereaper.php';
require_once __DIR__ . '/../Utils/SessionManager.php';
require_once __DIR__ . '/../Utils/PasswordPolicy.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Utils/validation.php';
require_once __DIR__ . '/../Services/RateLimiter.php';

class AuthController
{
    private PDO $db;
    private ?EmailService $emailService;
    private ?PasswordResetService $resetService;
    private TermsAcceptanceService $termsAcceptanceService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->emailService = class_exists(EmailService::class) ? new EmailService() : null;
        $this->resetService = class_exists(PasswordResetService::class)
            ? new PasswordResetService($this->db)
            : null;
        $this->termsAcceptanceService = new TermsAcceptanceService();
        $this->termsAcceptanceService->ensureSchema();
        RememberMeService::ensureSchema();
    }

    public function login(): void
    {
        $this->checkCsrf();
        $loginContext = $this->getLoginContext($_POST['login_context'] ?? null);
        $loginLocation = $loginContext === 'admin' ? 'login.php?context=admin' : 'login.php';

        $ip = RateLimiter::getClientIp();
        $limiter = new RateLimiter();
        $this->redirectIfRateLimited(
            $limiter,
            'login',
            $ip,
            $loginLocation,
            'Too many login attempts. Please try again in %d minute(s).',
            'Login'
        );

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $this->redirectWithError('Username and password are required.', $loginLocation);
        }

        if (strlen($username) > 100 || strlen($password) > 72) {
            $this->redirectWithError('Invalid credentials.', $loginLocation);
        }

        $user = $this->findActiveUserByCredential($username);
        if ($user && \App\Src\Utils\PasswordPolicy::verify($password, $user['password_hash'])) {
            $limiter->resetAttempts('login', $ip);

            $guestSessionService = new GuestSessionService();
            $guestToken = $guestSessionService->getCurrentGuestToken();

            \App\Src\Utils\SessionManager::regenerate();

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            if ($user['role'] === 'admin') {
                $_SESSION['admin_challenge_verified'] = true;
            }
            $this->termsAcceptanceService->storeTermsStateInSession($user);

            try {
                $guestSessionService->claimGuestDataForUser((int)$user['id'], $guestToken);
            } catch (\Throwable $throwable) {
                error_log('[AuthController] Guest data migration failed during login: ' . $throwable->getMessage());
            }

            $guestSessionService->clearGuestIdentity();

            // Remember Me: generate secure token and store in DB
            $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
            if ($rememberMe) {
                RememberMeService::createCookieForUser((int)$user['id']);
            }

            rotateCsrfToken();
            if (TermsAcceptanceService::currentUserNeedsAcceptance()) {
                $this->redirect('accept_terms.php');
            }

            if ($user['role'] === 'admin') {
                $this->redirect('admin_dashboard.php');
            }

            $this->redirect('index.php');
        }

        $limiter->recordAttempt('login', $ip);
        $this->redirectWithError('Invalid username or password.', $loginLocation);
    }

    public function register(): void
    {
        $this->checkCsrf();

        $username = trim($_POST['username'] ?? '');
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $termsAccepted = isAcceptedCheckboxValue($_POST['terms_accept'] ?? null);

        if (!$termsAccepted) {
            $this->redirectWithError('You must accept the Terms and Conditions before registering.', 'register.php');
        }

        $usernameError = validateUsername($username);
        if ($usernameError) {
            $this->redirectWithError($usernameError, 'register.php');
        }

        $normalizedEmail = $this->normalizeEmailOrRedirect(
            $email,
            'register.php',
            null
        );

        $passwordError = \App\Src\Utils\PasswordPolicy::validate($password);
        if ($passwordError) {
            $this->redirectWithError($passwordError, 'register.php');
        }

        if ($password !== $confirmPassword) {
            $this->redirectWithError('Your passwords do not match. Please try again.', 'register.php');
        }

        $passwordHash = \App\Src\Utils\PasswordPolicy::hash($password);

        try {
            $statement = $this->db->prepare(
                'INSERT INTO users (username, email, password_hash, terms_accepted, terms_accepted_at)
                 VALUES (?, ?, ?, 0, NULL)'
            );
            $statement->execute([$username, $normalizedEmail, $passwordHash]);

            rotateCsrfToken();
            $this->redirectWithSuccess('Registration successful. Please login.', 'login.php');
        } catch (PDOException $exception) {
            $this->redirectWithError('Username or email already exists.', 'register.php');
        }
    }

    public function logout(): void
    {
        RememberMeService::clearCookie();
        
        $guestSessionService = new GuestSessionService();
        $guestSessionService->clearGuestIdentity();

        unset($_SESSION['pending_admin_user_id']);
        unset($_SESSION['admin_challenge_verified']);

        \App\Src\Utils\SessionManager::destroy();

        $this->redirect('index.php');
    }
    public function forgotPassword(): void
    {
        $this->checkCsrf();

        $ip = RateLimiter::getClientIp();
        $limiter = new RateLimiter();
        $this->redirectIfRateLimited(
            $limiter,
            'reset_request',
            $ip,
            'forgot_password.php',
            'Too many reset requests. Please try again in %d minute(s).',
            'Password reset request'
        );

        if (!$this->resetService) {
            $this->redirectWithError('Password reset service unavailable.', 'forgot_password.php');
        }

        $email = $this->normalizeEmailOrRedirect(
            $_POST['email'] ?? '',
            'forgot_password.php',
            'Please enter a valid email address.'
        );

        $user = $this->findUserByEmail($email);
        if ($user) {
            $otp = $this->resetService->createToken($user['id']);
            if ($this->emailService) {
                $this->emailService->sendPasswordResetEmail($email, $otp);
            }
        }

        $limiter->recordAttempt('reset_request', $ip);
        $_SESSION['reset_email'] = $email;

        rotateCsrfToken();
        $this->redirectWithSuccess(
            'If your email exists, a 6-digit recovery code was sent.',
            'reset_password.php'
        );
    }

    public function resetPassword(): void
    {
        $this->checkCsrf();

        $ip = RateLimiter::getClientIp();
        $limiter = new RateLimiter();
        $this->redirectIfRateLimited(
            $limiter,
            'otp_verify',
            $ip,
            'reset_password.php',
            'Too many verification attempts. Please try again in %d minute(s).',
            'OTP verification'
        );

        if (!$this->resetService) {
            $this->redirectWithError('Password reset service unavailable.', 'login.php');
        }

        $email = $this->normalizeEmailOrRedirect(
            $_POST['email'] ?? '',
            'reset_password.php',
            'Please enter a valid email address.'
        );
        $otp = trim($_POST['otp'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($password !== $confirmPassword) {
            $this->redirectWithError('Your passwords do not match. Please try again.', 'reset_password.php');
        }

        $passwordError = \App\Src\Utils\PasswordPolicy::validate($password);
        if ($passwordError) {
            $this->redirectWithError($passwordError, 'reset_password.php');
        }

        if (!preg_match('/^\d{6}$/', $otp)) {
            $this->redirectWithError('OTP must be a 6-digit numeric code.', 'reset_password.php');
        }

        $user = $this->findUserByEmail($email);
        if ($user && $this->resetPasswordForUser($user['id'], $otp, $password)) {
            $limiter->resetAttempts('otp_verify', $ip);
            unset($_SESSION['reset_email']);

            rotateCsrfToken();
            $this->redirectWithSuccess('Password reset successfully. You may login.', 'login.php');
        }

        $limiter->recordAttempt('otp_verify', $ip);
        $this->redirectWithError(
            'Invalid or expired OTP code, or unrecognized email.',
            'reset_password.php'
        );
    }

    private function checkCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            http_response_code(403);
            die('Invalid CSRF token.');
        }
    }

    private function redirectIfRateLimited(
        RateLimiter $limiter,
        string $action,
        string $ip,
        string $location,
        string $messageTemplate,
        string $logLabel
    ): void {
        if (!$limiter->isLimited($action, $ip)) {
            return;
        }

        $retryAfter = $limiter->getRetryAfter($action, $ip);
        $minutes = (int)ceil($retryAfter / 60);

        error_log("[RateLimit] {$logLabel} blocked for IP: {$ip}");
        $this->redirectWithError(sprintf($messageTemplate, $minutes), $location);
    }

    private function normalizeEmailOrRedirect(
        string $email,
        string $location,
        ?string $overrideMessage
    ): string {
        $result = validateEmail($email);
        if (is_string($result)) {
            $message = $overrideMessage ?? $result;
            $this->redirectWithError($message, $location);
        }

        return $result['email'];
    }

    private function findActiveUserByCredential(string $usernameOrEmail)
    {
        $statement = $this->db->prepare(
            'SELECT * FROM users WHERE (username = :u1 OR email = :u2) AND active = 1'
        );
        $statement->execute([
            'u1' => $usernameOrEmail,
            'u2' => $usernameOrEmail,
        ]);

        return $statement->fetch();
    }

    private function findUserByEmail(string $email)
    {
        $statement = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $statement->execute([$email]);
        return $statement->fetch();
    }

    private function resetPasswordForUser(int $userId, string $otp, string $password): bool
    {
        $resetRecord = $this->resetService->validateToken($userId, $otp);
        if (!$resetRecord) {
            return false;
        }

        $passwordHash = \App\Src\Utils\PasswordPolicy::hash($password);

        $this->db->beginTransaction();
        try {
            $updateStatement = $this->db->prepare(
                'UPDATE users SET password_hash = ? WHERE id = ?'
            );
            $updateStatement->execute([$passwordHash, $userId]);

            $this->resetService->markTokenUsed($userId);
            $this->db->commit();

            return true;
        } catch (\Exception $exception) {
            $this->db->rollBack();
            error_log('[AuthController] Password reset transaction failed: ' . $exception->getMessage());
            $this->redirectWithError('An error occurred. Please try again.', 'reset_password.php');
        }

        return false;
    }

    private function getLoginContext(?string $loginContext): string
    {
        return $loginContext === 'admin' ? 'admin' : '';
    }

    private function redirectWithError(string $message, string $location): void
    {
        $_SESSION['error'] = $message;
        $this->redirect($location);
    }

    private function redirectWithSuccess(string $message, string $location): void
    {
        $_SESSION['success'] = $message;
        $this->redirect($location);
    }

    private function redirect(string $location): void
    {
        header("Location: {$location}");
        exit;
    }
}

if (isset($_GET['action'])) {
    $auth = new AuthController();

    switch ($_GET['action']) {
        case 'login':
            $auth->login();
            break;
        case 'register':
            $auth->register();
            break;
        case 'logout':
            $auth->logout();
            break;
        case 'forgotPassword':
            $auth->forgotPassword();
            break;
        case 'resetPassword':
            $auth->resetPassword();
            break;
    }
}
