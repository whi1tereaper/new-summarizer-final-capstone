<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be executed from CLI.');
}

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';

use App\Src\Database;

$retentionDays = (int)getenv('LANDING_ANALYTICS_RETENTION_DAYS');
if ($retentionDays <= 0) {
    $retentionDays = 90;
}
$isDryRun = in_array('--dry-run', $argv, true);

try {
    $pdo = Database::getInstance()->getConnection();
    $cutoff = (new DateTimeImmutable())->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');
    $tableExists = $pdo->query("SHOW TABLES LIKE 'landing_page_sessions'")->rowCount() > 0;
    if (!$tableExists) {
        echo "Landing analytics tables do not exist. No cleanup needed.\n";
        exit(0);
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM landing_page_sessions WHERE created_at < :cutoff');
    $count->execute([':cutoff' => $cutoff]);
    $rows = (int)$count->fetchColumn();
    if ($isDryRun) {
        echo "Dry run only.\nRetention days: {$retentionDays}\nRows that would be deleted: {$rows}\n";
        exit(0);
    }

    $delete = $pdo->prepare('DELETE FROM landing_page_sessions WHERE created_at < :cutoff');
    $delete->execute([':cutoff' => $cutoff]);
    error_log("Landing analytics cleanup completed. Deleted sessions: {$delete->rowCount()}. Cutoff: {$cutoff}");
    echo "Cleanup completed.\nDeleted sessions: {$delete->rowCount()}\n";
} catch (\Throwable $exception) {
    error_log('Landing analytics cleanup failed: ' . $exception->getMessage());
    fwrite(STDERR, "Cleanup failed.\n");
    exit(1);
}
