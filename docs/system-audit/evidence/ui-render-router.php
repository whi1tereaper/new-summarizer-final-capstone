<?php
/** Audit-only renderer. Original templates, synthetic session/PDO responses, no live DB.
 * Run with: php -S 127.0.0.1:8765 docs/system-audit/evidence/ui-render-router.php
 * Never deploy this file. It deliberately bypasses bootstrap for isolated UI inspection.
 */
declare(strict_types=1);
$projectRoot = dirname(__DIR__, 3);
$publicRoot = $projectRoot . '/app/public';
$path = ltrim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit('Audit renderer permits GET only.'); }
if (str_starts_with($path, 'assets/')) {
    $asset = realpath($publicRoot . '/' . $path);
    $assetRoot = realpath($publicRoot . '/assets');
    if (!$asset || !str_starts_with(str_replace('\\', '/', $asset), str_replace('\\', '/', $assetRoot) . '/') || !is_file($asset)) { http_response_code(404); exit; }
    $types = ['css'=>'text/css','js'=>'application/javascript','png'=>'image/png','webp'=>'image/webp','woff2'=>'font/woff2'];
    header('Content-Type: ' . ($types[pathinfo($asset, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($asset); exit;
}
$allowed = ['index.php','about.php','summarizer.php','login.php','register.php','forgot_password.php','reset_password.php','terms.php','accept_terms.php','dashboard.php','profile.php','history.php','analytics.php','processing.php','result.php','admin_dashboard.php','admin_audit_logs.php','admin_verify.php','admin_challenge_setup.php','admin_challenge_change.php','admin_db_health.php','admin_feedback_feed.php','evaluation.php','evaluate.php','evaluation_export.php','tts_generate.php','tts_audio.php','api/qa.php'];
if (!in_array($path, $allowed, true)) { http_response_code(404); exit('Not available in audit renderer.'); }
header('X-Audit-Evidence: isolated-template-render; synthetic-data; not-live-authentication');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'none'; form-action 'none'; object-src 'none'");
date_default_timezone_set('Asia/Manila');
define('APP_BOOTSTRAPPED', true);
$fixtureRole = in_array($_GET['fixture_role'] ?? '', ['user','admin'], true) ? $_GET['fixture_role'] : 'anonymous';
$_SESSION = ['guest_token'=>str_repeat('a',64),'csrf_token'=>'isolated-audit-csrf-not-a-real-session'];
if ($fixtureRole !== 'anonymous') {
    $_SESSION = ['user_id'=>900001,'username'=>'SYNTHETIC AUDIT USER','role'=>$fixtureRole,'email'=>'audit-fixture@example.invalid','terms_accepted'=>1,'terms_accepted_at'=>'2026-10-17 00:00:00','csrf_token'=>'isolated-audit-csrf-not-a-real-session'];
}
if (($_GET['fixture_terms_pending'] ?? '') === '1') { $_SESSION['terms_accepted'] = 0; }
if (($_GET['fixture_admin_code'] ?? '') === '1') { $_SESSION['pending_admin_login'] = ['user_id'=>900001,'expires_at'=>time()+600,'code_hash'=>'SYNTHETIC-NOT-A-REAL-CODE','email_hint'=>'audit-fixture@example.invalid']; }
if ($path === 'processing.php' && ($_GET['fixture_pending'] ?? '') === '1') {
    $_SESSION['pending_summary'] = ['source_type'=>'text','document_title'=>'SYNTHETIC UI EXAMPLE — no job submitted','original_text'=>'Synthetic source text used only to render an interface.','output_format'=>'paragraph','analysis_mode'=>'general','summary_depth'=>'balanced'];
}
function config(string $key, mixed $default = null): mixed {
    if (str_starts_with($key, 'database.')) { throw new RuntimeException('Live database access is disabled in audit renderer.'); }
    if (str_starts_with($key, 'storage.')) { return __DIR__ . '/isolated-ui-storage/' . substr($key, 8); }
    if ($key === 'summarizer.llm_available') return false;
    return $default;
}
class AuditUiStatement extends PDOStatement {
    private array $rows = [];
    private int $position = 0;
    public function __construct(private string $sql) { $this->execute(); }
    public function execute(?array $params = null): bool {
        if (!preg_match('/^\s*(SELECT|SHOW)\b/i', $this->sql)) throw new RuntimeException('Audit renderer blocked non-read SQL.');
        $this->rows = []; $this->position = 0;
        if (str_contains($this->sql, "SHOW TABLES LIKE 'admin_audit_logs'") && ($_GET['fixture_logs'] ?? '') === '1') {
            $this->rows = [['Tables_in_synthetic'=>'admin_audit_logs']];
        } elseif (preg_match('/FROM\s+users\s+WHERE\s+id/i', $this->sql)) {
            $this->rows = [['id'=>900001,'username'=>'SYNTHETIC AUDIT USER','email'=>'audit-fixture@example.invalid','created_at'=>'2026-10-17 00:00:00','active'=>1,'role'=>$GLOBALS['fixtureRole'],'terms_accepted'=>1]];
        } elseif (str_contains($this->sql, 'WHERE s.id = :id') && (int)($params['id'] ?? 0) === 900001) {
            $this->rows = [['id'=>900001,'user_id'=>$_SESSION['user_id'] ?? null,'guest_token'=>$_SESSION['guest_token'] ?? null,'share_token'=>null,'article_title'=>'SYNTHETIC UI EXAMPLE — no model output','input_type'=>'text','summary_style'=>'standard_paragraph','summary_length'=>'balanced','status'=>'completed','original_text'=>'This synthetic source exists only to demonstrate the page layout. No document was submitted and no summary was generated.','generated_summary'=>'This synthetic summary demonstrates the result interface. It is not a model output or an evaluation result.','created_at'=>'2026-10-17 00:00:00','original_word_count'=>24,'summary_word_count'=>22,'processing_time'=>0]];
        } elseif (preg_match('/(COUNT\(|AVG\(|SUM\()/i', $this->sql) && !preg_match('/GROUP BY/i', $this->sql)) {
            $row = ['avg'=>null,'count'=>0,'total'=>0];
            preg_match_all('/\bAS\s+([a-z_]+)/i', $this->sql, $matches);
            foreach ($matches[1] as $name) $row[$name] = 0;
            $this->rows = [$row];
        }
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $cursorOrientation=PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed { return $this->rows[$this->position++] ?? false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column=0): mixed { $row=$this->rows[0] ?? null; return $row ? (array_values($row)[$column] ?? 0) : 0; }
    public function bindValue(string|int $param, mixed $value, int $type=PDO::PARAM_STR): bool { return true; }
    public function rowCount(): int { return count($this->rows); }
}
class AuditUiPdo extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new AuditUiStatement($query); }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false { return new AuditUiStatement($query); }
    public function exec(string $statement): int|false { throw new RuntimeException('Audit renderer blocked database mutation.'); }
    public function beginTransaction(): bool { throw new RuntimeException('Audit renderer blocked transactions.'); }
    public function getAttribute(int $attribute): mixed { return 'SYNTHETIC AUDIT PDO'; }
}
require_once $projectRoot . '/app/src/Database.php';
$reflection = new ReflectionClass(App\Src\Database::class);
$database = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('pdo')->setValue($database, new AuditUiPdo());
$reflection->getProperty('instance')->setValue(null, $database);
$_SERVER['SCRIPT_NAME'] = '/' . $path;
chdir($publicRoot);
require $publicRoot . '/' . $path;
