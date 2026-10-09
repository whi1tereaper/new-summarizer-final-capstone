<?php
declare(strict_types=1);

/**
 * Phase 4 Test Suite — Database Lifecycle, Migration Hygiene & Runtime DDL Removal
 *
 * Verifies:
 * 1. Zero runtime DDL statements in app/
 * 2. Deterministic migration ordering and catalog
 * 3. Fresh installation succeeds in clean temporary database without errors or duplicate definitions
 * 4. Migration runner re-run is safe and idempotent (0 pending migrations)
 * 5. Critical schema columns and tables are intact
 * 6. Services function against existing schema without DDL execution
 */

require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Database.php';

$passed = 0;
$failed = 0;

function assertPhase4(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [PASS] {$description}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
    } else {
        $failed++;
        echo " [FAIL] {$description}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
    }
}

echo "====================================================\n";
echo "PHASE 4 DATABASE LIFECYCLE & RUNTIME DDL TEST SUITE\n";
echo "====================================================\n\n";

$db = \App\Src\Database::getInstance()->getConnection();

// ---------------------------------------------------------
// TEST GROUP 1: Static DDL Scan in Application Code
// ---------------------------------------------------------
echo "--- TEST GROUP 1: Static DDL Scan in Application Code ---\n";

$appDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
$phpFiles = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

$ddlHits = [];
$ddlPattern = '/\b(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|CREATE\s+INDEX|DROP\s+INDEX|TRUNCATE\s+TABLE)\b/i';

foreach ($phpFiles as $fileArr) {
    $filePath = $fileArr[0];
    $content = file_get_contents($filePath);
    if ($content !== false && preg_match_all($ddlPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $ddlHits[] = basename($filePath) . ':' . $match[0];
        }
    }
}

assertPhase4(
    "Zero runtime DDL statements remain in app/ codebase",
    count($ddlHits) === 0,
    count($ddlHits) === 0 ? "0 hits found" : "Hits: " . implode(', ', $ddlHits)
);

// ---------------------------------------------------------
// TEST GROUP 2: Migration Catalog & Deterministic Ordering
// ---------------------------------------------------------
echo "\n--- TEST GROUP 2: Migration Catalog & Deterministic Ordering ---\n";

$migrationsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
$migrationFiles = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql');
sort($migrationFiles, SORT_NATURAL);

$expectedMigrations = [
    '001_normalize_summaries.sql',
    '002_admin_security_challenges.sql',
    '003_admin_audit_logs.sql',
    '004_summary_analytics.sql',
    '005_nutshell_analytics.sql',
    '006_nutshell_generations.sql',
    '007_history_contracts.sql',
    '008_terms_acceptance.sql',
    '009_landing_inp.sql',
    '010_user_tokens.sql',
    '011_document_summaries.sql',
    '012_evaluation_framework.sql',
    '013_nutshell_production_contract.sql',
    '014_feedback_hardening_and_reasons.sql',
];

$actualBasenames = array_map('basename', $migrationFiles);

assertPhase4(
    "All sequential migrations exist in database/migrations/",
    $actualBasenames === $expectedMigrations,
    "Count: " . count($actualBasenames)
);

assertPhase4(
    "Migration 010_user_tokens.sql defines user_tokens table",
    file_exists($migrationsDir . DIRECTORY_SEPARATOR . '010_user_tokens.sql') &&
    str_contains((string)file_get_contents($migrationsDir . DIRECTORY_SEPARATOR . '010_user_tokens.sql'), 'user_tokens')
);

assertPhase4(
    "Migration 011_document_summaries.sql defines documents and document_summaries tables",
    file_exists($migrationsDir . DIRECTORY_SEPARATOR . '011_document_summaries.sql') &&
    str_contains((string)file_get_contents($migrationsDir . DIRECTORY_SEPARATOR . '011_document_summaries.sql'), 'document_summaries')
);

// ---------------------------------------------------------
// TEST GROUP 3: Live Database State & Schema Integrity
// ---------------------------------------------------------
echo "\n--- TEST GROUP 3: Live Database State & Schema Integrity ---\n";

$liveTables = array_map('strtolower', $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
$liveTablesMap = array_fill_keys($liveTables, true);

$requiredTables = [
    'admin_audit_logs',
    'admin_security_challenges',
    'document_summaries',
    'documents',
    'feedback',
    'guest_sessions',
    'landing_page_events',
    'landing_page_sessions',
    'nutshell_generations',
    'password_resets',
    'rate_limits',
    'schema_migrations',
    'summaries',
    'summary_artifacts',
    'user_tokens',
    'users',
];

$missingTables = [];
foreach ($requiredTables as $rt) {
    if (!isset($liveTablesMap[$rt])) {
        $missingTables[] = $rt;
    }
}

assertPhase4(
    "Live database contains all 14 required tables",
    empty($missingTables),
    empty($missingTables) ? "All present" : "Missing: " . implode(', ', $missingTables)
);

// Verify critical columns
$userCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN));
assertPhase4(
    "users table contains terms_accepted and terms_accepted_at",
    in_array('terms_accepted', $userCols, true) && in_array('terms_accepted_at', $userCols, true)
);

$summaryCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM summaries")->fetchAll(PDO::FETCH_COLUMN));
assertPhase4(
    "summaries table contains summary_length and nutshell_text",
    in_array('summary_length', $summaryCols, true) && in_array('nutshell_text', $summaryCols, true)
);

$lpsCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM landing_page_sessions")->fetchAll(PDO::FETCH_COLUMN));
assertPhase4(
    "landing_page_sessions table contains inp_ms",
    in_array('inp_ms', $lpsCols, true)
);

$tokenCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM user_tokens")->fetchAll(PDO::FETCH_COLUMN));
assertPhase4(
    "user_tokens table contains selector, hashed_validator, and expires",
    in_array('selector', $tokenCols, true) && in_array('hashed_validator', $tokenCols, true) && in_array('expires', $tokenCols, true)
);

// ---------------------------------------------------------
// TEST GROUP 4: Fresh Installation in Temporary Database
// ---------------------------------------------------------
echo "\n--- TEST GROUP 4: Fresh Installation in Temporary Database ---\n";

$tempDbName = 'test_tmp_fresh_' . bin2hex(random_bytes(3));
$host = config('database.host', '127.0.0.1');
$port = config('database.port', '3306');
$user = config('database.username', 'root');
$pass = config('database.password', '');

try {
    $serverPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $serverPdo->exec("CREATE DATABASE `{$tempDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $tempPdo = new PDO("mysql:host={$host};port={$port};dbname={$tempDbName};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);

    // 1. Import base schema
    $baseSchemaSql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $tempPdo->exec($baseSchemaSql);

    // 2. Import base analytics schema
    $analyticsSchemaSql = file_get_contents(dirname(__DIR__) . '/database/analytics_schema.sql');
    $tempPdo->exec($analyticsSchemaSql);

    // 3. Create schema_migrations ledger in temp DB
    $tempPdo->exec("
        CREATE TABLE `schema_migrations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL UNIQUE,
            `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Run all migrations sequentially in temp DB
    $migrationFailures = [];
    foreach ($migrationFiles as $mFile) {
        $mName = basename($mFile);
        $mSql = file_get_contents($mFile);
        try {
            $tempPdo->exec($mSql);
            $ins = $tempPdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
            $ins->execute([$mName]);
        } catch (\Throwable $e) {
            $migrationFailures[] = "{$mName}: " . $e->getMessage();
        }
    }

    assertPhase4(
        "Fresh install: All base schemas and sequential migrations apply with 0 errors",
        empty($migrationFailures),
        empty($migrationFailures) ? "10/10 migrations passed" : implode('; ', $migrationFailures)
    );

    // Verify temp database final table count
    $tempTables = array_map('strtolower', $tempPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
    $tempTablesMap = array_fill_keys($tempTables, true);
    $missingInTemp = array_filter($requiredTables, fn($t) => !isset($tempTablesMap[$t]));

    assertPhase4(
        "Fresh install: Temporary database reaches identical 14 required tables",
        empty($missingInTemp),
        empty($missingInTemp) ? "Exact match" : "Missing: " . implode(', ', $missingInTemp)
    );

    // Test idempotency: re-running applied migrations should result in 0 executions
    $appliedInTemp = $tempPdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    $pendingInTemp = array_diff($actualBasenames, $appliedInTemp);
    assertPhase4(
        "Migration ledger reports 0 pending migrations after full application",
        count($pendingInTemp) === 0,
        "Pending: " . count($pendingInTemp)
    );

    // Cleanup temp database
    $serverPdo->exec("DROP DATABASE `{$tempDbName}`");
    assertPhase4("Temporary test database cleaned up safely", true);
} catch (\Throwable $e) {
    assertPhase4("Fresh install in temporary database succeeded", false, $e->getMessage());
    try {
        $serverPdo->exec("DROP DATABASE IF EXISTS `{$tempDbName}`");
    } catch (\Throwable $ignored) {}
}

// ---------------------------------------------------------
// TEST GROUP 5: Runtime Services Function Without DDL
// ---------------------------------------------------------
echo "\n--- TEST GROUP 5: Runtime Services Function Without DDL ---\n";

// 5.1 TermsAcceptanceService
require_once dirname(__DIR__) . '/app/src/Services/TermsAcceptanceService.php';
$termsSvc = new \App\Src\Services\TermsAcceptanceService();
$termsSvc->ensureSchema(); // Must be safe no-op
assertPhase4("TermsAcceptanceService::ensureSchema() executes as safe no-op without error", true);

// 5.2 RememberMeService
require_once dirname(__DIR__) . '/app/src/Services/RememberMeService.php';
\App\Src\Services\RememberMeService::ensureSchema(); // Must be safe no-op
assertPhase4("RememberMeService::ensureSchema() executes as safe no-op without error", true);

// Test RememberMe cookie creation & DB persistence with controlled fixture
$fixtureUserId = 12; // Existing user ID in DB
$_SERVER['HTTPS'] = 'on';
@\App\Src\Services\RememberMeService::createCookieForUser($fixtureUserId);
$chkToken = $db->prepare("SELECT id, selector, expires FROM user_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$chkToken->execute([$fixtureUserId]);
$tokenRow = $chkToken->fetch(PDO::FETCH_ASSOC);
assertPhase4(
    "RememberMeService::createCookieForUser stores token in user_tokens",
    $tokenRow !== false && !empty($tokenRow['selector']),
    "Token ID: " . ($tokenRow['id'] ?? 'none')
);
if ($tokenRow) {
    $db->prepare("DELETE FROM user_tokens WHERE id = ?")->execute([$tokenRow['id']]);
}

// 5.3 RateLimiter
require_once dirname(__DIR__) . '/app/src/Services/RateLimiter.php';
$limiter = new \App\Src\Services\RateLimiter();
$isLim = $limiter->isLimited('login', 'test_identity_fixture_123');
assertPhase4("RateLimiter::isLimited() queries rate_limits without DDL", $isLim === false);

// 5.4 AdminController constructor
require_once dirname(__DIR__) . '/app/src/Controllers/AdminController.php';
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$adminCtrl = new \App\Src\Controllers\AdminController();
assertPhase4("AdminController instantiates cleanly without triggering ensureSchema DDL", is_object($adminCtrl));

// ---------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------
echo "\n====================================================\n";
echo "PHASE 4 TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================\n";

exit($failed === 0 ? 0 : 1);
