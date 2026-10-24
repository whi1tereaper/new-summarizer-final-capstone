<?php
// Explicit entry point and dispatcher for authentication actions.
use App\Src\Controllers\AuthController;

$registrationRequest=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_GET['action']??$_POST['action']??null)==='register';
try {
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action === null || $action === '') {
    header('Location: login.php');
    exit;
}

$controller = new AuthController();

switch ($action) {
    case 'login':
        $controller->login();
        break;

    case 'verifyAdminLoginCode':
        $controller->verifyAdminLoginCode();
        break;

    case 'register':
        $controller->register();
        break;

    case 'logout':
        $controller->logout();
        break;

    case 'deactivateAccount':
        $controller->deactivateAccount();
        break;

    case 'forgotPassword':
        $controller->forgotPassword();
        break;

    case 'resetPassword':
        $controller->resetPassword();
        break;

    default:
        http_response_code(400);
        echo 'Bad Request: Invalid auth action.';
        exit;
}
} catch (\Throwable $exception) {
    if(!$registrationRequest)throw $exception;
    // Fail closed even when database/bootstrap construction fails before dispatch.
    // Never expose database errors, submitted credentials, or a verification bypass.
    require_once __DIR__.'/../src/Support/StudentRegistrationForm.php';
    require_once __DIR__.'/../src/Utils/SessionManager.php';
    \App\Src\Utils\SessionManager::start();
    \App\Src\Support\StudentRegistrationForm::remember($_POST,['form'=>\App\Src\Support\StudentRegistrationForm::UNAVAILABLE]);
    error_log('[Student registration] Unavailable: '.get_class($exception));
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Retry-After: 30');
    header('Referrer-Policy: no-referrer');
    ?>
    <!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registration unavailable — LIGHT</title><link rel="stylesheet" href="assets/css/design-tokens.css"><link rel="stylesheet" href="assets/css/student-access.css"></head><body><main class="student-shell"><section class="student-access"><h1>Registration temporarily unavailable</h1><p role="alert"><?= htmlspecialchars(\App\Src\Support\StudentRegistrationForm::UNAVAILABLE,ENT_QUOTES,'UTF-8') ?></p><a href="register.php">Return to Create Account</a> · <a href="login.php">Sign In</a></section></main></body></html>
    <?php
}
