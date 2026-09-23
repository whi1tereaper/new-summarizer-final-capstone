<?php
ob_start();

require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/HistoryHandler.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Services/LocalPythonBridge.php';
require_once __DIR__ . '/../src/Services/FileUploadService.php';
require_once __DIR__ . '/../src/Services/SourceResolver.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Controllers\HistoryHandler;
use App\Src\Database;
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

function ensureNutshellGenerationsTable(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS nutshell_generations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            summary_id INT(11) DEFAULT NULL,
            user_id INT(11) DEFAULT NULL,
            guest_token VARCHAR(64) DEFAULT NULL,
            input_type VARCHAR(20) NOT NULL DEFAULT \'text\',
            nutshell_text TEXT DEFAULT NULL,
            word_count INT UNSIGNED NOT NULL DEFAULT 0,
            source_word_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ng_created (created_at),
            KEY idx_ng_user_created (user_id, created_at),
            KEY idx_ng_summary (summary_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendNutshellJsonResponse(405, [
        'success' => false,
        'message' => 'POST request required.',
    ]);
}

require_once __DIR__ . '/../src/Services/TermsAcceptanceService.php';
if (\App\Src\Services\TermsAcceptanceService::currentUserNeedsAcceptance()) {
    sendNutshellJsonResponse(403, [
        'success' => false,
        'message' => 'You must accept the Terms and Conditions before using the summarizer.',
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
$resolvedSource = null;

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
            $resolvedSource = $sourceResolver->resolve($directText);
            $textToAnalyze = $resolvedSource->getText();
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
    $nutshellText = trim((string)($result['nutshell'] ?? ''));

    if ($nutshellText !== '') {
        $db = Database::getInstance()->getConnection();
        ensureNutshellGenerationsTable($db);
        $statement = $db->prepare(
            'INSERT INTO nutshell_generations (summary_id, user_id, guest_token, input_type, nutshell_text, word_count, source_word_count) VALUES (:summary_id, :user_id, :guest_token, :input_type, :nutshell_text, :word_count, :source_word_count)'
        );
        $statement->execute([
            'summary_id' => $summaryId !== null && (int)$summaryId > 0 ? (int)$summaryId : null,
            'user_id' => $userId,
            'guest_token' => $guestToken,
            'input_type' => $filePath !== '' ? strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION)) : (preg_match('~^https?://~i', $directText) ? 'url' : 'text'),
            'nutshell_text' => $nutshellText,
            'word_count' => str_word_count($nutshellText),
            'source_word_count' => str_word_count($textToAnalyze),
        ]);
    }

    // Persist Nutshell only for an existing, authorized summary record.
    if ($summaryId !== null && (int)$summaryId > 0) {
        if ($nutshellText !== '') {
            try {
                $statement = Database::getInstance()->getConnection()->prepare(
                    'UPDATE summaries SET nutshell_text = :text, nutshell_word_count = :word_count, nutshell_generated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $statement->execute([
                    'text' => $nutshellText,
                    'word_count' => str_word_count($nutshellText),
                    'id' => (int)$summaryId,
                ]);
            } catch (\Throwable $exception) {
                // The generation ledger remains valid when migration 005 is not installed yet.
                error_log('[Nutshell] Could not update legacy summary columns: ' . $exception->getMessage());
            }
        }
    }

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
    $resolvedSource?->cleanup();
}

