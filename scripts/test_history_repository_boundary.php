<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Services/HistoryService.php';

use App\Src\Database;
use App\Src\Services\HistoryService;

$db = Database::getInstance()->getConnection();
$service = new HistoryService();
$passCount = 0;
$failCount = 0;

function assertHistory(string $name, bool $condition): void
{
    global $passCount, $failCount;

    if ($condition) {
        echo "[PASS] {$name}\n";
        $passCount++;
        return;
    }

    echo "[FAIL] {$name}\n";
    $failCount++;
}

$guestToken = bin2hex(random_bytes(32));
$shareToken = bin2hex(random_bytes(16));
$ownerId = 12;

$ownerShareToken = bin2hex(random_bytes(16));
$insertOwnerSummary = $db->prepare(
    'INSERT INTO summaries (user_id, guest_token, share_token, article_title, input_type, summary_style, summary_length, original_word_count, summary_word_count, processing_time, status, created_at)
     VALUES (:user_id, :guest_token, :share_token, :title, :input_type, :summary_style, :summary_length, 1200, 220, 1.25, "completed", NOW())'
);
$insertOwnerSummary->execute([
    'user_id' => $ownerId,
    'guest_token' => null,
    'share_token' => $ownerShareToken,
    'title' => 'History repository validation article (owner)',
    'input_type' => 'text',
    'summary_style' => 'standard_paragraph',
    'summary_length' => 'balanced',
]);
$userSummaryId = (int)$db->lastInsertId();

$insertGuestSummary = $db->prepare(
    'INSERT INTO summaries (user_id, guest_token, share_token, article_title, input_type, summary_style, summary_length, original_word_count, summary_word_count, processing_time, status, created_at)
     VALUES (:user_id, :guest_token, :share_token, :title, :input_type, :summary_style, :summary_length, 950, 180, 1.10, "completed", NOW())'
);
$insertGuestSummary->execute([
    'user_id' => null,
    'guest_token' => $guestToken,
    'share_token' => $shareToken,
    'title' => 'History repository validation article (guest)',
    'input_type' => 'text',
    'summary_style' => 'standard_paragraph',
    'summary_length' => 'balanced',
]);
$guestSummaryId = (int)$db->lastInsertId();

$insertOwnerArtifact = $db->prepare(
    'INSERT INTO summary_artifacts (summary_id, original_text, generated_summary)
     VALUES (:summary_id, :original_text, :generated_summary)'
);
$insertOwnerArtifact->execute([
    'summary_id' => $userSummaryId,
    'original_text' => 'This is the source text used for the repository validation test for the owner account.',
    'generated_summary' => 'This is the generated summary used for the repository validation test for the owner account.',
]);

$insertGuestArtifact = $db->prepare(
    'INSERT INTO summary_artifacts (summary_id, original_text, generated_summary)
     VALUES (:summary_id, :original_text, :generated_summary)'
);
$insertGuestArtifact->execute([
    'summary_id' => $guestSummaryId,
    'original_text' => 'This is the source text used for the repository validation test for the guest-owned summary.',
    'generated_summary' => 'This is the generated summary used for the repository validation test for the guest-owned summary.',
]);

$insertNutshell = $db->prepare(
    'INSERT INTO nutshell_generations (summary_id, user_id, guest_token, input_type, nutshell_text, status, word_count, source_word_count, created_at)
     VALUES (:summary_id, :user_id, :guest_token, :input_type, :nutshell_text, "completed", :word_count, :source_word_count, NOW())'
);
$insertNutshell->execute([
    'summary_id' => $userSummaryId,
    'user_id' => $ownerId,
    'guest_token' => null,
    'input_type' => 'text',
    'nutshell_text' => 'Repository validation nutshell output.',
    'word_count' => 7,
    'source_word_count' => 120,
]);

$historyByUser = $service->getSummaryHistory($ownerId, null, 1, 20);
assertHistory('User-owned summary list returns a populated result set', is_array($historyByUser) && count($historyByUser) >= 1);

$historyByGuest = $service->getSummaryHistory(null, $guestToken, 1, 20);
assertHistory('Guest-owned summary list returns a populated result set', is_array($historyByGuest) && count($historyByGuest) >= 1);

$summaryById = $service->getSummaryById($userSummaryId, $ownerId, null, null);
assertHistory('Owner can fetch a summary by id via the repository-backed path', is_array($summaryById) && (int)($summaryById['id'] ?? 0) === $userSummaryId);

$summaryByGuest = $service->getSummaryById($guestSummaryId, null, $guestToken, null);
assertHistory('Guest can fetch summary by id when the summary is guest-owned', is_array($summaryByGuest) && (int)($summaryByGuest['id'] ?? 0) === $guestSummaryId);

$summaryShared = $service->getSummaryById($guestSummaryId, null, null, $shareToken);
assertHistory('Share token grants authorized access to the summary', is_array($summaryShared) && (int)($summaryShared['id'] ?? 0) === $guestSummaryId);

$summaryUnauthorized = $service->getSummaryById($guestSummaryId, null, null, 'not-the-right-share-token');
assertHistory('Invalid share token is rejected', isset($summaryUnauthorized['error']) && $summaryUnauthorized['error'] === 'Unauthorized access.');

$nutshellHistory = $service->getNutshellHistory($ownerId, null, 20);
assertHistory('Nutshell history can be fetched through the repository-backed service', is_array($nutshellHistory) && count($nutshellHistory) >= 1);

$db->prepare('DELETE FROM nutshell_generations WHERE summary_id IN (?, ?)')->execute([$userSummaryId, $guestSummaryId]);
$db->prepare('DELETE FROM summary_artifacts WHERE summary_id IN (?, ?)')->execute([$userSummaryId, $guestSummaryId]);
$db->prepare('DELETE FROM summaries WHERE id IN (?, ?)')->execute([$userSummaryId, $guestSummaryId]);

echo "\nHistory repository verification: {$passCount} passed, {$failCount} failed.\n";
if ($failCount > 0) {
    exit(1);
}
