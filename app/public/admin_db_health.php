<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Services/AdminSecurityService.php';

use App\Src\Database;
use App\Src\Services\AdminSecurityService;

// Strict authorization gate: requires an authenticated user with the administrator role.
// Environment setting (development/production) NEVER overrides this gate.
if (!AdminSecurityService::isVerifiedAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$isDev = (config('app.env', 'development')) === 'development';
$environment = $isDev ? 'development' : 'production';

$status = [
    'status' => 'OK',
    'environment' => $environment,
    'connection' => 'Pending',
    'tables' => [],
    'checked_at' => date('Y-m-d H:i:s'),
];

// Detail disclosure control: In development mode, provide database name and engine version
// to authorized administrators. In production, suppress engine version and database name.
if ($isDev) {
    $status['database'] = 'Unknown';
    $status['mysql_version'] = 'Unknown';
}

$requiredTables = [
    'users' => ['id', 'username', 'email', 'password_hash', 'role', 'active', 'terms_accepted', 'terms_accepted_at', 'created_at'],
    'summaries' => ['id', 'user_id', 'guest_token', 'share_token', 'article_title', 'input_type', 'summary_style', 'created_at'],
    'summary_artifacts' => ['id', 'summary_id', 'original_text', 'generated_summary'],
    'feedback' => ['id', 'summary_id', 'user_id', 'guest_token', 'rating', 'name', 'email', 'impression', 'comment', 'created_at', 'updated_at'],
];

try {
    $db = Database::getInstance()->getConnection();
    $status['connection'] = 'OK';

    if ($isDev) {
        $status['database'] = $db->query('SELECT DATABASE()')->fetchColumn() ?: 'None selected';
        $status['mysql_version'] = $db->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    foreach ($requiredTables as $table => $columns) {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        $tableExists = $stmt->rowCount() > 0;
        
        if (!$tableExists) {
            $status['tables'][$table] = ['exists' => false];
            $status['status'] = 'WARNING';
            continue;
        }

        $sanitizedTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $colStmt = $db->query("SHOW COLUMNS FROM `{$sanitizedTable}`");
        $existingCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $colStatus = [];
        foreach ($columns as $col) {
            $colStatus[$col] = in_array($col, $existingCols, true);
            if (!$colStatus[$col]) {
                $status['status'] = 'WARNING';
            }
        }

        $status['tables'][$table] = [
            'exists' => true,
            'columns' => $colStatus
        ];
    }
} catch (\Throwable $e) {
    error_log('[DB Health Check] ' . $e->getMessage());
    $status['status'] = 'FAILED';
    $status['connection'] = 'FAILED';
}

header('Content-Type: application/json');
echo json_encode($status, JSON_PRETTY_PRINT);
