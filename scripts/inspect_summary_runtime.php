<?php
declare(strict_types=1);
// Read-only local diagnostics. Never expose this script over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (in_array('--local-mysql', $argv, true)) {
    // Match an Apache process that cannot load pdo_pgsql, without changing .env.
    putenv('DATABASE_URL=mysql://local-diagnostic');
}
require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Database.php';

$runtime = ['php_version' => PHP_VERSION, 'sapi' => PHP_SAPI,
    'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql'),
    'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
    'configured_postgres' => str_starts_with((string)config('database.url', ''), 'postgres'),
    'article_routing' => config('summarizer.automatic_article_routing', false),
    'engine' => config('summarizer.engine_version', 'legacy'),
    'python_binary_exists' => is_file((string)config('python.bin')),
    'worker_script_exists' => is_file((string)config('python.script'))];
try {
    $database = \App\Src\Database::getInstance();
    $db = $database->getConnection();
    $runtime['database_driver'] = $database->getDriver();
    $resultOption = array_search('--verify-result', $argv, true);
    if ($resultOption !== false) {
        $resultId = filter_var($argv[$resultOption + 1] ?? null, FILTER_VALIDATE_INT);
        if (!$resultId || $resultId < 1) throw new RuntimeException('Invalid test result.');
        $check = $db->prepare('SELECT a.generated_summary FROM summary_artifacts a JOIN summaries s ON s.id=a.summary_id WHERE s.id=? AND s.article_title=?');
        $check->execute([$resultId, 'Isolated runtime repair verification']);
        $artifact = $check->fetchColumn();
        if (!is_string($artifact)) throw new RuntimeException('Not an owned runtime fixture result.');
        $metadata = json_decode($artifact, true)['source_metadata'] ?? [];
        $runtime['verified_result'] = array_intersect_key($metadata, array_flip(['engine','engine_version','source_type','engine_routing']));
        echo json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit;
    }
    $rows = $db->query('SELECT job_id, state, result_id, error_details, created_at, updated_at, worker_pid FROM summary_jobs ORDER BY created_at DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
    $runtime['recent_jobs'] = $rows;
} catch (Throwable $error) {
    $runtime['inspection_exception'] = get_class($error);
    $runtime['sqlstate'] = $error instanceof PDOException ? $error->getCode() : null;
}
echo json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
