<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/HistoryHandler.php';

use App\Src\Controllers\HistoryHandler;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'error' => 'GET request required.',
    ]);
    exit;
}

$summaryId = $_GET['id'] ?? null;
if (!is_numeric($summaryId) || (int)$summaryId < 1) {
    http_response_code(400);
    echo json_encode([
        'error' => 'A valid summary identifier is required.',
    ]);
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$guestToken = $_SESSION['guest_token'] ?? null;
$shareToken = isset($_GET['share']) && is_string($_GET['share']) ? trim($_GET['share']) : null;
$summaryData = HistoryHandler::getSummaryById((int)$summaryId, $userId, $guestToken, $shareToken);

if (!$summaryData || isset($summaryData['error'])) {
    http_response_code(404);
    echo json_encode([
        'error' => 'Summary not found or access denied.',
    ]);
    exit;
}

echo json_encode([
    'status' => $summaryData['status'] ?? 'completed',
    'error_message' => $summaryData['error_message'] ?? null,
]);
