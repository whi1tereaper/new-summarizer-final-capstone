<?php
declare(strict_types=1);

// Document Q&A API endpoint: grounds questions directly in source document passages.

require_once __DIR__ . '/../../src/whitereaper.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Controllers/HistoryHandler.php';
require_once __DIR__ . '/../../src/Services/LocalPythonBridge.php';
require_once __DIR__ . '/../../src/Utils/validation.php';

use App\Src\Controllers\HistoryHandler;
use App\Src\Services\LocalPythonBridge;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. POST is required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input', false, null, 0, 65536);
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON request payload.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$summaryId = isset($input['summary_id']) && is_numeric($input['summary_id']) ? (int)$input['summary_id'] : 0;
if ($summaryId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid summary_id is required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$question = isset($input['question']) && is_string($input['question']) ? trim($input['question']) : '';
if (mb_strlen($question) < 3) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a question with at least 3 characters.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($question) > 400) {
    $question = mb_substr($question, 0, 400);
}

// Security: Verify viewer authorization against the summary artifact
$userId = $_SESSION['user_id'] ?? null;
$guestToken = $_SESSION['guest_token'] ?? null;
$shareToken = isset($input['share_token']) && is_string($input['share_token']) ? trim($input['share_token']) : null;

$summaryData = HistoryHandler::getSummaryById($summaryId, $userId, $guestToken, $shareToken);
if (!$summaryData || isset($summaryData['error'])) {
    http_response_code(404);
    echo json_encode(['error' => 'Summary not found or access denied.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$originalText = trim((string)($summaryData['original_text'] ?? ''));
if ($originalText === '') {
    // Attempt fallback from generated_summary JSON if available
    $storedJson = json_decode((string)($summaryData['generated_summary'] ?? ''), true);
    if (is_array($storedJson)) {
        $originalText = trim((string)($storedJson['raw_text'] ?? ($storedJson['cleaned_text'] ?? '')));
    }
}

if ($originalText === '') {
    http_response_code(400);
    echo json_encode([
        'found' => false,
        'question' => $question,
        'answer' => 'Source document text is unavailable for this summary.',
        'passages' => [],
        'confidence' => 'insufficient_evidence',
        'message' => 'The original text could not be retrieved from the summary record.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $bridge = new LocalPythonBridge();
    $qaResponse = $bridge->qa([
        'question' => $question,
        'text' => $originalText,
    ], 30);

    echo json_encode([
        'found' => (bool)($qaResponse['found'] ?? false),
        'question' => $question,
        'answer' => (string)($qaResponse['answer'] ?? ''),
        'passages' => is_array($qaResponse['passages'] ?? null) ? $qaResponse['passages'] : [],
        'confidence' => (string)($qaResponse['confidence'] ?? 'moderate'),
        'message' => (string)($qaResponse['message'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('[DocumentQA] Answering failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'An error occurred while analyzing the document. Please try again.',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
