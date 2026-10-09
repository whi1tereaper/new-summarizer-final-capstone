<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Services/FeedbackService.php';
require_once __DIR__ . '/../app/src/Controllers/FeedbackHandler.php';

use App\Src\Database;
use App\Src\Services\FeedbackService;
use App\Src\Controllers\FeedbackHandler;

$db = Database::getInstance()->getConnection();
$service = new FeedbackService();

echo "Running Part 1 Rating & Anonymous Comments Verification Suite...\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $condition, string $testName): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$testName}\n";
        $failCount++;
    }
}

// 1. Create a dedicated test summary for this test guest token
$testGuestToken = 'test_guest_token_' . bin2hex(random_bytes(8));
$insertSummaryStmt = $db->prepare('
    INSERT INTO summaries (user_id, guest_token, article_title, input_type, summary_style, summary_length, processing_time, status)
    VALUES (NULL, ?, "Test Rating Article", "text", "standard_paragraph", "balanced", 0.5, "completed")
');
$insertSummaryStmt->execute([$testGuestToken]);
$testSummaryId = (int)$db->lastInsertId();

// Ensure clean state for this test guest token on this summary
$db->prepare('DELETE FROM feedback WHERE summary_id = ? AND guest_token = ?')->execute([$testSummaryId, $testGuestToken]);

// Test 1: Submit rating without comment
$res1 = $service->submitFeedback($testSummaryId, 5, null, $testGuestToken, null, '');
assertCondition(isset($res1['success']) && $res1['success'] === true, 'Submit rating with empty string comment succeeds');

$checkStmt = $db->prepare('SELECT rating, comment, name, email FROM feedback WHERE summary_id = ? AND guest_token = ?');
$checkStmt->execute([$testSummaryId, $testGuestToken]);
$row1 = $checkStmt->fetch(PDO::FETCH_ASSOC);
assertCondition($row1 && (int)$row1['rating'] === 5 && $row1['comment'] === null, 'Feedback row stored rating 5 with NULL comment');

// Test 2: Submit rating with valid comment
$commentText = 'This is a helpful summary of key points.';
$res2 = $service->submitFeedback($testSummaryId, 4, null, $testGuestToken, null, $commentText);
assertCondition(isset($res2['success']) && $res2['success'] === true, 'Submit rating with 4 stars and comment succeeds');

$checkStmt->execute([$testSummaryId, $testGuestToken]);
$row2 = $checkStmt->fetch(PDO::FETCH_ASSOC);
assertCondition($row2 && (int)$row2['rating'] === 4 && $row2['comment'] === $commentText, 'Feedback row updated rating to 4 and stored comment');

// Test 3: Validate > 500 characters comment rejection
$longComment = str_repeat('a', 501);
$res3 = $service->submitFeedback($testSummaryId, 4, null, $testGuestToken, null, $longComment);
assertCondition(isset($res3['error']) && str_contains($res3['error'], '500'), 'Comment exceeding 500 characters is rejected');

// Test 4: FeedbackHandler forwards comment
$res4 = FeedbackHandler::submitFeedback($testSummaryId, 5, null, $testGuestToken, null, 'Updated via Handler');
assertCondition(isset($res4['success']) && $res4['success'] === true, 'FeedbackHandler::submitFeedback forwards comment correctly');

// Test 5: Verify anonymity in getAllFeedbackForAdmin()
$adminFeed = $service->getAllFeedbackForAdmin();
assertCondition(is_array($adminFeed), 'getAllFeedbackForAdmin returns array');

$foundTestRow = false;
$noIdentifiersExposed = true;
foreach ($adminFeed as $fb) {
    if (!isset($fb['guest_token']) && !isset($fb['user_id'])) {
        // guest_token and user_id must NOT be present in query output
    } else {
        $noIdentifiersExposed = false;
    }
    if ((int)$fb['summary_id'] === $testSummaryId && ($fb['comment'] ?? '') === 'Updated via Handler') {
        $foundTestRow = true;
        // Verify account_username and account_email are null for anonymous ratings
        if ($fb['account_username'] !== null || $fb['account_email'] !== null) {
            $noIdentifiersExposed = false;
        }
    }
}
assertCondition($noIdentifiersExposed, 'Admin feedback listing never exposes guest_token, user_id, or user accounts for anonymous submissions');
assertCondition($foundTestRow, 'Admin feedback listing contains the submitted comment');

// Test 6: Verify template partial markup
$ratingStats = ['count' => 3, 'avg' => 4.5];
$existingRating = null;
ob_start();
require __DIR__ . '/../app/public/partials/rating-widget.php';
$markup = ob_get_clean();

assertCondition(str_contains($markup, 'id="feedback-form-container"'), 'Widget partial contains #feedback-form-container');
assertCondition(str_contains($markup, 'id="star-row"'), 'Widget partial contains #star-row');
assertCondition(str_contains($markup, 'id="feedback-comment-block"'), 'Widget partial contains #feedback-comment-block');
assertCondition(str_contains($markup, 'id="feedback-comment"'), 'Widget partial contains #feedback-comment');
assertCondition(str_contains($markup, 'maxlength="500"'), 'Widget partial textarea has maxlength="500"');
assertCondition(str_contains($markup, 'id="feedback-counter"'), 'Widget partial contains #feedback-counter');
assertCondition(str_contains($markup, 'id="feedback-submit-btn"'), 'Widget partial contains #feedback-submit-btn');
assertCondition(str_contains($markup, 'id="feedback-confirmed"'), 'Widget partial contains #feedback-confirmed');
assertCondition(str_contains($markup, 'Thanks for your feedback'), 'Widget partial contains "Thanks for your feedback"');

// Clean up test feedback row and summary
$db->prepare('DELETE FROM feedback WHERE summary_id = ?')->execute([$testSummaryId]);
$db->prepare('DELETE FROM summaries WHERE id = ?')->execute([$testSummaryId]);

echo "\nSummary: {$passCount} passed, {$failCount} failed.\n";
if ($failCount > 0) {
    exit(1);
}
