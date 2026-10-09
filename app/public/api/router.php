<?php
declare(strict_types=1);

// API router dispatcher for document summarization endpoints.

require_once __DIR__ . '/../../src/whitereaper.php';
require_once __DIR__ . '/../../src/Controllers/DocumentSummaryController.php';

use App\Src\Controllers\DocumentSummaryController;

$controller = new DocumentSummaryController();
$controller->dispatch();
