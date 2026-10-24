<?php
namespace App\Src\Controllers;

use App\Src\Database;
use App\Src\Repositories\UserRepository;
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
require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Utils/validation.php';
require_once __DIR__ . '/../Services/RateLimiter.php';
require_once __DIR__ . '/../Services/StudentVerificationService.php';
require_once __DIR__ . '/../Support/StudentRegistrationForm.php';

class AuthController
{
    private const ADMIN_LOGIN_CODE_TTL = 600;

    private PDO $db;
    private UserRepository $users;
    private ?EmailService $emailService;
    private ?PasswordResetService $resetService;
    private TermsAcceptanceService $termsAcceptanceService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->users = new UserRepository($this->db);
        $this->emailService = null;
        $this->resetService = class_exists(PasswordResetService::class)
            ? new PasswordResetService($this->db)
            : null;
        $this->termsAcceptanceService = new TermsAcceptanceService();
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

        $user = $this->users->findActiveByCredential($username);
        $authenticated = false;

        if ($user && \App\Src\Utils\PasswordPolicy::verify($password, $user['password_hash'])) {
            $authenticated = true;
        }

        if ($authenticated && $user) {
            if (($user['role'] ?? '') === 'admin') {
                $limiter->resetAttempts('login', $ip);
                $this->beginAdminLoginCode($user);
            }

            $limiter->resetAttempts('login', $ip);
            if (!$this->establishAuthenticatedSession($user)) {
                $this->redirect($loginLocation);
            }

            // Remember Me: generate secure token and store in DB
            $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
            if ($rememberMe) {
                RememberMeService::createCookieForUser((int)$user['id']);
            }

            if (TermsAcceptanceService::currentUserNeedsAcceptance()) {
                $this->redirect('accept_terms.php');
            }

            $this->redirect($user['role'] === 'admin' ? 'admin_dashboard.php' : 'index.php');
        }

        $limiter->recordAttempt('login', $ip);
        $this->redirectWithError('Invalid username or password.', $loginLocation);
    }

    private function beginAdminLoginCode(array $user): void
    {
        $loginLocation = 'login.php?context=admin';
        $email = strtolower(trim((string)($user['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[AuthController] Admin account has no valid email address for second-factor sign-in.');
            $this->redirectWithError('Administrator sign-in is unavailable. Please contact support.', $loginLocation);
        }

        $ip = RateLimiter::getClientIp();
        $limiter = new RateLimiter();
        $emailIdentifier = hash('sha256', $email);
        $requestLimits = [
            ['admin_code_request_ip', $ip],
            ['admin_code_request_user', $emailIdentifier],
        ];

        foreach ($requestLimits as [$action, $identifier]) {
            $this->redirectIfRateLimited(
                $limiter,
                $action,
                $identifier,
                $loginLocation,
                'Too many verification emails were requested. Please try again in %d minute(s).',
                'Administrator sign-in code request'
            );
        }
        foreach ($requestLimits as [$action, $identifier]) {
            $limiter->recordAttempt($action, $identifier);
        }

        $code = (string)random_int(100000, 999999);
        $emailService = $this->getEmailService();
        if (!$emailService || !$emailService->sendAdminLoginCodeEmail($email, $code)) {
            $this->redirectWithError('We could not send a verification code. Please try again later.', $loginLocation);
        }

        \App\Src\Utils\SessionManager::regenerate();
        unset(
            $_SESSION['user_id'],
            $_SESSION['role'],
            $_SESSION['username'],
            $_SESSION['terms_accepted'],
            $_SESSION['terms_accepted_at'],
            $_SESSION['admin_challenge_verified'],
            $_SESSION['pending_admin_user_id'],
            $_SESSION['pending_admin_remember_me']
        );
        $_SESSION['pending_admin_login'] = [
            'user_id' => (int)($user['id'] ?? 0),
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'email_hash' => $emailIdentifier,
            'email_hint' => $this->maskEmailAddress($email),
            'rate_identifier' => $emailIdentifier,
            'expires_at' => time() + self::ADMIN_LOGIN_CODE_TTL,
        ];
        rotateCsrfToken();

        $this->redirectWithSuccess(
            'A one-time sign-in code has been sent to ' . $this->maskEmailAddress($email) . '.',
            $loginLocation
        );
    }

    public function verifyAdminLoginCode(): void
    {
        $this->checkCsrf();
        $loginLocation = 'login.php?context=admin';
        $pending = $_SESSION['pending_admin_login'] ?? null;
        $userId = is_array($pending) ? filter_var($pending['user_id'] ?? null, FILTER_VALIDATE_INT) : false;

        if (
            $userId === false
            || !is_string($pending['code_hash'] ?? null)
            || !is_string($pending['rate_identifier'] ?? null)
            || !is_int($pending['expires_at'] ?? null)
        ) {
            unset($_SESSION['pending_admin_login']);
            $this->redirectWithError('Start administrator sign-in again to request a new code.', $loginLocation);
        }

        if ($pending['expires_at'] <= time()) {
            unset($_SESSION['pending_admin_login']);
            $this->redirectWithError('That sign-in code has expired. Sign in again to request a new code.', $loginLocation);
        }

        $ip = RateLimiter::getClientIp();
        $limiter = new RateLimiter();
        $this->redirectIfRateLimited(
            $limiter,
            'admin_code_verify_ip',
            $ip,
            $loginLocation,
            'Too many code attempts. Please try again in %d minute(s).',
            'Administrator code verification'
        );
        $this->redirectIfRateLimited(
            $limiter,
            'admin_code_verify_user',
            $pending['rate_identifier'],
            $loginLocation,
            'Too many code attempts. Please try again in %d minute(s).',
            'Administrator code verification'
        );

        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $pending['code_hash'])) {
            $limiter->recordAttempt('admin_code_verify_ip', $ip);
            $limiter->recordAttempt('admin_code_verify_user', $pending['rate_identifier']);
            $this->redirectWithError('The code is invalid or expired. Check the email and try again.', $loginLocation);
        }

        $user = $userId > 0 ? $this->users->findActiveById((int)$userId) : null;
        if (
            !$user
            || ($user['role'] ?? '') !== 'admin'
            || !hash_equals(
                (string)($pending['email_hash'] ?? ''),
                hash('sha256', strtolower(trim((string)($user['email'] ?? ''))))
            )
        ) {
            unset($_SESSION['pending_admin_login']);
            $this->redirectWithError('Administrator sign-in could not be verified. Start again.', $loginLocation);
        }

        $limiter->resetAttempts('admin_code_verify_ip', $ip);
        $limiter->resetAttempts('admin_code_verify_user', $pending['rate_identifier']);

        if (!$this->establishAuthenticatedSession($user)) {
            $this->redirect($loginLocation);
        }

        if (TermsAcceptanceService::currentUserNeedsAcceptance()) {
            $this->redirect('accept_terms.php');
        }

        $this->redirect('admin_dashboard.php');
    }

    public function register(): void
    {
        $this->checkCsrf();

        $ip = RateLimiter::getClientIp();
        $limiter = new RateLimiter();
        $this->redirectIfRateLimited(
            $limiter,
            'register',
            $ip,
            'register.php',
            'Too many registration attempts. Please try again in %d minute(s).',
            'Registration'
        );
        $limiter->recordAttempt('register', $ip);

        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        if ($username === '') $username = 'student_' . bin2hex(random_bytes(6));
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        $termsAccepted = isAcceptedCheckboxValue($_POST['terms_accept'] ?? null);
        $errors=[];
        if (!$termsAccepted) $errors['terms_accept']='You must accept the Terms and Conditions before registering.';
        $usernameError = validateUsername($username);
        if ($usernameError) $errors['username']=$usernameError;
        $emailResult=validateEmail($email);
        if(is_string($emailResult)) $errors['email']=$emailResult;
        $passwordError = \App\Src\Utils\PasswordPolicy::validate($password);
        if ($passwordError) $errors['password']=$passwordError;
        if ($password !== $confirmPassword) $errors['confirm_password']='Your passwords do not match. Please try again.';
        if($errors)$this->rejectRegistration($errors,true);
        $normalizedEmail=$emailResult['email'];
        $passwordHash = \App\Src\Utils\PasswordPolicy::hash($password);

        try {
            $visitor = (new \App\Src\Services\CampusAccessService($this->db))->visitorToken();
            (new \App\Src\Services\StudentVerificationService($this->db))->register($username, $normalizedEmail, $passwordHash, $_POST, $visitor);
        } catch (PDOException $exception) {
            if (UserRepository::isUniqueConstraintViolation($exception)) {
                $this->rejectRegistration(['email'=>\App\Src\Services\StudentVerificationService::UNAVAILABLE_ID]);
            }
            throw $exception;
        } catch (\InvalidArgumentException $exception) {
            $this->rejectRegistration(['student_number'=>$exception->getMessage()]);
        } catch (\App\Src\Support\ProcessingError $exception) {
            $this->rejectRegistration(['student_number'=>$exception->getMessage()]);
        }
        unset($_SESSION['registration_feedback'],$_SESSION['error']);
        \App\Src\Utils\SessionManager::regenerate();
        rotateCsrfToken();
        $this->redirectWithSuccess(\App\Src\Support\StudentRegistrationForm::CREATED,'login.php');
    }

    private function rejectRegistration(array $errors,bool $audit=false): void
    {
        \App\Src\Support\StudentRegistrationForm::remember($_POST,$errors);
        if($audit){
            try{
                $number=is_string($_POST['student_number']??null)?$_POST['student_number']:'';
                try{$number=\App\Src\Services\StudentVerificationService::registrationNumber($number);}catch(\InvalidArgumentException $ignored){$number=null;}
                (new \App\Src\Services\StudentEnrollmentRegistryService($this->db))->recordRegistrationRejection($number,'validation_failed');
            }catch(\Throwable $ignored){error_log('[Student registration] Validation audit unavailable.');}
        }
        $this->redirect('register.php');
    }

    private function establishAuthenticatedSession(array $user): bool
    {
        $guestSessionService = new GuestSessionService();
        $guestToken = $guestSessionService->getCurrentGuestToken();

        \App\Src\Utils\SessionManager::regenerate();

        try {
            // Bind before the existing history migration clears the guest identity.
            $access = new \App\Src\Services\CampusAccessService($this->db);
            if (!$access->continuedAccess((int)$user['id'])) {
                $access->trialSubject((int)$user['id'], $access->visitorToken());
            }
            $guestSessionService->claimGuestDataForUser((int)$user['id'], $guestToken);
        } catch (\Throwable $exception) {
            error_log('[AuthController] Guest data migration failed during authentication: ' . get_class($exception));
            $_SESSION['error'] = "Your account is ready, but we could not link this guest session's saved data. Your summaries are preserved; please sign in again shortly.";
            return false;
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role'] = (string)($user['role'] ?? 'user');
        $_SESSION['username'] = (string)$user['username'];
        unset($_SESSION['admin_challenge_verified']);
        unset($_SESSION['pending_admin_user_id']);
        unset($_SESSION['pending_admin_remember_me']);
        unset($_SESSION['pending_admin_login']);

        $this->termsAcceptanceService->storeTermsStateInSession($user);

        $guestSessionService->clearGuestIdentity();
        rotateCsrfToken();
        return true;
    }

    public function logout(): void
    {
        RememberMeService::clearCookie();
        
        $guestSessionService = new GuestSessionService();
        $guestSessionService->clearGuestIdentity();

        unset($_SESSION['pending_admin_user_id']);
        unset($_SESSION['pending_admin_remember_me']);
        unset($_SESSION['admin_challenge_verified']);
        unset($_SESSION['pending_admin_login']);

        \App\Src\Utils\SessionManager::destroy();

        $this->redirect('index.php');
    }

    public function deactivateAccount(): void
    {
        $this->checkCsrf();

        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            $this->redirect('login.php');
        }

        $password = $_POST['password'] ?? '';
        if (!is_string($password) || $password === '' || strlen($password) > 72) {
            $this->redirectWithError('Enter your current password to deactivate your account.', 'profile.php');
        }

        $ip = RateLimiter::getClientIp();
        $limiter = new RateLimiter();
        $limiterIdentifier = $userId . ':' . $ip;
        $this->redirectIfRateLimited(
            $limiter,
            'account_deactivation',
            $limiterIdentifier,
            'profile.php',
            'Too many deactivation attempts. Please try again in %d minute(s).',
            'Account deactivation'
        );

        try {
            $user = $this->users->findActiveAccountForDeactivation((int)$userId);
            if (!$user || !\App\Src\Utils\PasswordPolicy::verify($password, (string)$user['password_hash'])) {
                $limiter->recordAttempt('account_deactivation', $limiterIdentifier);
                $this->redirectWithError('The password is incorrect. Your account was not deactivated.', 'profile.php');
            }

            if (!$this->users->deactivateAccount((int)$userId)) {
                $message = ($user['role'] ?? '') === 'admin'
                    ? 'Administrators cannot deactivate their own account. Ask another administrator for assistance.'
                    : 'This account cannot be deactivated right now.';
                $this->redirectWithError(
                    $message,
                    'profile.php'
                );
            }

            $limiter->resetAttempts('account_deactivation', $limiterIdentifier);
            RememberMeService::clearCookie();
            \App\Src\Utils\SessionManager::destroy();
            \App\Src\Utils\SessionManager::start();
            $_SESSION['success'] = 'Your account has been deactivated. Your saved summaries have been retained.';
            $this->redirect('login.php');
        } catch (\Throwable $exception) {
            error_log('[AuthController] Account deactivation failed: ' . $exception->getMessage());
            $this->redirectWithError('We could not deactivate your account. Please try again.', 'profile.php');
        }
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

        $userId = $this->users->findIdByEmail($email);
        if ($userId !== null) {
            $otp = $this->resetService->createToken($userId);
            $emailService = $this->getEmailService();
            if ($emailService) {
                $emailService->sendPasswordResetEmail($email, $otp);
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

        $userId = $this->users->findIdByEmail($email);
        if ($userId !== null && $this->resetPasswordForUser($userId, $otp, $password)) {
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

    private function getEmailService(): ?EmailService
    {
        if ($this->emailService !== null) {
            return $this->emailService;
        }

        try {
            $this->emailService = new EmailService();
        } catch (\RuntimeException|\PHPMailer\PHPMailer\Exception $exception) {
            error_log('[AuthController] Email service initialization failed: ' . $exception->getMessage());
            return null;
        }

        return $this->emailService;
    }

    private function maskEmailAddress(string $email): string
    {
        [$localPart, $domain] = explode('@', $email, 2);
        $visibleCharacter = substr($localPart, 0, 1);
        $maskedCharacters = str_repeat('*', max(1, min(8, strlen($localPart) - 1)));

        return $visibleCharacter . $maskedCharacters . '@' . $domain;
    }

    private function checkCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            die('Method not allowed.');
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !verifyCsrfToken($token)) {
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

    private function resetPasswordForUser(int $userId, string $otp, string $password): bool
    {
        $this->db->beginTransaction();
        try {
            $resetRecord = $this->resetService->validateToken($userId, $otp);
            if (!$resetRecord) {
                $this->db->rollBack();
                return false;
            }
            $passwordHash = \App\Src\Utils\PasswordPolicy::hash($password);
            $this->users->updatePasswordHash($userId, $passwordHash);

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
