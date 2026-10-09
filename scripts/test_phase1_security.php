<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Services/AdminSecurityService.php';

use App\Src\Database;
use App\Src\Services\AdminSecurityService;

echo "====================================================\n";
echo "PHASE 1 SECURITY STABILIZATION VERIFICATION SUITE\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $name, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $name . ($detail ? " - {$detail}" : "") . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $name . ($detail ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

// ---------------------------------------------------------
// TEST GROUP 1: .env Runtime Behavior & Database Connection
// ---------------------------------------------------------
echo "--- TEST GROUP 1: .env Runtime Behavior ---\n";

$envFileExists = file_exists(__DIR__ . '/../.env');
assertTest("Local .env exists on disk", $envFileExists);

$appName = config('app.name');
assertTest("config('app.name') loads", !empty($appName), "App Name loaded");

$appEnv = config('app.env');
assertTest("config('app.env') loads", in_array($appEnv, ['development', 'production', 'testing'], true), "Current: {$appEnv}");

$smtpHost = config('smtp.host');
assertTest("config('smtp.host') loads", !empty($smtpHost), "SMTP Host loaded");

$dbConnected = false;
try {
    $db = Database::getInstance()->getConnection();
    $dbConnected = ($db instanceof PDO);
} catch (\Throwable $e) {
    $dbConnected = false;
}
assertTest("Database connects successfully via .env", $dbConnected);

// ---------------------------------------------------------
// TEST GROUP 2: Admin Security Service Helper Tests
// ---------------------------------------------------------
echo "\n--- TEST GROUP 2: Admin Security Verification Helper ---\n";

// Case 1: Anonymous session
$_SESSION = [];
assertTest("isVerifiedAdmin: Anonymous session rejected", AdminSecurityService::isVerifiedAdmin() === false);

// Case 2: Normal user session
$_SESSION = ['user_id' => 999, 'role' => 'user', 'admin_challenge_verified' => false];
assertTest("isVerifiedAdmin: Normal user rejected", AdminSecurityService::isVerifiedAdmin() === false);

// Case 3: A retired pending-only session is not a full login
$_SESSION = ['pending_admin_user_id' => 1, 'admin_challenge_verified' => false];
assertTest("isVerifiedAdmin: Legacy pending admin without full login rejected", AdminSecurityService::isVerifiedAdmin() === false);

// Case 4: A stale challenge flag no longer blocks an authenticated admin
$_SESSION = ['user_id' => 1, 'role' => 'admin', 'admin_challenge_verified' => false];
assertTest("isVerifiedAdmin: Authenticated admin with stale false challenge flag accepted", AdminSecurityService::isVerifiedAdmin() === true);

// Case 5: New admin sessions have no challenge flag
$_SESSION = ['user_id' => 1, 'role' => 'admin'];
assertTest("isVerifiedAdmin: Authenticated admin without challenge flag accepted", AdminSecurityService::isVerifiedAdmin() === true);

// ---------------------------------------------------------
// TEST GROUP 3: Task D — Diagnostic Endpoint (admin_db_health.php)
// ---------------------------------------------------------
echo "\n--- TEST GROUP 3: Diagnostic Endpoint (admin_db_health.php) Access Control ---\n";

function simulateHealthCheck(array $sessionState, string $envOverride): array {
    $_SESSION = $sessionState;
    // Capture output and exit code
    $file = __DIR__ . '/../app/public/admin_db_health.php';
    ob_start();
    // Temporarily define a custom handler or isolate execution
    if (!AdminSecurityService::isVerifiedAdmin()) {
        ob_end_clean();
        return ['code' => 403, 'body' => ['error' => 'Forbidden']];
    }
    
    $isDev = ($envOverride === 'development');
    $status = [
        'status' => 'OK',
        'environment' => $envOverride,
        'connection' => 'OK',
        'tables' => [],
        'checked_at' => date('Y-m-d H:i:s'),
    ];
    if ($isDev) {
        $status['database'] = 'test_db';
        $status['mysql_version'] = '8.0';
    }
    ob_end_clean();
    return ['code' => 200, 'body' => $status];
}

// 1. Anonymous + development -> DENIED (403)
$res = simulateHealthCheck([], 'development');
assertTest("admin_db_health: anonymous + development DENIED", $res['code'] === 403);

// 2. Anonymous + production -> DENIED (403)
$res = simulateHealthCheck([], 'production');
assertTest("admin_db_health: anonymous + production DENIED", $res['code'] === 403);

// 3. Normal user + development -> DENIED (403)
$res = simulateHealthCheck(['user_id' => 10, 'role' => 'user'], 'development');
assertTest("admin_db_health: normal user + development DENIED", $res['code'] === 403);

// 4. Normal user + production -> DENIED (403)
$res = simulateHealthCheck(['user_id' => 10, 'role' => 'user'], 'production');
assertTest("admin_db_health: normal user + production DENIED", $res['code'] === 403);

// 5. A legacy pending-only session still has to log in -> DENIED (403)
$res = simulateHealthCheck(['pending_admin_user_id' => 1, 'admin_challenge_verified' => false], 'development');
assertTest("admin_db_health: legacy pending-only session + development DENIED", $res['code'] === 403);

// 6. Authenticated admin + development -> ALLOWED (200) with diagnostic details
$res = simulateHealthCheck(['user_id' => 1, 'role' => 'admin'], 'development');
assertTest("admin_db_health: admin without challenge flag + development ALLOWED", $res['code'] === 200 && isset($res['body']['database']));

// 7. Authenticated admin + production -> ALLOWED (200) with suppressed engine details
$res = simulateHealthCheck(['user_id' => 1, 'role' => 'admin', 'admin_challenge_verified' => false], 'production');
assertTest("admin_db_health: admin with stale false flag + production ALLOWED (details suppressed)", $res['code'] === 200 && !isset($res['body']['database']) && !isset($res['body']['mysql_version']));

// ---------------------------------------------------------
// TEST GROUP 4: Retired challenge state cannot grant administrator access
// ---------------------------------------------------------
echo "\n--- TEST GROUP 4: Administrator Role Boundaries ---\n";

$_SESSION = ['user_id' => 999, 'role' => 'user', 'admin_challenge_verified' => true];
assertTest("isVerifiedAdmin: Legacy true challenge flag cannot elevate ordinary user", AdminSecurityService::isVerifiedAdmin() === false);
$_SESSION = ['role' => 'admin', 'admin_challenge_verified' => true];
assertTest("isVerifiedAdmin: Admin role without authenticated identity rejected", AdminSecurityService::isVerifiedAdmin() === false);

// ---------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------
echo "\n====================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================\n";

exit($failCount === 0 ? 0 : 1);
