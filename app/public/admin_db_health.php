<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Database.php';

$isDev = (config('app.env', 'development')) === 'development';
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

if (!$isDev && !$isAdmin) {
    http_response_code(403);
    echo "Forbidden.";
    exit;
}

use App\Src\Database;

$environment = $isDev ? 'development' : 'production';
$status = [
    'status' => 'OK',
    'environment' => $environment,
    'connection' => 'Pending',
    'database' => 'Unknown',
    'mysql_version' => 'Unknown',
    'tables' => [],
    'checked_at' => date('Y-m-d H:i:s'),
];

$requiredTables = [
    'users' => ['id', 'username', 'email', 'password_hash', 'role', 'active', 'terms_accepted', 'terms_accepted_at', 'created_at'],
    'summaries' => ['id', 'user_id', 'guest_token', 'share_token', 'article_title', 'input_type', 'summary_style', 'created_at'],
    'summary_artifacts' => ['id', 'summary_id', 'original_text', 'generated_summary'],
    'feedback' => ['id', 'summary_id', 'user_id', 'guest_token', 'rating', 'name', 'email', 'impression', 'comment', 'created_at', 'updated_at'],
];

try {
    $db = Database::getInstance()->getConnection();
    $status['connection'] = 'OK';
    $status['database'] = $db->query('SELECT DATABASE()')->fetchColumn() ?: 'None selected';
    $status['mysql_version'] = $db->getAttribute(PDO::ATTR_SERVER_VERSION);

    foreach ($requiredTables as $table => $columns) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        $tableExists = $stmt->rowCount() > 0;
        
        if (!$tableExists) {
            $status['tables'][$table] = ['exists' => false];
            $status['status'] = 'WARNING';
            continue;
        }

        $colStmt = $db->query("SHOW COLUMNS FROM `$table`");
        $existingCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $colStatus = [];
        foreach ($columns as $col) {
            $colStatus[$col] = in_array($col, $existingCols);
            if (!$colStatus[$col]) {
                $status['status'] = 'WARNING';
            }
        }

        $status['tables'][$table] = [
            'exists' => true,
            'columns' => $colStatus
        ];
    }
} catch (\Exception $e) {
    error_log('[DB Health Check] ' . $e->getMessage());
    $status['status'] = 'FAILED';
    $status['connection'] = 'FAILED';
}

header('Content-Type: application/json');
echo json_encode($status, JSON_PRETTY_PRINT);
