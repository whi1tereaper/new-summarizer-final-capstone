<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;

class AdminSecurityService
{
    public function __construct(private ?\PDO $database = null) {}

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

    /** Strict writer for mutations that must commit with their audit entry. */
    public function recordAudit(?int $adminId, string $action, string $details = '', ?string $entityType = null, ?int $entityId = null): void
    {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,99}\z/D', $action)) {
            throw new \InvalidArgumentException('Invalid audit action.');
        }
        $db = $this->database ?? Database::getInstance()->getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? '127.0.0.1' : null);
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'CLI' : '');
        $agent = preg_replace('/[\x00-\x1f\x7f]/', '', (string)$agent);
        $stmt = $db->prepare('INSERT INTO admin_audit_logs (admin_id,action,entity_type,entity_id,ip_address,user_agent,details) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$adminId,$action,$entityType===null?null:mb_strcut($entityType,0,100,'UTF-8'),$entityId,
            filter_var($ip,FILTER_VALIDATE_IP)?$ip:null,mb_strcut($agent,0,255,'UTF-8'),mb_strcut($details,0,4000,'UTF-8')]);
    }

    /** Best effort for denied/failed operations where there is no successful mutation. */
    public function auditLog(?int $adminId, string $action, string $details = '', ?string $entityType = null, ?int $entityId = null): void
    {
        try {
            $this->recordAudit($adminId,$action,$details,$entityType,$entityId);
        } catch (\Throwable $exception) {
            error_log('[AdminSecurityService] Audit write unavailable: ' . get_class($exception));
        }
    }
}
