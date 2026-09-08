<?php
// Save feedback from the result page.

require_once __DIR__ . '/../src/whitereaper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';
require_once __DIR__ . '/../src/Controllers/FeedbackHandler.php';

use App\Src\Controllers\FeedbackHandler;

// Support both JSON requests and standard form posts.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isAjax = str_contains($contentType, 'application/json');

if ($isAjax) {
    $rawInput = file_get_contents('php://input', false, null, 0, 4096);
    $body = json_decode($rawInput, true);

    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body.']);
        exit;
    }

    $csrfToken = $body['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Refresh the page and try again.']);
        exit;
    }

    if (!isset($body['summary_id']) || !is_numeric($body['summary_id'])
        || !isset($body['rating']) || !is_numeric($body['rating'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input.']);
        exit;
    }

    $summaryId = (int)$body['summary_id'];
    $rating    = (int)$body['rating'];

    if ($summaryId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid summary reference.']);
        exit;
    }

    if ($rating < 1 || $rating > 5) {
        http_response_code(400);
        echo json_encode(['error' => 'Rating must be between 1 and 5.']);
        exit;
    }

    $userId     = $_SESSION['user_id'] ?? null;
    $guestToken = $_SESSION['guest_token'] ?? null;
    $shareToken = $body['share_token'] ?? null;

    if (!is_string($shareToken)) {
        $shareToken = null;
    }

    if (!$userId && !$guestToken) {
        http_response_code(401);
        echo json_encode(['error' => 'You must be logged in or have an active session to rate.']);
        exit;
    }

    $result = FeedbackHandler::submitFeedback($summaryId, $rating, $userId, $guestToken, $shareToken);

    if (isset($result['error'])) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Rating saved.']);
    exit;
} else {
    // Handle the full feedback form submission.
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $_SESSION['flash_error'] = 'Invalid session. Please try again.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    $summaryId = (int)($_POST['summary_id'] ?? 0);
    if ($summaryId <= 0) {
        $_SESSION['flash_error'] = 'Invalid summary reference.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    $data = [
        'name'       => $_POST['name'] ?? '',
        'email'      => $_POST['email'] ?? '',
        'impression' => $_POST['impression'] ?? '',
        'comment'    => $_POST['comment'] ?? '',
    ];

    $userId = $_SESSION['user_id'] ?? null;
    $guestToken = $_SESSION['guest_token'] ?? null;

    $result = FeedbackHandler::submitFullFeedback($summaryId, $data, $userId, $guestToken);

    if (isset($result['error'])) {
        $_SESSION['flash_error'] = $result['error'];
    } else {
        $_SESSION['flash_success'] = 'Thank you for your feedback!';
    }

    header('Location: result.php?id=' . $summaryId);
    exit;
}
