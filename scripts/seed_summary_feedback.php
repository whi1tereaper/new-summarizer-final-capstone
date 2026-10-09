<?php
declare(strict_types=1);

/**
 * Summary Feedback Seeder Script
 *
 * Populates realistic feedback ratings, improvement reasons, and anonymous comments
 * across all existing summaries in the database. Populates both the canonical `feedback`
 * table (for user/guest state) and the `feedback_comments` append-only event ledger.
 *
 * Usage:
 *   php scripts/seed_summary_feedback.php           # Seeds missing summaries
 *   php scripts/seed_summary_feedback.php --reset   # Clears and reseeds all summaries
 *   php scripts/seed_summary_feedback.php --dry-run # Previews actions without writing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be executed via the command line interface.\n");
}

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Services/FeedbackService.php';

use App\Src\Database;
use App\Src\Services\FeedbackService;

$isDryRun = in_array('--dry-run', $argv, true);
$isReset  = in_array('--reset', $argv, true);

$positiveComments = [
    'Concise and accurate summary. Captured the core findings effectively.',
    'Very well organized. The executive takeaways saved significant reading time.',
    'Clear and coherent synthesis of the methodology and results.',
    'The breakdown of key points was precise and easy to digest.',
    'High quality extraction. Identified the primary arguments without unnecessary fluff.',
    'Excellent summary. Structured flow made complex sections accessible.',
    'Spot-on summary. Preserved the crucial quantitative conclusions.',
    'Extremely useful overview. Accurately condensed the lengthy paper.',
    'The summary style matched the technical depth required for this document.',
    'Well-balanced synthesis. Captured both the background context and conclusions.',
];

$goodComments = [
    'Good summary overall. Minor details omitted, but the main premise is intact.',
    'Helpful digest. A few terms could have had clearer definitions, but overall solid.',
    'Readable and focused. Captured most of the important takeaways.',
    'Accurate overview. Length was slightly longer than desired, but readable.',
    'Solid condensation of the core sections. Easy to scan and reference.',
    'Very readable output. Good representation of the author’s primary points.',
];

$neutralComments = [
    ['reasons' => ['too_long'], 'comment' => 'A bit verbose in the background section. Could be tightened further.'],
    ['reasons' => ['missing_info'], 'comment' => 'Missed some specific quantitative metrics from the results chapter.'],
    ['reasons' => ['format_mismatch'], 'comment' => 'Format could have been structured better for rapid scanning.'],
    ['reasons' => ['hard_to_understand'], 'comment' => 'A few sentences felt overly complex compared to the source text.'],
    ['reasons' => ['mode_mismatch'], 'comment' => 'Analysis tone felt slightly generic for this academic paper.'],
    ['reasons' => ['too_short'], 'comment' => 'Summary ended abruptly without capturing the full conclusion.'],
];

$lowComments = [
    ['reasons' => ['repetitive', 'too_long'], 'comment' => 'Repeated several opening premises multiple times in the middle sections.'],
    ['reasons' => ['missing_info', 'incorrect_info'], 'comment' => 'Left out crucial sample metrics and mischaracterized the experimental results.'],
    ['reasons' => ['hard_to_understand'], 'comment' => 'Phrasing was disjointed and difficult to parse smoothly.'],
    ['reasons' => ['mode_mismatch', 'missing_info'], 'comment' => 'Did not follow the requested analysis profile and dropped core sections.'],
];

$poorComments = [
    ['reasons' => ['incorrect_info', 'hard_to_understand'], 'comment' => 'Misinterpreted key findings and generated confusing paragraphs.'],
    ['reasons' => ['missing_info', 'repetitive'], 'comment' => 'Completely omitted the final results and looped the introductory remarks.'],
];

try {
    $pdo = Database::getInstance()->getConnection();

    $summariesStmt = $pdo->query('
        SELECT id, user_id, guest_token, article_title, summary_style, summary_length, input_type, created_at 
        FROM summaries 
        ORDER BY id ASC
    ');
    $summaries = $summariesStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalSummaries = count($summaries);
    echo "Found {$totalSummaries} summaries in database.\n";

    if ($totalSummaries === 0) {
        echo "No summaries to seed. Exiting.\n";
        exit(0);
    }

    if ($isReset) {
        if ($isDryRun) {
            echo "[Dry-Run] Would delete all rows from `feedback` and `feedback_comments`.\n";
        } else {
            echo "Resetting existing feedback records...\n";
            $pdo->exec('DELETE FROM feedback_comments');
            $pdo->exec('DELETE FROM feedback');
            echo "Feedback tables cleared.\n";
        }
    }

    $existingFeedbackStmt = $pdo->query('SELECT summary_id, id FROM feedback');
    $existingFeedbackMap = [];
    while ($row = $existingFeedbackStmt->fetch(PDO::FETCH_ASSOC)) {
        $existingFeedbackMap[(int)$row['summary_id']] = (int)$row['id'];
    }

    $seededCount = 0;
    $updatedCount = 0;
    $eventsCount = 0;

    foreach ($summaries as $index => $summary) {
        $sid = (int)$summary['id'];
        $hasFeedback = isset($existingFeedbackMap[$sid]);

        if ($hasFeedback && !$isReset) {
            // Ensure feedback_comments at least has an event row for existing feedback
            $fcCount = (int)$pdo->query("SELECT COUNT(*) FROM feedback_comments WHERE summary_id = {$sid}")->fetchColumn();
            if ($fcCount === 0) {
                if ($isDryRun) {
                    echo "[Dry-Run] Would add missing feedback_comments event row for summary #{$sid}.\n";
                } else {
                    $fbRow = $pdo->query("SELECT rating, reasons, comment, created_at FROM feedback WHERE summary_id = {$sid} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if ($fbRow) {
                        $insFc = $pdo->prepare('
                            INSERT INTO feedback_comments (summary_id, rating, reasons, comment, created_at)
                            VALUES (:sid, :rating, :reasons, :comment, :created_at)
                        ');
                        $insFc->execute([
                            ':sid'        => $sid,
                            ':rating'     => $fbRow['rating'] ?? 5,
                            ':reasons'    => $fbRow['reasons'],
                            ':comment'    => !empty($fbRow['comment']) ? $fbRow['comment'] : 'Helpful summary.',
                            ':created_at' => $fbRow['created_at'] ?? date('Y-m-d H:i:s'),
                        ]);
                        $eventsCount++;
                        $updatedCount++;
                    }
                }
            }
            continue;
        }

        // Deterministic pseudo-random distribution based on summary ID for stability
        $seedVal = ($sid * 997 + 101) % 100;

        if ($seedVal < 45) {
            // 5 stars (45%)
            $rating = 5;
            $reasonsJson = null;
            $comment = $positiveComments[$sid % count($positiveComments)];
        } elseif ($seedVal < 75) {
            // 4 stars (30%)
            $rating = 4;
            $reasonsJson = null;
            $comment = $goodComments[$sid % count($goodComments)];
        } elseif ($seedVal < 88) {
            // 3 stars (13%)
            $rating = 3;
            $choice = $neutralComments[$sid % count($neutralComments)];
            $reasonsJson = json_encode($choice['reasons'], JSON_UNESCAPED_UNICODE);
            $comment = $choice['comment'];
        } elseif ($seedVal < 96) {
            // 2 stars (8%)
            $rating = 2;
            $choice = $lowComments[$sid % count($lowComments)];
            $reasonsJson = json_encode($choice['reasons'], JSON_UNESCAPED_UNICODE);
            $comment = $choice['comment'];
        } else {
            // 1 star (4%)
            $rating = 1;
            $choice = $poorComments[$sid % count($poorComments)];
            $reasonsJson = json_encode($choice['reasons'], JSON_UNESCAPED_UNICODE);
            $comment = $choice['comment'];
        }

        // Identify owner or fallback guest token
        $userId = !empty($summary['user_id']) ? (int)$summary['user_id'] : null;
        $guestToken = null;
        if ($userId === null) {
            $guestToken = !empty($summary['guest_token']) 
                ? (string)$summary['guest_token'] 
                : 'seeded_guest_' . substr(hash('sha256', (string)$sid), 0, 32);
        }

        $createdAt = !empty($summary['created_at']) ? $summary['created_at'] : date('Y-m-d H:i:s');
        // Feedback typically created 1-5 minutes after summary
        $feedbackTimestamp = date('Y-m-d H:i:s', strtotime($createdAt) + (($sid % 240) + 60));

        if ($isDryRun) {
            echo "[Dry-Run] Summary #{$sid}: rating={$rating}, reasons=" . ($reasonsJson ?: 'none') . ", comment=\"{$comment}\"\n";
            $seededCount++;
            $eventsCount++;
            continue;
        }

        $pdo->beginTransaction();

        // 1. Canonical feedback row
        if ($userId !== null) {
            $fbStmt = $pdo->prepare('
                INSERT INTO feedback (summary_id, user_id, rating, reasons, comment, created_at, updated_at)
                VALUES (:sid, :uid, :rating, :reasons, :comment, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    rating = VALUES(rating),
                    reasons = VALUES(reasons),
                    comment = VALUES(comment),
                    updated_at = VALUES(updated_at)
            ');
            $fbStmt->execute([
                ':sid'        => $sid,
                ':uid'        => $userId,
                ':rating'     => $rating,
                ':reasons'    => $reasonsJson,
                ':comment'    => $comment,
                ':created_at' => $feedbackTimestamp,
                ':updated_at' => $feedbackTimestamp,
            ]);
        } else {
            $fbStmt = $pdo->prepare('
                INSERT INTO feedback (summary_id, guest_token, rating, reasons, comment, created_at, updated_at)
                VALUES (:sid, :token, :rating, :reasons, :comment, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    rating = VALUES(rating),
                    reasons = VALUES(reasons),
                    comment = VALUES(comment),
                    updated_at = VALUES(updated_at)
            ');
            $fbStmt->execute([
                ':sid'        => $sid,
                ':token'      => $guestToken,
                ':rating'     => $rating,
                ':reasons'    => $reasonsJson,
                ':comment'    => $comment,
                ':created_at' => $feedbackTimestamp,
                ':updated_at' => $feedbackTimestamp,
            ]);
        }

        // 2. Append-only feedback_comments event row
        $fcStmt = $pdo->prepare('
            INSERT INTO feedback_comments (summary_id, rating, reasons, comment, created_at)
            VALUES (:sid, :rating, :reasons, :comment, :created_at)
        ');
        $fcStmt->execute([
            ':sid'        => $sid,
            ':rating'     => $rating,
            ':reasons'    => $reasonsJson,
            ':comment'    => $comment,
            ':created_at' => $feedbackTimestamp,
        ]);
        $eventsCount++;

        // For ~15% of summaries, simulate a second revision event 10 minutes later
        if ($sid % 7 === 0) {
            $revisedRating = min(5, $rating + 1);
            $revisedComment = $comment . ' (Updated: summary answered my questions upon second reading.)';
            $revisedTimestamp = date('Y-m-d H:i:s', strtotime($feedbackTimestamp) + 600);
            $fcStmt->execute([
                ':sid'        => $sid,
                ':rating'     => $revisedRating,
                ':reasons'    => $revisedRating > 3 ? null : $reasonsJson,
                ':comment'    => $revisedComment,
                ':created_at' => $revisedTimestamp,
            ]);
            $eventsCount++;
        }

        $pdo->commit();
        $seededCount++;
    }

    echo "\nSeeding finished successfully!\n";
    echo "  Summaries seeded with new feedback: {$seededCount}\n";
    echo "  Existing feedback records updated:   {$updatedCount}\n";
    echo "  Total feedback_comments events:     {$eventsCount}\n";

    // Show updated total counts
    $totalFb = (int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn();
    $totalFc = (int)$pdo->query('SELECT COUNT(*) FROM feedback_comments')->fetchColumn();
    $distinctSummariesWithFb = (int)$pdo->query('SELECT COUNT(DISTINCT summary_id) FROM feedback')->fetchColumn();
    echo "\nLive database verification:\n";
    echo "  Total summaries with canonical feedback: {$distinctSummariesWithFb} / {$totalSummaries}\n";
    echo "  Total rows in `feedback` table:           {$totalFb}\n";
    echo "  Total rows in `feedback_comments` table:  {$totalFc}\n";

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seeding error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
