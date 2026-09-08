<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be executed from CLI.');
}

require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Services/AdminSecurityService.php';

use App\Src\Database;
use App\Src\Services\AdminSecurityService;

$email = readline('Admin email: ');
$anime = readline('New favorite anime: ');
$number = readline('New favorite number: ');

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare("
    SELECT id, role
    FROM users
    WHERE email = :email
    LIMIT 1
");

$stmt->execute([
    ':email' => trim($email)
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] !== 'admin') {
    error_log('[AdminSecurity] CLI Reset failed: Admin account not found for email ' . $email);
    exit("Admin account not found.\n");
}

$service = new AdminSecurityService();
try {
    $service->createOrUpdateChallenge((int) $user['id'], $anime, $number);
    
    // Explicitly reset any lockout or failed attempts that might be lingering
    $resetStmt = $pdo->prepare("UPDATE admin_security_challenges SET failed_attempts = 0, locked_until = NULL WHERE user_id = :user_id");
    $resetStmt->execute(['user_id' => $user['id']]);

    $service->auditLog((int)$user['id'], 'cli_challenge_reset_completed', 'Reset via CLI script');
    
    error_log('[AdminSecurity] CLI Reset successful for admin ID: ' . $user['id']);
    echo "Admin security challenge reset successfully.\n";
} catch (\InvalidArgumentException $e) {
    exit("Error: " . $e->getMessage() . "\n");
} catch (\Exception $e) {
    exit("An unexpected error occurred: " . $e->getMessage() . "\n");
}
