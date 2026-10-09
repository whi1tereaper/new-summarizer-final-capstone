<?php
// Translate saved summaries through the local Python worker.

require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Services\LocalPythonBridge;

header('Content-Type: application/json');

if (empty($_SESSION)) {
    http_response_code(401);
    echo json_encode(['error' => 'Session required.']);
    exit;
}

$rawInput = file_get_contents('php://input', false, null, 0, 65536);
$input = json_decode($rawInput, true);

if (!is_array($input) || empty($input['text']) || !is_string($input['text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No text provided for translation.']);
    exit;
}

if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Refresh the page and try again.']);
    exit;
}

$text = $input['text'];
if (mb_strlen($text) > 50000) {
    http_response_code(400);
    echo json_encode(['error' => 'Text too long for translation.']);
    exit;
}

$allowedLangs = ['tl', 'en'];
$targetLang = $input['target_lang'] ?? 'tl';
if (!is_string($targetLang) || !in_array($targetLang, $allowedLangs, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported target language.']);
    exit;
}

try {
    $decoded = (new LocalPythonBridge())->translate($text, $targetLang, 60);
} catch (\RuntimeException $exception) {
    error_log('[TranslateProxy] Translation worker error: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Filipino translation is temporarily unavailable. Please try again shortly.']);
    exit;
}

$translated = isset($decoded['translated']) && is_string($decoded['translated'])
    ? trim($decoded['translated'])
    : '';
if ($translated === '') {
    error_log('[TranslateProxy] Translation worker returned an empty response.');
    http_response_code(502);
    echo json_encode(['error' => 'The translation service returned no Filipino text. Please try again.']);
    exit;
}

echo json_encode([
    'translated' => $translated,
    'target_lang' => $decoded['target_lang'] ?? $targetLang,
    'warning' => null,
    'used_fallback' => false,
]);
