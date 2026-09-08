<?php
ob_start();

require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/HistoryHandler.php';
require_once __DIR__ . '/../src/Services/LocalPythonBridge.php';
require_once __DIR__ . '/../src/Services/FileUploadService.php';
require_once __DIR__ . '/../src/Services/SourceResolver.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Controllers\HistoryHandler;
use App\Src\Services\LocalPythonBridge;
use App\Src\Services\FileUploadService;
use App\Src\Services\SourceResolver;
use App\Src\Utils\Formatter;

function sendNutshellJsonResponse(int $statusCode, array $data): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendNutshellJsonResponse(405, [
        'success' => false,
        'message' => 'POST request required.',
    ]);
}

$userId = $_SESSION['user_id'] ?? null;
$guestToken = $_SESSION['guest_token'] ?? null;

// Parse either JSON body or Multipart Form
$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : null;

$csrfToken = null;
$summaryId = null;
$directText = '';
$filePath = '';

if (is_array($jsonInput)) {
    $csrfToken = $jsonInput['csrf_token'] ?? null;
    $summaryId = $jsonInput['summary_id'] ?? null;
    $directText = isset($jsonInput['text']) && is_string($jsonInput['text']) ? trim($jsonInput['text']) : '';
} else {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $summaryId = $_POST['summary_id'] ?? null;
    $directText = isset($_POST['original_text']) && is_string($_POST['original_text']) ? trim($_POST['original_text']) : '';

    if (isset($_FILES['pdf_file']) && is_array($_FILES['pdf_file']) && ($_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $fileUploadService = new FileUploadService();
            $filePath = $fileUploadService->saveUploadedFile($_FILES['pdf_file']);
        } catch (\Throwable $e) {
            sendNutshellJsonResponse(400, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

if (!verifyCsrfToken($csrfToken)) {
    sendNutshellJsonResponse(403, [
        'success' => false,
        'message' => 'Invalid or expired CSRF token. Please refresh the page and try again.',
    ]);
}

$textToAnalyze = '';
$temporaryFilePathToClean = $filePath;

try {
    if ($summaryId !== null && (int)$summaryId > 0) {
        $summaryData = HistoryHandler::getSummaryById((int)$summaryId, $userId, $guestToken);
        if (!$summaryData || isset($summaryData['error'])) {
            sendNutshellJsonResponse(404, [
                'success' => false,
                'message' => 'Summary not found or access denied.',
            ]);
        }
        $storedSummary = (string)($summaryData['generated_summary'] ?? '');
        $storedOriginal = (string)($summaryData['original_text'] ?? '');
        
        if ($storedOriginal !== '') {
            $textToAnalyze = $storedOriginal;
        } else {
            $textToAnalyze = Formatter::toOverallSummaryTextFromStoredSummary($storedSummary);
        }
    } elseif ($directText !== '') {
        // If it's a URL, resolve it
        if (preg_match('~^https?://~i', $directText)) {
            $sourceResolver = new SourceResolver();
            $resolved = $sourceResolver->resolve($directText);
            $textToAnalyze = $resolved['text'];
        } else {
            $textToAnalyze = $directText;
        }
    }

    if ($textToAnalyze === '' && $filePath === '') {
        sendNutshellJsonResponse(400, [
            'success' => false,
            'message' => 'Please provide article text, URL, or upload a document.',
        ]);
    }

    $bridge = new LocalPythonBridge();
    $payload = [
        'text' => $textToAnalyze,
        'file_path' => $filePath,
    ];

    $result = $bridge->nutshell($payload, 45);

    sendNutshellJsonResponse(200, [
        'success' => true,
        'nutshell' => $result['nutshell'] ?? 'In a nutshell, the document explains its core concepts.',
        'word_count' => $result['word_count'] ?? 0,
        'title' => $result['title'] ?? 'Document',
    ]);
} catch (\Throwable $e) {
    sendNutshellJsonResponse(500, [
        'success' => false,
        'message' => 'Failed to generate nutshell: ' . $e->getMessage(),
    ]);
} finally {
    if ($temporaryFilePathToClean !== '' && file_exists($temporaryFilePathToClean)) {
        @unlink($temporaryFilePathToClean);
    }
}

