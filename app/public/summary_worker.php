<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Controllers/ArticleController.php';

use App\Src\Controllers\ArticleController;

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    exit(1);
}

$jobId = $argv[1] ?? '';
if ($jobId === '') {
    exit(1);
}

$controller = new ArticleController();
$controller->handleBackgroundJob($jobId);
