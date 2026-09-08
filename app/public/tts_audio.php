<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/HistoryHandler.php';
require_once __DIR__ . '/../src/Services/BarkTtsService.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';

use App\Src\Controllers\HistoryHandler;
use App\Src\Services\BarkTtsService;
use App\Src\Utils\Formatter;

$summaryId = $_GET['summary_id'] ?? null;
$fileName = $_GET['file'] ?? '';

if (!is_numeric($summaryId) || (int)$summaryId < 1 || !is_string($fileName)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Audio not found.';
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$guestToken = $_SESSION['guest_token'] ?? null;
if (!$userId && !$guestToken) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Please log in or use an active guest session to access audio.';
    exit;
}

$shareToken = isset($_GET['share']) && is_string($_GET['share']) ? trim($_GET['share']) : null;
$summaryData = HistoryHandler::getSummaryById((int)$summaryId, $userId, $guestToken, $shareToken);

if (!$summaryData || isset($summaryData['error'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Audio not found.';
    exit;
}

$summaryStatus = $summaryData['status'] ?? 'completed';
if (in_array($summaryStatus, ['pending', 'processing', 'failed'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Audio not found.';
    exit;
}

$summaryText = Formatter::toOverallSummaryTextFromStoredSummary((string)($summaryData['generated_summary'] ?? ''));
$summaryText = BarkTtsService::normalizeText($summaryText);
if ($summaryText === '' && trim($fileName) === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Audio not found.';
    exit;
}

$safeFileName = trim($fileName);
$accessToken = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';

if ($accessToken !== '') {
    if (!BarkTtsService::verifyAudioAccessToken((int)$summaryId, $safeFileName, $accessToken, $shareToken)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Audio not found.';
        exit;
    }
} elseif (!BarkTtsService::matchesExpectedFileNameForText($summaryText, $safeFileName, 'en')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Audio not found.';
    exit;
}

$ttsService = new BarkTtsService();

try {
    $audioResponse = $ttsService->fetchAudioStream($safeFileName, $_SERVER['HTTP_RANGE'] ?? null);
} catch (\InvalidArgumentException $exception) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Audio not found.';
    exit;
} catch (\RuntimeException $exception) {
    error_log('[TTS] Audio fetch failed: ' . $exception->getMessage());
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Audio temporarily unavailable.';
    exit;
}

if (!in_array($audioResponse['status'], [200, 206], true)) {
    http_response_code($audioResponse['status'] === 404 ? 404 : 502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $audioResponse['status'] === 404 ? 'Audio not found.' : 'Audio temporarily unavailable.';
    exit;
}

http_response_code($audioResponse['status']);
header('Content-Type: ' . $audioResponse['content_type']);

$passthroughHeaders = [
    'accept-ranges',
    'cache-control',
    'content-length',
    'content-range',
    'etag',
    'last-modified',
];

foreach ($passthroughHeaders as $headerName) {
    if (isset($audioResponse['headers'][$headerName])) {
        header(ucwords($headerName, '-') . ': ' . $audioResponse['headers'][$headerName]);
    }
}

echo $audioResponse['body'];
