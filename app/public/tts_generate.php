<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/HistoryHandler.php';
require_once __DIR__ . '/../src/Services/BarkTtsService.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Controllers\HistoryHandler;
use App\Src\Services\BarkTtsService;
use App\Src\Utils\Formatter;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'POST request required.',
    ]);
    exit;
}

$rawInput = file_get_contents('php://input', false, null, 0, 32768);
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request payload.',
    ]);
    exit;
}

if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Refresh the page and try again.',
    ]);
    exit;
}

$summaryId = $input['summary_id'] ?? null;
if (!is_numeric($summaryId) || (int)$summaryId < 1) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'A valid summary identifier is required.',
    ]);
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$guestToken = $_SESSION['guest_token'] ?? null;
if (!$userId && !$guestToken) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Please log in or use an active guest session to generate audio for this summary.',
    ]);
    exit;
}

$shareToken = $input['share_token'] ?? null;
if (!is_string($shareToken)) {
    $shareToken = null;
}

$summaryData = HistoryHandler::getSummaryById((int)$summaryId, $userId, $guestToken, $shareToken);

if (!$summaryData || isset($summaryData['error'])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Summary not found or access denied.',
    ]);
    exit;
}

$summaryStatus = $summaryData['status'] ?? 'completed';
if (in_array($summaryStatus, ['pending', 'processing'], true)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Summary is still processing. Please try again in a moment.',
    ]);
    exit;
}

if ($summaryStatus === 'failed') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Audio is unavailable for failed summaries.',
    ]);
    exit;
}

$ttsService = new BarkTtsService();
$requestedLanguage = BarkTtsService::normalizeLanguageCode($input['language'] ?? 'en');
$requestedText = isset($input['text']) && is_string($input['text'])
    ? BarkTtsService::normalizeText($input['text'])
    : '';

$summaryText = Formatter::toOverallSummaryTextFromStoredSummary((string)($summaryData['generated_summary'] ?? ''));
$textForAudio = $requestedText !== '' ? $requestedText : BarkTtsService::normalizeText($summaryText);
$validationError = $ttsService->validateText($textForAudio);

if ($validationError !== null) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $validationError,
    ]);
    exit;
}

try {
    $ttsResult = $ttsService->generate($textForAudio, $requestedLanguage);
    echo json_encode([
        'success' => true,
        'message' => $ttsResult['cached'] ? 'Using cached audio.' : $ttsResult['message'],
        'audio_url' => $ttsService->buildAudioProxyUrl((int)$summaryId, $ttsResult['file_name'], $shareToken),
        'cached' => $ttsResult['cached'],
        'text_used' => $textForAudio,
        'language' => $ttsResult['language'],
    ]);
} catch (\InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
} catch (\RuntimeException $exception) {
    error_log('[TTS] Generation failed: ' . $exception->getMessage());
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Audio generation failed. Please try again later.',
    ]);
}
