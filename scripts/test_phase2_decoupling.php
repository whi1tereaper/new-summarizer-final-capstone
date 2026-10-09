<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';

use App\Src\Database;

echo "====================================================\n";
echo "PHASE 2 DECOUPLING & REQUEST BOUNDARY VERIFICATION\n";
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
// TEST GROUP 1: Include Safety (No Side Effects on require)
// ---------------------------------------------------------
echo "--- TEST GROUP 1: Include Safety (Zero File-Scope Execution) ---\n";

// Test 1.1: Include ArticleController.php directly in an isolated process
$codeArticle = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST["csrf_token"] = "fake";
$_GET["action"] = "execute";
ob_start();
require_once "app/src/Controllers/ArticleController.php";
$out = ob_get_clean();
if ($out !== "") {
    echo "OUTPUT_EMITTED: " . $out;
    exit(2);
}
if (!class_exists("App\\Src\\Controllers\\ArticleController")) {
    echo "CLASS_MISSING";
    exit(3);
}
echo "OK";
exit(0);
';

$tempFile = sys_get_temp_dir() . '/test_art_inc_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeArticle);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$resultText = implode("\n", $output);

assertTest(
    "Including ArticleController.php produces no output or premature exit",
    $exitCode === 0 && trim($resultText) === 'OK',
    "Exit code: {$exitCode}, Output: {$resultText}"
);

// Test 1.2: Include AuthController.php directly in an isolated process
$codeAuth = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "login";
$_POST["username"] = "admin";
$_POST["password"] = "wrong";
ob_start();
require_once "app/src/Controllers/AuthController.php";
$out = ob_get_clean();
if ($out !== "") {
    echo "OUTPUT_EMITTED: " . $out;
    exit(2);
}
if (!class_exists("App\\Src\\Controllers\\AuthController")) {
    echo "CLASS_MISSING";
    exit(3);
}
echo "OK";
exit(0);
';

$tempFile = sys_get_temp_dir() . '/test_auth_inc_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAuth);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$resultText = implode("\n", $output);

assertTest(
    "Including AuthController.php produces no output or premature exit",
    $exitCode === 0 && trim($resultText) === 'OK',
    "Exit code: {$exitCode}, Output: {$resultText}"
);

// ---------------------------------------------------------
// TEST GROUP 2: Controller Instantiation (No Side Effects on new)
// ---------------------------------------------------------
echo "\n--- TEST GROUP 2: Controller Instantiation Safety ---\n";

require_once __DIR__ . '/../app/src/Controllers/ArticleController.php';
require_once __DIR__ . '/../app/src/Controllers/AuthController.php';

use App\Src\Controllers\ArticleController;
use App\Src\Controllers\AuthController;

$articleController = null;
$articleConstructed = false;
try {
    $articleController = new ArticleController();
    $articleConstructed = ($articleController instanceof ArticleController);
} catch (\Throwable $e) {
    $articleConstructed = false;
}
assertTest("ArticleController can be instantiated without triggering actions", $articleConstructed);

$authController = null;
$authConstructed = false;
try {
    $authController = new AuthController();
    $authConstructed = ($authController instanceof AuthController);
} catch (\Throwable $e) {
    $authConstructed = false;
}
assertTest("AuthController can be instantiated without triggering actions", $authConstructed);

// ---------------------------------------------------------
// TEST GROUP 3: Explicit Dispatch - summarize.php
// ---------------------------------------------------------
echo "\n--- TEST GROUP 3: Explicit Dispatch - summarize.php ---\n";

// Test 3.1: summarize.php GET request redirects to summarizer.php
$codeSummarizeGet = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
require_once "app/public/summarize.php";
';
$tempFile = sys_get_temp_dir() . '/test_sum_get_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeSummarizeGet);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
// Non-POST calls header("Location: summarizer.php") and exit(0)
assertTest("summarize.php with GET redirects non-POST requests", $exitCode === 0);

// Test 3.2: summarize.php?action=execute with no pending summary returns 400 JSON
$codeSummarizeExecNoPending = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET["action"] = "execute";
session_start();
unset($_SESSION["pending_summary"]);
require_once "app/public/summarize.php";
';
$tempFile = sys_get_temp_dir() . '/test_sum_exec_nopend_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeSummarizeExecNoPending);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$rawText = implode('', $output);
$jsonOutput = json_decode($rawText, true);
assertTest(
    "summarize.php?action=execute returns 400 JSON when no pending summary",
    isset($jsonOutput['error']) && $jsonOutput['error'] === 'No pending summary found.',
    $rawText
);

// ---------------------------------------------------------
// TEST GROUP 4: Explicit Dispatch - auth.php
// ---------------------------------------------------------
echo "\n--- TEST GROUP 4: Explicit Dispatch - auth.php ---\n";

// Test 4.1: auth.php with no action redirects to login.php
$codeAuthNoAction = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
unset($_GET["action"]);
unset($_POST["action"]);
require_once "app/public/auth.php";
';
$tempFile = sys_get_temp_dir() . '/test_auth_noaction_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAuthNoAction);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
assertTest("auth.php without action safely redirects to login.php", $exitCode === 0);

// Test 4.2: auth.php with unknown action returns 400 Bad Request
$codeAuthUnknownAction = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET["action"] = "malicious_unrecognized_action";
require_once "app/public/auth.php";
';
$tempFile = sys_get_temp_dir() . '/test_auth_unknown_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAuthUnknownAction);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$outText = implode('', $output);
assertTest(
    "auth.php with unknown action deliberately rejects with Bad Request (400)",
    str_contains($outText, 'Bad Request: Invalid auth action.')
);

// Test 4.3: auth.php?action=login rejects invalid CSRF
$codeAuthBadCsrf = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "login";
$_POST["csrf_token"] = "invalid_token_123";
$_POST["username"] = "testuser";
$_POST["password"] = "SecretPassword123";
session_start();
require_once "app/public/auth.php";
';
$tempFile = sys_get_temp_dir() . '/test_auth_badcsrf_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAuthBadCsrf);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$outText = implode('', $output);
assertTest(
    "auth.php?action=login enforces CSRF token verification",
    str_contains($outText, 'Invalid CSRF token.')
);

// Test 4.4: auth.php?action=logout terminates session
$codeAuthLogout = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET["action"] = "logout";
session_start();
$_SESSION["user_id"] = 999;
$_SESSION["username"] = "testadmin";
require_once "app/public/auth.php";
';
$tempFile = sys_get_temp_dir() . '/test_auth_logout_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAuthLogout);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
assertTest("auth.php?action=logout executes session logout and redirect", $exitCode === 0);

// ---------------------------------------------------------
// TEST GROUP 5: History Analytics Cleanup
// ---------------------------------------------------------
echo "\n--- TEST GROUP 5: History Analytics Cleanup ---\n";

$historyContent = file_get_contents(__DIR__ . '/../app/public/history.php');

assertTest(
    "history.php contains no AnalyticsController import or reference",
    !str_contains($historyContent, 'AnalyticsController')
);

assertTest(
    "history.php contains no getKpis() call",
    !str_contains($historyContent, 'getKpis()')
);

assertTest(
    "history.php contains no getDeviceBreakdown() call",
    !str_contains($historyContent, 'getDeviceBreakdown()')
);

assertTest(
    "history.php contains no getScrollFunnel() call",
    !str_contains($historyContent, 'getScrollFunnel()')
);

assertTest(
    "history.php contains no getDailyTrend() call",
    !str_contains($historyContent, 'getDailyTrend()')
);

assertTest(
    "history.php contains no getTopReferrers() call",
    !str_contains($historyContent, 'getTopReferrers()')
);

// ---------------------------------------------------------
// TEST GROUP 6: End-to-End Summarization Workflow Verification
// ---------------------------------------------------------
echo "\n--- TEST GROUP 6: Summarization Stage 1 & Stage 2 Execution ---\n";

// Test 6.1: Stage 1 form POST builds pending summary in session
$codeStage1 = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
require_once "app/src/Services/ArticleService.php";

session_start();
$_SESSION["guest_token"] = "test_guest_" . bin2hex(random_bytes(8));
$_SESSION["guest_terms_reviewed"] = true;
$_SESSION["guest_terms_accepted"] = true;

$token = generateCsrfToken();
$_POST["csrf_token"] = $token;
$_POST["guest_terms_accept"] = "1";
$_POST["source_type"] = "text";
$_POST["original_text"] = "Quantum computing harnesses quantum mechanics to solve complex problems much faster than classical computers.";
$_POST["summary_style"] = "standard_paragraph";
$_POST["summary_length"] = "balanced";
$_POST["sentence_count"] = 3;

$service = new App\Src\Services\ArticleService();
$pending = $service->buildPendingSummary($_POST, $_FILES, null, $_SESSION["guest_token"]);
$_SESSION["pending_summary"] = $pending;

echo json_encode([
    "staged" => !empty($_SESSION["pending_summary"]),
    "original_text" => $_SESSION["pending_summary"]["original_text"] ?? "",
    "summary_style" => $_SESSION["pending_summary"]["summary_style"] ?? ""
]);
';

$tempFile = sys_get_temp_dir() . '/test_stg1_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeStage1);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$stg1Result = json_decode(implode('', $output), true);

assertTest(
    "Stage 1 prepares pending summary in session",
    !empty($stg1Result['staged']) && !empty($stg1Result['original_text']),
    "Staged: " . (!empty($stg1Result['staged']) ? 'YES' : 'NO')
);

// Test 6.2: Stage 2 executes summarizer and returns valid redirect contract
$codeStage2 = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET["action"] = "execute";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
require_once "app/src/Controllers/ArticleController.php";

session_start();
$guestToken = "test_guest_" . bin2hex(random_bytes(8));
$_SESSION["pending_summary"] = [
    "original_text" => "Quantum computing harnesses quantum mechanics to solve complex problems exponentially faster than classical computers. Quantum bits or qubits can exist in superposition states. Entanglement enables interconnected processing capabilities.",
    "file_path" => "",
    "sentence_count" => 3,
    "summary_depth" => "balanced",
    "summary_length" => "balanced",
    "summary_style" => "standard_paragraph",
    "document_title" => "Quantum Computing Intro",
    "selection_mode" => "general",
    "analysis_mode" => "general",
    "output_format" => "paragraph",
    "user_id" => null,
    "guest_token" => $guestToken,
    "csrf_token" => generateCsrfToken(),
];

$controller = new App\Src\Controllers\ArticleController();
// execute() outputs JSON and calls exit
$controller->execute();
';

$tempFile = sys_get_temp_dir() . '/test_stg2_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeStage2);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$rawText = implode('', $output);
$stg2Result = json_decode($rawText, true);

assertTest(
    "Stage 2 executes summarizer and returns valid redirect contract",
    !empty($stg2Result['success']) && str_starts_with((string)($stg2Result['redirect'] ?? ''), 'result.php?id='),
    "Redirect: " . ($stg2Result['redirect'] ?? 'NONE')
);

// ---------------------------------------------------------
// TEST GROUP 7: Administrator Role Authorization
// ---------------------------------------------------------
echo "\n--- TEST GROUP 7: Administrator Role Authorization ---\n";

// Check the authorization boundary independently of challenge session fields.
$codeAdminLogin = '<?php
require_once "app/src/whitereaper.php";
require_once "app/src/Services/AdminSecurityService.php";
$_SESSION = ["user_id" => 1, "role" => "admin"];
$authenticatedAdmin = App\Src\Services\AdminSecurityService::isVerifiedAdmin();
$_SESSION = ["pending_admin_user_id" => 1, "admin_challenge_verified" => true];
$legacyPendingAdmin = App\Src\Services\AdminSecurityService::isVerifiedAdmin();
$_SESSION = ["user_id" => 2, "role" => "user", "admin_challenge_verified" => true];
$ordinaryUser = App\Src\Services\AdminSecurityService::isVerifiedAdmin();
echo json_encode(["authenticated_admin" => $authenticatedAdmin, "legacy_pending_admin" => $legacyPendingAdmin, "ordinary_user" => $ordinaryUser]);
';

$tempFile = sys_get_temp_dir() . '/test_admin_flow_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeAdminLogin);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$adminResult = json_decode(implode('', $output), true);

assertTest(
    "Authenticated administrator needs no legacy challenge flag",
    $exitCode === 0 && ($adminResult['authenticated_admin'] ?? false) === true
);
assertTest("Legacy pending-only session is not elevated", ($adminResult['legacy_pending_admin'] ?? null) === false);
assertTest("Ordinary user with legacy true flag is not elevated", ($adminResult['ordinary_user'] ?? null) === false);

// Summary
echo "\n====================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================\n";

exit($failCount > 0 ? 1 : 0);
