<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;
use InvalidArgumentException;
use PDO;

class AdminSecurityService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function normalizeAdminChallenge(string $anime, string $number): string
    {
        $anime = trim($anime);
        $anime = mb_strtolower($anime, 'UTF-8');
        $anime = preg_replace('/\s+/', ' ', $anime);

        $number = trim($number);

        if (!preg_match('/^\d+$/', $number)) {
            throw new InvalidArgumentException('Invalid number format.');
        }

        return $anime . ':' . $number;
    }

    public function createOrUpdateChallenge(int $userId, string $anime, string $number): void
    {
        $secret = $this->normalizeAdminChallenge($anime, $number);
        $hash = password_hash($secret, PASSWORD_DEFAULT);

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO admin_security_challenges (user_id, challenge_hash, updated_at)
            VALUES (:user_id, :hash, NOW())
            ON DUPLICATE KEY UPDATE challenge_hash = :hash_update, updated_at = NOW()
        ");
        $stmt->execute([
            'user_id' => $userId,
            'hash' => $hash,
            'hash_update' => $hash
        ]);
    }

    public function hasChallengeSetup(int $userId): bool
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT 1 FROM admin_security_challenges WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public function verifyChallenge(int $userId, string $anime, string $number): array
    {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT * FROM admin_security_challenges WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            // No challenge setup for this admin user
            return ['error' => 'Invalid verification answer.', 'locked' => false];
        }

        if ($record['locked_until'] !== null) {
            $lockedUntil = new \DateTime($record['locked_until']);
            $now = new \DateTime();
            if ($now < $lockedUntil) {
                return ['error' => 'Too many failed attempts. Try again later.', 'locked' => true];
            } else {
                // Lockout expired, reset attempts
                $resetStmt = $db->prepare("UPDATE admin_security_challenges SET failed_attempts = 0, locked_until = NULL WHERE id = :id");
                $resetStmt->execute(['id' => $record['id']]);
                $record['failed_attempts'] = 0;
            }
        }

        try {
            $secret = $this->normalizeAdminChallenge($anime, $number);
        } catch (InvalidArgumentException $e) {
            $this->incrementFailedAttempts($db, (int)$record['id'], (int)$record['failed_attempts'], $userId);
            $this->auditLog($userId, 'admin_challenge_verification_failed', 'Normalization failed');
            return ['error' => 'Invalid verification answer.', 'locked' => false];
        }

        if (!password_verify($secret, $record['challenge_hash'])) {
            $this->incrementFailedAttempts($db, (int)$record['id'], (int)$record['failed_attempts'], $userId);
            $this->auditLog($userId, 'admin_challenge_verification_failed', 'Hash mismatch');
            return ['error' => 'Invalid verification answer.', 'locked' => false];
        }

        // Success, reset failed attempts
        if ((int)$record['failed_attempts'] > 0) {
            $resetStmt = $db->prepare("UPDATE admin_security_challenges SET failed_attempts = 0, locked_until = NULL WHERE id = :id");
            $resetStmt->execute(['id' => $record['id']]);
        }
        
        $this->auditLog($userId, 'admin_challenge_verification_success', 'Verification successful');

        return ['success' => true];
    }

    private function incrementFailedAttempts(PDO $db, int $recordId, int $currentAttempts, int $userId): void
    {
        $newAttempts = $currentAttempts + 1;
        $lockedUntil = null;

        if ($newAttempts >= self::MAX_ATTEMPTS) {
            $now = new \DateTime();
            $now->modify('+' . self::LOCKOUT_MINUTES . ' minutes');
            $lockedUntil = $now->format('Y-m-d H:i:s');
            $this->auditLog($userId, 'admin_challenge_lockout_triggered', 'Account locked out after ' . self::MAX_ATTEMPTS . ' failed attempts');
        }

        $stmt = $db->prepare("UPDATE admin_security_challenges SET failed_attempts = :attempts, locked_until = :locked WHERE id = :id");
        $stmt->execute([
            'attempts' => $newAttempts,
            'locked' => $lockedUntil,
            'id' => $recordId
        ]);
    }

    private static bool $schemaVerified = false;

    private function ensureAuditLogsTable(): void
    {
        if (self::$schemaVerified) {
            return;
        }
        
        try {
            $db = Database::getInstance()->getConnection();
            $db->exec("
                CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `admin_id` int(11) NULL,
                    `action` VARCHAR(100) NOT NULL,
                    `entity_type` VARCHAR(100) NULL,
                    `entity_id` BIGINT UNSIGNED NULL,
                    `ip_address` VARCHAR(45) NULL,
                    `user_agent` VARCHAR(255) NULL,
                    `details` TEXT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_admin_audit_logs_admin_id` (`admin_id`),
                    INDEX `idx_admin_audit_logs_action` (`action`),
                    INDEX `idx_admin_audit_logs_created_at` (`created_at`),
                    INDEX `idx_admin_audit_entity` (`entity_type`, `entity_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            
            // Alter table to add columns if they don't exist yet
            $cols = $db->query("SHOW COLUMNS FROM admin_audit_logs")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('entity_type', $cols)) {
                $db->exec("ALTER TABLE admin_audit_logs ADD COLUMN entity_type VARCHAR(100) NULL AFTER action, ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER entity_type, ADD INDEX idx_admin_audit_entity (entity_type, entity_id)");
            }

            self::$schemaVerified = true;
        } catch (\Throwable $e) {
            error_log('[AdminSecurityService] ensureAuditLogsTable error: ' . $e->getMessage());
        }
    }

    public function auditLog(?int $adminId, string $action, string $details = '', ?string $entityType = null, ?int $entityId = null): void
    {
        $this->ensureAuditLogsTable();

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                INSERT INTO admin_audit_logs (admin_id, action, entity_type, entity_id, ip_address, user_agent, details)
                VALUES (:admin_id, :action, :entity_type, :entity_id, :ip_address, :user_agent, :details)
            ");
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? '127.0.0.1' : '');
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'CLI' : '');
            
            $stmt->execute([
                'admin_id' => $adminId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $ip,
                'user_agent' => $ua,
                'details' => $details
            ]);
        } catch (\Throwable $exception) {
            error_log('[AdminSecurityService] Failed to insert audit log: ' . $exception->getMessage());
        }
    }
}
