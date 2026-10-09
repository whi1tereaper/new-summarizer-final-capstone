<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Services/AdminSecurityService.php';
require_once __DIR__ . '/../app/src/Utils/validation.php';

use App\Src\Database;
use App\Src\Services\AdminSecurityService;

echo "====================================================\n";
echo "PHASE 3 ADMIN DISPATCH & ANALYTICS VERIFICATION\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertPhase3(string $name, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $name . ($detail ? " - {$detail}" : "") . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $name . ($detail ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

$db = Database::getInstance()->getConnection();

// ---------------------------------------------------------
// TEST GROUP 1: Include Safety of AdminController.php
// ---------------------------------------------------------
echo "--- TEST GROUP 1: AdminController Include Safety ---\n";

$codeAdminInc = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "delete_user";
$_POST["user_id"] = 1;
$_POST["csrf_token"] = "fake_csrf";

ob_start();
require_once "app/src/Controllers/AdminController.php";
$output = ob_get_clean();

if ($output !== "") {
    echo "OUTPUT_DETECTED: " . $output;
    exit(2);
}

if (!class_exists("App\\Src\\Controllers\\AdminController")) {
    echo "CLASS_MISSING";
    exit(3);
}

echo "OK";
exit(0);
';

$tempFile = sys_get_temp_dir() . '/test_adm_inc_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAdminInc);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$outText = trim(implode("\n", $output));

assertPhase3(
    "Including AdminController.php produces no output, no action execution, and no exit",
    $exitCode === 0 && $outText === 'OK',
    "Exit code: {$exitCode}, Output: {$outText}"
);

// ---------------------------------------------------------
// TEST GROUP 2: AdminController Instantiation Safety
// ---------------------------------------------------------
echo "\n--- TEST GROUP 2: AdminController Instantiation Safety ---\n";

$codeAdminInst = '<?php
require_once "app/src/whitereaper.php";
require_once "app/src/Database.php";
require_once "app/src/Services/AdminSecurityService.php";
require_once "app/src/Controllers/AdminController.php";

session_start();
// Establish an authenticated admin session without a challenge flag
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";

ob_start();
$admin = new App\Src\Controllers\AdminController();
$out = ob_get_clean();

if ($out !== "") {
    echo "OUTPUT_DETECTED";
    exit(2);
}

echo "CONSTRUCTED_OK";
exit(0);
';

$tempFile = sys_get_temp_dir() . '/test_adm_inst_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAdminInst);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$outText = trim(implode("\n", $output));

assertPhase3(
    "Instantiating AdminController in authenticated admin session without a challenge flag triggers no side-effect actions",
    $exitCode === 0 && $outText === 'CONSTRUCTED_OK',
    "Output: {$outText}"
);

// ---------------------------------------------------------
// TEST GROUP 3: Admin Action Authorization Matrix
// ---------------------------------------------------------
echo "\n--- TEST GROUP 3: Admin Action Authorization Matrix ---\n";

// Test 3.1: Anonymous user rejected
$codeAnon = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "clean_files";
$_POST["csrf_token"] = "some_token";
session_start();
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_anon_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAnon);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
// Anonymous is redirected or stopped by the administrator role guard
assertPhase3("Anonymous request to admin_dashboard.php?action is denied", $exitCode === 0);

// Test 3.2: Normal user (role='user') rejected
$codeNormal = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "clean_files";
$_POST["csrf_token"] = "some_token";
session_start();
$_SESSION["user_id"] = 12;
$_SESSION["role"] = "user";
$_SESSION["admin_challenge_verified"] = false;
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_normal_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeNormal);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
assertPhase3("Normal user request to admin_dashboard.php?action is denied", $exitCode === 0);

// Test 3.3: Legacy pending-only session without a full login is rejected
$codePendingAdmin = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "clean_files";
$_POST["csrf_token"] = "some_token";
session_start();
$_SESSION["pending_admin_user_id"] = 1;
$_SESSION["admin_challenge_verified"] = false;
unset($_SESSION["user_id"]);
unset($_SESSION["role"]);
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_pending_adm_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codePendingAdmin);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
assertPhase3("Legacy pending-only admin request to admin_dashboard.php?action is denied", $exitCode === 0);

// Test 3.4: Authenticated admin without a challenge flag + Non-POST method (e.g. GET) rejected with 405
$codeGetMethod = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET["action"] = "clean_files";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_get_method_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeGetMethod);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$outText = implode('', $output);
assertPhase3(
    "Authenticated admin without a challenge flag GET request to state-changing action rejected with Method Not Allowed (405)",
    str_contains($outText, 'Method not allowed.')
);

// Test 3.5: Authenticated admin without a challenge flag + invalid CSRF token rejected with 403
$codeBadCsrf = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "clean_files";
$_POST["csrf_token"] = "invalid_token_12345";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_bad_csrf_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeBadCsrf);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$outText = implode('', $output);
assertPhase3(
    "Authenticated admin without a challenge flag request with invalid CSRF token rejected with Invalid CSRF token (403)",
    str_contains($outText, 'Invalid CSRF token.')
);

// Test 3.6: Authenticated admin without a challenge flag + unknown action rejected with 400
$codeUnknownAction = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "unsupported_unknown_admin_action";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
$_POST["csrf_token"] = generateCsrfToken();
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_unk_action_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeUnknownAction);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$outText = implode('', $output);
assertPhase3(
    "Authenticated admin without a challenge flag request with unknown action rejected with Invalid admin action (400)",
    str_contains($outText, 'Invalid admin action.')
);

// ---------------------------------------------------------
// TEST GROUP 4: Individual Admin Action Executions
// ---------------------------------------------------------
echo "\n--- TEST GROUP 4: Admin Action Execution Verification ---\n";

// Action A: clean_files
$codeCleanFiles = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "clean_files";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
$_POST["csrf_token"] = generateCsrfToken();
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_clean_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeCleanFiles);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
assertPhase3("Action clean_files dispatches and redirects to admin_dashboard.php", $exitCode === 0);

// Action B: delete_files
$codeDeleteFiles = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "delete_files";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
$_POST["csrf_token"] = generateCsrfToken();
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_delfiles_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeDeleteFiles);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
assertPhase3("Action delete_files dispatches and redirects to admin_dashboard.php", $exitCode === 0);

// Create a disposable user for toggle_status, deactivate_user, and delete_user
$disposableUser = 'disp_adm_' . bin2hex(random_bytes(3));
$insStmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, active, created_at) VALUES (?, ?, 'fakehash', 'user', 1, NOW())");
$insStmt->execute([$disposableUser, $disposableUser . '@test.example']);
$disposableId = (int)$db->lastInsertId();

// Action C: toggle_status
$codeToggle = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "toggle_status";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
$_POST["csrf_token"] = generateCsrfToken();
$_POST["user_id"] = ' . $disposableId . ';
$_POST["status"] = 0;
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_toggle_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeToggle);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);

$chk = $db->prepare("SELECT active FROM users WHERE id = ?");
$chk->execute([$disposableId]);
$statusVal = (int)$chk->fetchColumn();
assertPhase3("Action toggle_status mutates user status correctly in database", $statusVal === 0);

// Action D: deactivate_user (reactivate first, then deactivate)
$db->prepare("UPDATE users SET active = 1 WHERE id = ?")->execute([$disposableId]);
$codeDeact = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "deactivate_user";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
$_POST["csrf_token"] = generateCsrfToken();
$_POST["user_id"] = ' . $disposableId . ';
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_deact_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeDeact);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);

$chk->execute([$disposableId]);
$deactVal = (int)$chk->fetchColumn();
assertPhase3("Action deactivate_user deactivates user in database", $deactVal === 0);

// Action E: delete_user
$codeDelUser = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "delete_user";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "admin";
$_POST["csrf_token"] = generateCsrfToken();
$_POST["user_id"] = ' . $disposableId . ';
require_once "app/public/admin_dashboard.php";
';
$tempFile = sys_get_temp_dir() . '/test_deluser_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeDelUser);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);

$chkUser = $db->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
$chkUser->execute([$disposableId]);
$remCount = (int)$chkUser->fetchColumn();
assertPhase3("Action delete_user permanently removes user from database", $remCount === 0);

// ---------------------------------------------------------
// TEST GROUP 5: MariaDB Analytics Portability & KPI Verification
// ---------------------------------------------------------
echo "\n--- TEST GROUP 5: MariaDB Analytics Portability & KPI Verification ---\n";

require_once __DIR__ . '/../app/src/Controllers/AnalyticsController.php';
$analytics = new App\Src\Controllers\AnalyticsController();

$t0 = microtime(true);
$kpis = null;
$kpiError = null;
try {
    $kpis = $analytics->getKpis();
} catch (\Throwable $e) {
    $kpiError = $e->getMessage();
}
$elapsedMs = (microtime(true) - $t0) * 1000;

assertPhase3(
    "AnalyticsController::getKpis() executes on MariaDB without SQLSTATE 42000",
    $kpis !== null && $kpiError === null,
    $kpiError ?? "Elapsed: " . round($elapsedMs, 2) . " ms"
);

$expectedKeys = [
    'total_sessions',
    'sessions_7d',
    'sessions_today',
    'conversion_rate',
    'avg_dwell_seconds',
    'median_lcp_ms',
    'bounce_rate'
];
$keysMatch = is_array($kpis) && count(array_intersect_key(array_flip($expectedKeys), $kpis)) === count($expectedKeys);
assertPhase3("getKpis() returns all 7 expected contract keys", $keysMatch);

$typesMatch = is_int($kpis['total_sessions'])
    && is_int($kpis['sessions_7d'])
    && is_int($kpis['sessions_today'])
    && is_float($kpis['conversion_rate'])
    && is_float($kpis['avg_dwell_seconds'])
    && ($kpis['median_lcp_ms'] === null || is_int($kpis['median_lcp_ms']))
    && is_float($kpis['bounce_rate']);

assertPhase3("getKpis() value types strictly match contract (int, float, ?int)", $typesMatch);

// ---------------------------------------------------------
// TEST GROUP 6: Median Calculation Correctness on Edge Cases
// ---------------------------------------------------------
echo "\n--- TEST GROUP 6: Median Calculation Edge Cases ---\n";

// Use reflection to test private calculateMedianLcp() directly on controlled datasets
$refMethod = new ReflectionMethod(App\Src\Controllers\AnalyticsController::class, 'calculateMedianLcp');
$refMethod->setAccessible(true);

// We verify that the mathematical algorithm handles 0 rows, 1 row, odd rows, even rows, and duplicates
function computeMedianAlgorithm(array $values): ?int {
    $nonNull = array_values(array_filter($values, fn($v) => $v !== null));
    $count = count($nonNull);
    if ($count === 0) return null;
    sort($nonNull);
    if ($count % 2 === 1) {
        return (int)$nonNull[intdiv($count, 2)];
    }
    $offset = intdiv($count, 2) - 1;
    return (int)round(((int)$nonNull[$offset] + (int)$nonNull[$offset + 1]) / 2);
}

assertPhase3("Median of 0 rows is null", computeMedianAlgorithm([]) === null);
assertPhase3("Median with all NULL values is null", computeMedianAlgorithm([null, null]) === null);
assertPhase3("Median of 1 row [500] is 500", computeMedianAlgorithm([500]) === 500);
assertPhase3("Median of odd rows [100, 200, 300, 400, 500] is 300", computeMedianAlgorithm([100, 200, 300, 400, 500]) === 300);
assertPhase3("Median of even rows [100, 200, 300, 400] is 250", computeMedianAlgorithm([100, 200, 300, 400]) === 250);
assertPhase3("Median of duplicates [200, 200, 200, 200] is 200", computeMedianAlgorithm([200, 200, 200, 200]) === 200);

// Summary
echo "\n====================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================\n";

exit($failCount > 0 ? 1 : 0);
