<?php
// Resolve this trusted CLI-only handoff before loading configuration/services.
// Do not accept a backend selector from HTTP or switch databases on fallback.
if (PHP_SAPI !== 'cli') { http_response_code(400); exit(1); }
if (isset($argv[2]) && $argv[2] !== '--database-driver=mysql') exit(2);
require_once __DIR__ . '/../src/Support/ProcessingError.php';

use App\Src\Controllers\ArticleController;

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    exit(1);
}

$jobId = $argv[1] ?? '';
if ($jobId === '') {
    exit(1);
}

\App\Src\Support\ProcessingDiagnostics::begin($jobId);
\App\Src\Support\ProcessingDiagnostics::stage('worker_bootstrap', 'summary_worker', 'main');
try {
    require_once __DIR__ . '/../src/whitereaper.php';
    require_once __DIR__ . '/../src/Controllers/ArticleController.php';
    $controller = new ArticleController();
    $controller->handleBackgroundJob($jobId);
} catch (\Throwable $error) {
    \App\Src\Support\ProcessingError::fromThrowable($error)->log();
    exit(1);
}
