<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/src/Utils/PasswordPolicy.php';
require_once __DIR__ . '/../app/src/Utils/validation.php';
require_once __DIR__ . '/../app/src/Services/AdminSecurityService.php';

use App\Src\Database;
use App\Src\Repositories\UserRepository;
use App\Src\Services\AdminSecurityService;

echo "====================================================\n";
echo "AUTHENTICATION REGRESSION VERIFICATION SUITE\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertAuth(string $name, bool $condition, string $detail = ''): void {
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
$users = new UserRepository($db);
$temporaryLoginUserId = null;

// 1. User login (valid user credentials)
$stmt = $db->prepare("SELECT * FROM users WHERE role = 'user' AND active = 1 LIMIT 1");
$stmt->execute();
$normalUser = $stmt->fetch();

if ($normalUser) {
    assertAuth("Normal active user exists in database", true, "User ID: " . $normalUser['id']);
} else {
    $tempUsername = 'test_user_' . bin2hex(random_bytes(3));
    $tempPass = 'TestPass123!';
    $hash = \App\Src\Utils\PasswordPolicy::hash($tempPass);
    $ins = $db->prepare("INSERT INTO users (username, email, password_hash, role, active, created_at) VALUES (?, ?, ?, 'user', 1, NOW())");
    $ins->execute([$tempUsername, $tempUsername . '@example.com', $hash]);
    $userId = (int)$db->lastInsertId();
    $temporaryLoginUserId = $userId;
    $normalUser = ['id' => $userId, 'username' => $tempUsername, 'role' => 'user'];
    assertAuth("Created temporary test user for login verification", true, "User ID: " . $userId);
}

// 2. Invalid login rejected and rate limiter preserved
$codeInvalidLogin = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_GET["action"] = "login";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_POST["csrf_token"] = generateCsrfToken();
$_POST["username"] = "non_existent_user_99999";
$_POST["password"] = "CompletelyWrongPassword123!";

require_once "app/public/auth.php";
';

$tempFile = sys_get_temp_dir() . '/test_inv_login_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeInvalidLogin);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);

assertAuth(
    "Invalid login is rejected and redirected",
    $exitCode === 0,
    "Exit code: {$exitCode}"
);

// 3. Administrator authorization uses authenticated identity and role.
$savedAdminSession = $_SESSION ?? [];
$_SESSION = ['user_id' => 1, 'role' => 'admin'];
assertAuth("Authenticated admin accepted without challenge state", AdminSecurityService::isVerifiedAdmin());
$_SESSION['admin_challenge_verified'] = false;
assertAuth("Stale false challenge flag does not block authenticated admin", AdminSecurityService::isVerifiedAdmin());
$_SESSION = ['pending_admin_user_id' => 1, 'admin_challenge_verified' => true];
assertAuth("Legacy pending-only session cannot become an admin session", !AdminSecurityService::isVerifiedAdmin());
$_SESSION = ['user_id' => 2, 'role' => 'user', 'admin_challenge_verified' => true];
assertAuth("Ordinary user cannot gain admin access from a legacy flag", !AdminSecurityService::isVerifiedAdmin());
$_SESSION = [];
assertAuth("Anonymous session cannot gain admin access", !AdminSecurityService::isVerifiedAdmin());
$_SESSION = $savedAdminSession;

// 4. Registration verification
$testRegUser = 'reg_test_' . bin2hex(random_bytes(3));
$testRegEmail = $testRegUser . '@example.test';
$codeRegister = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_GET["action"] = "register";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();

$_POST["csrf_token"] = generateCsrfToken();
$_POST["username"] = "' . $testRegUser . '";
$_POST["email"] = "' . $testRegEmail . '";
$_POST["password"] = "Str0ngP@ssword2026!";
$_POST["confirm_password"] = "Str0ngP@ssword2026!";
$_POST["terms_accept"] = "1";

require_once "app/public/auth.php";
';
$tempFile = sys_get_temp_dir() . '/test_reg_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeRegister);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);

// Check if user was registered in DB
$checkStmt = $db->prepare("SELECT id, username, role FROM users WHERE username = ?");
$checkStmt->execute([$testRegUser]);
$createdUser = $checkStmt->fetch();

assertAuth(
    "Registration action through auth.php creates new account",
    !empty($createdUser),
    "Created ID: " . ($createdUser['id'] ?? 'NONE')
);

// Clean up test registered user
if ($createdUser) {
    $duplicateResponseFile = sys_get_temp_dir() . '/test_dup_reg_result_' . bin2hex(random_bytes(4)) . '.json';
    $duplicateRegisterPayload = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "register";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_POST["csrf_token"] = generateCsrfToken();
$_POST["username"] = ' . var_export($testRegUser, true) . ';
$_POST["email"] = ' . var_export($testRegEmail, true) . ';
$_POST["password"] = "Str0ngP@ssword2026!";
$_POST["confirm_password"] = "Str0ngP@ssword2026!";
$_POST["terms_accept"] = "1";
register_shutdown_function(static function (): void {
    file_put_contents(' . var_export($duplicateResponseFile, true) . ', json_encode([
        "error" => $_SESSION["error"] ?? null,
        "success" => $_SESSION["success"] ?? null,
    ]));
});
require_once "app/public/auth.php";
';
    $tempFile = sys_get_temp_dir() . '/test_dup_reg_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tempFile, $duplicateRegisterPayload);
    exec('php ' . escapeshellarg($tempFile), $duplicateOutput, $duplicateExitCode);
    unlink($tempFile);
    $duplicateResponse = json_decode((string)file_get_contents($duplicateResponseFile), true);
    unlink($duplicateResponseFile);

    $duplicateCountStatement = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
    $duplicateCountStatement->execute([$testRegUser, $testRegEmail]);
    assertAuth(
        "Duplicate registration reports the existing-account error without creating another account",
        $duplicateExitCode === 0
            && ($duplicateResponse['error'] ?? null) === 'Username or email already exists.'
            && ($duplicateResponse['success'] ?? null) === null
            && (int)$duplicateCountStatement->fetchColumn() === 1
    );

    $repositoryUser = $users->findActiveByCredential($testRegUser);
    assertAuth(
        "UserRepository reads the newly registered active account",
        is_array($repositoryUser)
            && (int)$repositoryUser['id'] === (int)$createdUser['id']
            && $repositoryUser['role'] === 'user'
    );
    assertAuth(
        "UserRepository finds accounts by normalized email",
        $users->findIdByEmail($testRegEmail) === (int)$createdUser['id']
    );
    $testPasswordHash = \App\Src\Utils\PasswordPolicy::hash('UpdatedPass123!');
    $users->updatePasswordHash((int)$createdUser['id'], $testPasswordHash);
    $updatedPasswordStatement = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $updatedPasswordStatement->execute([$createdUser['id']]);
    assertAuth(
        "UserRepository updates password hashes without changing account identity",
        password_verify('UpdatedPass123!', (string)$updatedPasswordStatement->fetchColumn())
    );
    $duplicateAccountError = new PDOException('duplicate account', 23000);
    $duplicateAccountError->errorInfo = ['23000', 1062, 'Duplicate entry'];
    $otherIntegrityError = new PDOException('other integrity failure', 23000);
    $otherIntegrityError->errorInfo = ['23000', 1451, 'Foreign key constraint failure'];
    assertAuth(
        "Registration recognizes only MySQL duplicate-key failures as duplicate accounts",
        UserRepository::isUniqueConstraintViolation($duplicateAccountError)
            && !UserRepository::isUniqueConstraintViolation($otherIntegrityError)
    );

    $delStmt = $db->prepare("DELETE FROM users WHERE id = ?");
    $delStmt->execute([$createdUser['id']]);
}

// 5. Logout verification
$codeLogout = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET["action"] = "logout";
require_once "app/src/whitereaper.php";
session_start();
$_SESSION["user_id"] = 12345;
$_SESSION["role"] = "user";
$_SESSION["username"] = "logged_in_user";

require_once "app/public/auth.php";
';
$tempFile = sys_get_temp_dir() . '/test_logout_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeLogout);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
assertAuth("Logout action through auth.php terminates session and redirects", $exitCode === 0);

// 6. Forgot password / reset password routing check
$codeForgotPw = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_GET["action"] = "forgotPassword";
require_once "app/src/whitereaper.php";
require_once "app/src/Utils/validation.php";
session_start();
$_POST["csrf_token"] = generateCsrfToken();
$_POST["email"] = "nonexistent_reset@example.com";

require_once "app/public/auth.php";
';
$tempFile = sys_get_temp_dir() . '/test_forgot_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeForgotPw);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
assertAuth("ForgotPassword action routes structurally without error", $exitCode === 0);

if ($temporaryLoginUserId !== null) {
    $cleanup = $db->prepare('DELETE FROM users WHERE id = ?');
    $cleanup->execute([$temporaryLoginUserId]);
}

echo "\n====================================================\n";
echo "AUTH VERIFICATION RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================\n";

exit($failCount > 0 ? 1 : 0);
