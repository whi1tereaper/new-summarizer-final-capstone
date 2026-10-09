<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be executed from CLI.');
}

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Services/FeedbackService.php';

use App\Src\Database;
use App\Src\Services\FeedbackService;

// Default retention days, can be overridden by env
$retentionDays = (int)getenv('FEEDBACK_EVENT_RETENTION_DAYS');
if ($retentionDays <= 0) {
    $retentionDays = 90;
}

$isDryRun = in_array('--dry-run', $argv, true);

try {
    $pdo = Database::getInstance()->getConnection();

    // Verify table existence
    $tableExists = $pdo->query("SHOW TABLES LIKE 'feedback_comments'")->rowCount() > 0;
    if (!$tableExists) {
        echo "Table 'feedback_comments' does not exist yet. No cleanup needed.\n";
        exit(0);
    }

    $cutoffDate = (new DateTimeImmutable())
        ->modify("-{$retentionDays} days")
        ->format('Y-m-d H:i:s');

    if ($isDryRun) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM feedback_comments 
            WHERE created_at < :cutoff
        ");
        $stmt->execute([':cutoff' => $cutoffDate]);
        $count = (int)$stmt->fetchColumn();

        echo "Dry run only.\n";
        echo "Retention days: {$retentionDays}\n";
        echo "Cutoff date: {$cutoffDate}\n";
        echo "Feedback comment event rows that would be deleted: {$count}\n";
        exit(0);
    }

    $feedbackService = new FeedbackService();
    $deleted = $feedbackService->pruneFeedbackEvents($retentionDays);

    echo "Feedback comment event ledger cleanup completed.\n";
    echo "Retention days: {$retentionDays}\n";
    echo "Cutoff date: {$cutoffDate}\n";
    echo "Deleted rows: {$deleted}\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Feedback comment event cleanup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
