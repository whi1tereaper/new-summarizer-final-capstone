<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Controllers/FeedbackHandler.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

use App\Src\Controllers\FeedbackHandler;

header('Content-Type: application/json');

try {
    $feedbacks = FeedbackHandler::getAllFeedbackForAdmin();
    $stats = FeedbackHandler::getSystemFeedbackStats();
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'feedbacks' => $feedbacks,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    error_log('[Admin Feedback Feed] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load feed']);
}
