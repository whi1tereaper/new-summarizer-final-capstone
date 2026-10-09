<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;

class AdminSecurityService
{
    // Retain the shared guard name for callers; admin access uses the authenticated role.
    public static function isVerifiedAdmin(): bool
    {
        return !empty($_SESSION['user_id'])
            && (($_SESSION['role'] ?? '') === 'admin');
    }

    public static function requireVerifiedAdmin(string $redirect = 'login.php?context=admin'): void
    {
        if (!self::isVerifiedAdmin()) {
            header('Location: ' . $redirect);
            exit;
        }
    }

    public static function requireVerifiedAdminApi(): void
    {
        if (!self::isVerifiedAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
    }

    private function ensureAuditLogsTable(): void
    {
        // Schema is managed via database/migrations/003_admin_audit_logs.sql
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
