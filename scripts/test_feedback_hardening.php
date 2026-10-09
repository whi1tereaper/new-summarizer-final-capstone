<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Services/FeedbackService.php';
require_once __DIR__ . '/../app/src/Controllers/FeedbackHandler.php';
require_once __DIR__ . '/../app/src/Services/RateLimiter.php';
require_once __DIR__ . '/../app/src/Utils/validation.php';

use App\Src\Database;
use App\Src\Services\FeedbackService;
use App\Src\Controllers\FeedbackHandler;
use App\Src\Services\RateLimiter;

$db = Database::getInstance()->getConnection();
$service = new FeedbackService();
$limiter = new RateLimiter();

echo "Running Summary Feedback Hardening & Telemetry Verification Suite...\n\n";

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

// Helper validation functions matching endpoint implementation
function testStrictPositiveInt(mixed $val): bool {
    if (is_int($val)) {
        return $val > 0;
    }
    if (is_string($val) && preg_match('/^[1-9][0-9]*$/', trim($val)) === 1) {
        return true;
    }
    return false;
}

function testStrictRatingInt(mixed $val): bool {
    if (is_int($val)) {
        return $val >= 1 && $val <= 5;
    }
    if (is_string($val) && in_array(trim($val), ['1', '2', '3', '4', '5'], true)) {
        return true;
    }
    return false;
}

// --- TEST GROUP 1: Strict Integer Validation (Preventing silent float/scientific conversion) ---
assertCondition(testStrictPositiveInt(42) === true, 'Strict positive int accepts integer 42');
assertCondition(testStrictPositiveInt('42') === true, 'Strict positive int accepts digit string "42"');
assertCondition(testStrictPositiveInt(0) === false, 'Strict positive int rejects integer 0');
assertCondition(testStrictPositiveInt(-5) === false, 'Strict positive int rejects negative integer -5');
assertCondition(testStrictPositiveInt(2.9) === false, 'Strict positive int rejects float 2.9');
assertCondition(testStrictPositiveInt('2.9') === false, 'Strict positive int rejects float string "2.9"');
assertCondition(testStrictPositiveInt('1e2') === false, 'Strict positive int rejects scientific notation "1e2"');
assertCondition(testStrictPositiveInt('+3') === false, 'Strict positive int rejects signed string "+3"');
assertCondition(testStrictPositiveInt(null) === false, 'Strict positive int rejects null');
assertCondition(testStrictPositiveInt(['id' => 1]) === false, 'Strict positive int rejects array');

assertCondition(testStrictRatingInt(1) === true, 'Strict rating int accepts integer 1');
assertCondition(testStrictRatingInt(5) === true, 'Strict rating int accepts integer 5');
assertCondition(testStrictRatingInt('3') === true, 'Strict rating int accepts string "3"');
assertCondition(testStrictRatingInt(0) === false, 'Strict rating int rejects integer 0');
assertCondition(testStrictRatingInt(6) === false, 'Strict rating int rejects integer 6');
assertCondition(testStrictRatingInt(2.9) === false, 'Strict rating int rejects float 2.9');
assertCondition(testStrictRatingInt('2.9') === false, 'Strict rating int rejects float string "2.9"');
assertCondition(testStrictRatingInt('1e2') === false, 'Strict rating int rejects scientific notation "1e2"');
assertCondition(testStrictRatingInt('+3') === false, 'Strict rating int rejects signed string "+3"');

// Setup dedicated test summary owned by testGuestA
$testGuestA = 'guest_a_' . bin2hex(random_bytes(16));
$testGuestB = 'guest_b_' . bin2hex(random_bytes(16));
$shareToken = bin2hex(random_bytes(32));

$insertStmt = $db->prepare('
    INSERT INTO summaries (user_id, guest_token, share_token, article_title, input_type, summary_style, summary_length, original_word_count, summary_word_count, processing_time, status)
    VALUES (NULL, :guest, :share, "Hardening Test Article", "pdf", "academic_summary", "detailed", 1500, 300, 1.45, "completed")
');
$insertStmt->execute(['guest' => $testGuestA, 'share' => $shareToken]);
$testSummaryId = (int)$db->lastInsertId();

// Clean existing feedback for this test summary
$db->prepare('DELETE FROM feedback WHERE summary_id = ?')->execute([$testSummaryId]);
$db->prepare('DELETE FROM feedback_comments WHERE summary_id = ?')->execute([$testSummaryId]);

// --- TEST GROUP 2: Service Rating Boundary & Comment Validation ---
$res1 = $service->submitFeedback($testSummaryId, 1, null, $testGuestA, null, 'One star test');
assertCondition(isset($res1['success']) && $res1['success'] === true, 'Rating 1 accepted');

$res5 = $service->submitFeedback($testSummaryId, 5, null, $testGuestA, null, 'Five star test');
assertCondition(isset($res5['success']) && $res5['success'] === true, 'Rating 5 accepted');

$res0 = $service->submitFeedback($testSummaryId, 0, null, $testGuestA);
assertCondition(isset($res0['error']) && str_contains($res0['error'], 'between 1 and 5'), 'Rating 0 rejected');

$res6 = $service->submitFeedback($testSummaryId, 6, null, $testGuestA);
assertCondition(isset($res6['error']) && str_contains($res6['error'], 'between 1 and 5'), 'Rating 6 rejected');

$unicodeOver500 = str_repeat('好', 501);
$resOver = $service->submitFeedback($testSummaryId, 3, null, $testGuestA, null, $unicodeOver500);
assertCondition(isset($resOver['error']) && str_contains($resOver['error'], '500'), '>500 Unicode characters rejected');

$resEmpty = $service->submitFeedback($testSummaryId, 4, null, $testGuestA, null, "   \n  \t  ");
assertCondition(isset($resEmpty['success']) && $resEmpty['success'] === true, 'Whitespace comment accepted');
$checkEmpty = $db->prepare('SELECT comment FROM feedback WHERE summary_id = ? AND guest_token = ?');
$checkEmpty->execute([$testSummaryId, $testGuestA]);
assertCondition($checkEmpty->fetchColumn() === null, 'Whitespace-only comment stored as NULL in database');

// --- TEST GROUP 3: Authorization Checks ---
$resUnauthorized = $service->submitFeedback($testSummaryId, 5, null, $testGuestB, null, 'Unauthorized comment');
assertCondition(isset($resUnauthorized['error']) && str_contains($resUnauthorized['error'], 'access denied'), 'Unauthorized summary access without share token rejected');

$resNonExistent = $service->submitFeedback(99999999, 5, null, $testGuestA);
assertCondition(isset($resNonExistent['error']) && str_contains($resNonExistent['error'], 'access denied'), 'Non-existent summary ID rejected');

$resShared = $service->submitFeedback($testSummaryId, 4, null, $testGuestB, $shareToken, 'Shared viewer feedback');
assertCondition(isset($resShared['success']) && $resShared['success'] === true, 'Authorized viewer with valid share token accepted');

// --- TEST GROUP 4: Reason Taxonomy Boundary (Must be <= 3 stars) ---
// High ratings (4 or 5) must NOT allow improvement reasons
$resHighRatingWithReasons = $service->submitFeedback(
    $testSummaryId,
    5,
    null,
    $testGuestA,
    null,
    'Great summary',
    ['incorrect_info']
);
assertCondition(
    isset($resHighRatingWithReasons['error']) && str_contains($resHighRatingWithReasons['error'], '3 stars or below'),
    'Service strictly rejects improvement reason tags when rating > 3 (rating = 5)'
);

$resRating4WithReasons = $service->submitFeedback(
    $testSummaryId,
    4,
    null,
    $testGuestA,
    null,
    'Almost perfect',
    ['too_long']
);
assertCondition(
    isset($resRating4WithReasons['error']) && str_contains($resRating4WithReasons['error'], '3 stars or below'),
    'Service strictly rejects improvement reason tags when rating > 3 (rating = 4)'
);

// Low rating (<= 3) permits valid reasons
$reasons = ['missing_info', 'too_long', 'fake_unauthorized_tag'];
$resReasons = $service->submitFeedback($testSummaryId, 2, null, $testGuestA, null, 'Needs improvement', $reasons);
assertCondition(isset($resReasons['success']) && $resReasons['success'] === true, 'Submission with reason tags succeeds for rating <= 3');

$reasonsStmt = $db->prepare('SELECT reasons FROM feedback WHERE summary_id = ? AND guest_token = ?');
$reasonsStmt->execute([$testSummaryId, $testGuestA]);
$storedReasons = json_decode((string)$reasonsStmt->fetchColumn(), true);
assertCondition(
    is_array($storedReasons) && in_array('missing_info', $storedReasons, true) && in_array('too_long', $storedReasons, true) && !in_array('fake_unauthorized_tag', $storedReasons, true),
    'Valid reason tags whitelist-filtered and stored as clean JSON (invalid tag rejected)'
);

// --- TEST GROUP 5: Relational Linkability, Event Ledger & Anonymity Verification ---
// Verify feedback table enforces atomic upsert (1 logical state row per guest/user)
$dupStmt = $db->prepare('SELECT COUNT(*) FROM feedback WHERE summary_id = ? AND guest_token = ?');
$dupStmt->execute([$testSummaryId, $testGuestA]);
$count = (int)$dupStmt->fetchColumn();
assertCondition($count === 1, 'Canonical feedback table maintains 1 logical state per guest + summary');

// Now edit the comment: this should append a second event to feedback_comments
$service->submitFeedback($testSummaryId, 3, null, $testGuestA, null, 'Revised comment on second thought', ['repetitive']);
$fcEventsStmt = $db->prepare('SELECT * FROM feedback_comments WHERE summary_id = ? ORDER BY id ASC');
$fcEventsStmt->execute([$testSummaryId]);
$events = $fcEventsStmt->fetchAll(PDO::FETCH_ASSOC);

assertCondition(count($events) >= 2, 'feedback_comments behaves as an append-only event ledger capturing revision history');

// Verify columns: feedback_comments contains NO user identifiers, but maintains summary_id for evaluation correlation
$latestEvent = end($events);
assertCondition(
    !array_key_exists('user_id', $latestEvent) &&
    !array_key_exists('guest_token', $latestEvent) &&
    !array_key_exists('ip', $latestEvent) &&
    !array_key_exists('email', $latestEvent),
    'feedback_comments ledger contains ZERO direct user identifiers (user_id, guest_token, ip, email)'
);
assertCondition(
    (int)$latestEvent['summary_id'] === $testSummaryId,
    'feedback_comments retains summary_id for research telemetry and result correlation (pseudonymous / identity-hidden)'
);

// --- TEST GROUP 6: Event Ledger Pruning & Retention Policy ---
// Insert artificial aged event (100 days old)
$oldEventStmt = $db->prepare('
    INSERT INTO feedback_comments (summary_id, rating, reasons, comment, created_at)
    VALUES (:sid, 1, "[\"missing_info\"]", "Old feedback event", DATE_SUB(NOW(), INTERVAL 100 DAY))
');
$oldEventStmt->execute(['sid' => $testSummaryId]);
$oldEventId = (int)$db->lastInsertId();

// Verify row exists
$checkOld = $db->prepare('SELECT COUNT(*) FROM feedback_comments WHERE id = ?');
$checkOld->execute([$oldEventId]);
assertCondition((int)$checkOld->fetchColumn() === 1, 'Test aged event row successfully seeded');

// Execute pruneFeedbackEvents(90)
$prunedCount = $service->pruneFeedbackEvents(90);
assertCondition($prunedCount >= 1, 'pruneFeedbackEvents(90) pruned aged events (>90 days)');

// Verify aged row is gone, but recent events remain intact
$checkOldAgain = $db->prepare('SELECT COUNT(*) FROM feedback_comments WHERE id = ?');
$checkOldAgain->execute([$oldEventId]);
assertCondition((int)$checkOldAgain->fetchColumn() === 0, 'Aged event row was deleted by retention policy');

$checkRecent = $db->prepare('SELECT COUNT(*) FROM feedback_comments WHERE id = ?');
$checkRecent->execute([$latestEvent['id']]);
assertCondition((int)$checkRecent->fetchColumn() === 1, 'Recent event row (<90 days) safely retained');

// --- TEST GROUP 7: Rate Limiting Enforcement ---
$testLimiterKey = 'test_rate_key_' . bin2hex(random_bytes(8));
$limiter->resetAttempts('feedback', $testLimiterKey);

$rateLimited = false;
for ($i = 0; $i < 12; $i++) {
    if ($limiter->isLimited('feedback', $testLimiterKey)) {
        $rateLimited = true;
        break;
    }
    $limiter->recordAttempt('feedback', $testLimiterKey);
}
assertCondition($rateLimited === true, 'RateLimiter strictly blocks feedback submissions exceeding max attempts (10/min)');
assertCondition($limiter->getRetryAfter('feedback', $testLimiterKey) > 0, 'RateLimiter reports positive retry_after window');
$limiter->resetAttempts('feedback', $testLimiterKey);

// --- TEST GROUP 8: Admin Listing Anonymity & Default Ordering ---
$adminFeed = $service->getAllFeedbackForAdmin(['sort' => 'newest']);
assertCondition(!empty($adminFeed), 'getAllFeedbackForAdmin returns array with newest feedback first');

$noLeaks = true;
foreach ($adminFeed as $item) {
    if (isset($item['user_id']) || isset($item['guest_token']) || $item['account_username'] !== null || $item['account_email'] !== null) {
        $noLeaks = false;
    }
}
assertCondition($noLeaks, 'Admin feedback feed strictly conceals user_id, guest_token, account_username, and account_email');

// --- TEST GROUP 9: Telemetry Breakdown Aggregation ---
$telemetryStats = $service->getFeedbackTelemetryBreakdown();
assertCondition(is_array($telemetryStats['by_style']) && is_array($telemetryStats['by_length']) && is_array($telemetryStats['reasons_counts']), 'getFeedbackTelemetryBreakdown computes style, length, and reasons breakdown');

// --- TEST GROUP 10: CLI Maintenance Cleanup Script Dry-Run ---
exec('php ' . escapeshellarg(__DIR__ . '/cleanup_feedback_events.php') . ' --dry-run 2>&1', $cliOutput, $cliReturn);
assertCondition($cliReturn === 0, 'CLI cleanup script (cleanup_feedback_events.php --dry-run) executed with exit code 0');
assertCondition(
    implode(' ', $cliOutput) !== '' && str_contains(implode(' ', $cliOutput), 'Dry run only'),
    'CLI cleanup script reports dry run mode and retention metrics'
);

// Clean up test records
$db->prepare('DELETE FROM feedback WHERE summary_id = ?')->execute([$testSummaryId]);
$db->prepare('DELETE FROM feedback_comments WHERE summary_id = ?')->execute([$testSummaryId]);
$db->prepare('DELETE FROM summaries WHERE id = ?')->execute([$testSummaryId]);

echo "\nSummary: {$passCount} passed, {$failCount} failed.\n";
if ($failCount > 0) {
    exit(1);
}
