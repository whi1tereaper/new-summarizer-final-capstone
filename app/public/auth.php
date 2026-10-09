<?php
// Explicit entry point and dispatcher for authentication actions.

require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';

use App\Src\Controllers\AuthController;

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

    case 'register':
        $controller->register();
        break;

    case 'logout':
        $controller->logout();
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
