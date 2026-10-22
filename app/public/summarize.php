<?php
// Explicit entry point and dispatcher for article summarization.

require_once __DIR__ . '/../src/Support/ProcessingError.php';

use App\Src\Controllers\ArticleController;

$action = $_GET['action'] ?? null;
try {
    require_once __DIR__ . '/../src/whitereaper.php';
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../src/Controllers/ArticleController.php';
    $controller = new ArticleController();
    if (in_array($action, ['execute', 'status', 'cancel', 'retry'], true)) {
        $controller->{$action}();
    } else {
        $controller->process();
    }
} catch (\Throwable $error) {
    $failure = \App\Src\Support\ProcessingError::fromThrowable($error);
    $failure->log();
    if ($action !== null || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($failure->httpStatus);
        echo json_encode(['success' => false, 'error' => $failure->getMessage(), 'failure' => $failure->toArray()]);
    } else {
        $_SESSION['flash_failure'] = $failure->toArray();
        $_SESSION['flash_error'] = $failure->getMessage();
        $_SESSION['summarizer_form_saved_at'] = time();
        header('Location: summarizer.php', true, 303);
    }
}
