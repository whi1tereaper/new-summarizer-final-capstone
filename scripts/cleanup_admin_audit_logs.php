<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be executed from CLI.');
}

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';

use App\Src\Database;

// Default retention days, can be overridden by env
$retentionDays = (int)getenv('ADMIN_AUDIT_LOG_RETENTION_DAYS');
if ($retentionDays <= 0) {
    $retentionDays = 90;
}

$isDryRun = in_array('--dry-run', $argv, true);

try {
    $pdo = Database::getInstance()->getConnection();

    // Check if table exists securely
    $tableExists = $pdo->query("SHOW TABLES LIKE 'admin_audit_logs'")->rowCount() > 0;
    if (!$tableExists) {
        echo "Table 'admin_audit_logs' does not exist yet. No cleanup needed.\n";
        exit(0);
    }

    $cutoffDate = (new DateTimeImmutable())
        ->modify("-{$retentionDays} days")
        ->format('Y-m-d H:i:s');

    if ($isDryRun) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM admin_audit_logs 
            WHERE created_at < :cutoff
        ");

        $stmt->execute([
            ':cutoff' => $cutoffDate
        ]);

        $count = (int) $stmt->fetchColumn();

        echo "Dry run only.\n";
        echo "Retention days: {$retentionDays}\n";
        echo "Cutoff date: {$cutoffDate}\n";
        echo "Rows that would be deleted: {$count}\n";
        exit(0);
    }

    $stmt = $pdo->prepare("
        DELETE FROM admin_audit_logs 
        WHERE created_at < :cutoff
    ");

    $stmt->execute([
        ':cutoff' => $cutoffDate
    ]);

    $deletedRows = $stmt->rowCount();

    error_log("Admin audit log cleanup completed. Deleted rows: {$deletedRows}. Cutoff: {$cutoffDate}");

    echo "Cleanup completed.\n";
    echo "Deleted rows: {$deletedRows}\n";
    exit(0);
} catch (\Throwable $e) {
    error_log("Admin audit log cleanup failed: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
