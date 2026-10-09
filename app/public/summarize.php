<?php
// Explicit entry point and dispatcher for article summarization.

require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/ArticleController.php';

use App\Src\Controllers\ArticleController;

$controller = new ArticleController();

$action = $_GET['action'] ?? null;

if ($action === 'execute') {
    $controller->execute();
    exit;
}

$controller->process();
