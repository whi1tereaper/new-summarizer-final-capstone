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
require_once __DIR__ . '/../src/Services/RateLimiter.php';
require_once __DIR__ . '/../src/Controllers/FeedbackHandler.php';

use App\Src\Controllers\FeedbackHandler;
use App\Src\Services\RateLimiter;

function isStrictPositiveInt(mixed $val): bool
{
    if (is_int($val)) {
        return $val > 0;
    }
    if (is_string($val) && preg_match('/^[1-9][0-9]*$/', trim($val)) === 1) {
        return true;
    }
    return false;
}

function isStrictRatingInt(mixed $val): bool
{
    if (is_int($val)) {
        return $val >= 1 && $val <= 5;
    }
    if (is_string($val) && in_array(trim($val), ['1', '2', '3', '4', '5'], true)) {
        return true;
    }
    return false;
}

// Support both JSON requests and standard form posts.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isAjax = str_contains($contentType, 'application/json');

if ($isAjax) {
    // 1. Request size validation (max 8KB)
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 8192) {
        http_response_code(413);
        echo json_encode(['error' => 'Payload too large.']);
        exit;
    }

    $rawInput = file_get_contents('php://input', false, null, 0, 8192);
    $body = json_decode($rawInput, true);

    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body.']);
        exit;
    }

    // 2. CSRF token validation
    $csrfToken = $body['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Refresh the page and try again.']);
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

    // 3. Server-side Rate Limiting (10 feedback submissions per minute)
    // Limits both session identifier and client IP against automated abuse
    $rateLimiter = new RateLimiter();
    $clientIp = RateLimiter::getClientIp();
    $sessionKey = $userId !== null ? 'user:' . $userId : 'guest:' . $guestToken;
    $ipKey = 'ip:' . $clientIp;

    if ($rateLimiter->isLimited('feedback', $sessionKey) || $rateLimiter->isLimited('feedback', $ipKey)) {
        $retryAfter = max(
            $rateLimiter->getRetryAfter('feedback', $sessionKey),
            $rateLimiter->getRetryAfter('feedback', $ipKey)
        );
        http_response_code(429);
        echo json_encode([
            'error' => 'Too many feedback submissions. Please wait ' . $retryAfter . ' seconds before trying again.'
        ]);
        exit;
    }
    $rateLimiter->recordAttempt('feedback', $sessionKey);
    $rateLimiter->recordAttempt('feedback', $ipKey);

    // 4. Strict integer validation: summary_id and rating
    if (!isset($body['summary_id']) || !isStrictPositiveInt($body['summary_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid summary reference.']);
        exit;
    }

    if (!isset($body['rating']) || !isStrictRatingInt($body['rating'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Rating must be an integer between 1 and 5.']);
        exit;
    }

    $summaryId = (int)$body['summary_id'];
    $rating    = (int)$body['rating'];

    // 5. Input validation: comment (max 500 Unicode chars)
    $comment = null;
    if (isset($body['comment'])) {
        if (!is_string($body['comment'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid comment format.']);
            exit;
        }
        $comment = trim($body['comment']);
        if (mb_strlen($comment, 'UTF-8') > 500) {
            http_response_code(400);
            echo json_encode(['error' => 'Comment cannot exceed 500 characters.']);
            exit;
        }
        if ($comment === '') {
            $comment = null;
        }
    }

    // 6. Input validation: reason tags (strictly allowed only for ratings <= 3)
    $reasons = null;
    if (isset($body['reasons'])) {
        if (!is_array($body['reasons'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid reasons format.']);
            exit;
        }
        if (!empty($body['reasons']) && $rating > 3) {
            http_response_code(400);
            echo json_encode(['error' => 'Improvement reasons are only valid for ratings of 3 stars or below.']);
            exit;
        }
        $reasons = $body['reasons'];
    }

    $result = FeedbackHandler::submitFeedback($summaryId, $rating, $userId, $guestToken, $shareToken, $comment, $reasons);

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

    $userId = $_SESSION['user_id'] ?? null;
    $guestToken = $_SESSION['guest_token'] ?? null;

    if (!$userId && !$guestToken) {
        $_SESSION['flash_error'] = 'You must be logged in or have an active session to rate.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    $rateLimiter = new RateLimiter();
    $clientIp = RateLimiter::getClientIp();
    $sessionKey = $userId !== null ? 'user:' . $userId : 'guest:' . $guestToken;
    $ipKey = 'ip:' . $clientIp;

    if ($rateLimiter->isLimited('feedback', $sessionKey) || $rateLimiter->isLimited('feedback', $ipKey)) {
        $_SESSION['flash_error'] = 'Too many feedback submissions. Please wait a moment.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }
    $rateLimiter->recordAttempt('feedback', $sessionKey);
    $rateLimiter->recordAttempt('feedback', $ipKey);

    $rawSummaryId = $_POST['summary_id'] ?? null;
    if (!isStrictPositiveInt($rawSummaryId)) {
        $_SESSION['flash_error'] = 'Invalid summary reference.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }
    $summaryId = (int)$rawSummaryId;

    $data = [
        'name'       => $_POST['name'] ?? '',
        'email'      => $_POST['email'] ?? '',
        'impression' => $_POST['impression'] ?? '',
        'comment'    => $_POST['comment'] ?? '',
    ];

    $result = FeedbackHandler::submitFullFeedback($summaryId, $data, $userId, $guestToken);

    if (isset($result['error'])) {
        $_SESSION['flash_error'] = $result['error'];
    } else {
        $_SESSION['flash_success'] = 'Thank you for your feedback!';
    }

    header('Location: result.php?id=' . $summaryId);
    exit;
}
