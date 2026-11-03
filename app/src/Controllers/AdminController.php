<?php
namespace App\Src\Controllers;

use App\Src\Database;
use App\Src\Services\AdminSecurityService;
use App\Src\Services\GuestSessionService;
use App\Src\Services\TermsAcceptanceService;
use App\Src\Support\RuntimePaths;
use PDO;

require_once __DIR__ . '/FeedbackHandler.php';
require_once __DIR__ . '/../whitereaper.php';
require_once __DIR__ . '/../Utils/validation.php';
require_once __DIR__ . '/../Services/AdminSecurityService.php';

use App\Src\Controllers\FeedbackHandler;

class AdminController {
    private PDO $db;
    private AdminSecurityService $auditSvc;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->auditSvc = new AdminSecurityService($this->db);
        $this->ensureAdmin();
    }

    private function ensureAdmin() {
        if (!AdminSecurityService::isVerifiedAdmin()) {
            http_response_code(403);
            die('Access Denied: Administrative privileges required.');
        }
    }

    /**
     * Wrapper so controllers don't call AdminSecurityService directly.
     * Never throws — audit failures must not crash the delete flow.
     */
    private function audit(
        string $action,
        string $details = '',
        ?string $entityType = null,
        ?int $entityId = null
    ): void {
        $adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->auditSvc->auditLog($adminId, $action, $details, $entityType, $entityId);
    }

    public function getAllUsers() {
        $stmt = $this->db->prepare(
            "SELECT id, username, email, role, active, created_at, terms_accepted, terms_accepted_at
             FROM users
             ORDER BY created_at DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getSystemStats(?string $from = null, ?string $to = null): array {
        $range = $this->normalizeStatsRange($from, $to);
        $toNext = $range !== null ? (new \DateTimeImmutable($range[1]))->modify('+1 day')->format('Y-m-d') : null;
        $dateClause = $range === null
            ? ''
            : ' WHERE created_at >= :from AND created_at < :to_next';
        $params = $range === null ? [] : ['from' => $range[0], 'to_next' => $toNext];

        $userStatement = $this->db->prepare('SELECT COUNT(*) FROM users' . $dateClause);
        $userStatement->execute($params);

        $summaryStatement = $this->db->prepare('SELECT COUNT(*) FROM summaries' . $dateClause);
        $summaryStatement->execute($params);

        $guestWhere = ' WHERE user_id IS NULL AND guest_token IS NOT NULL AND guest_token <> :empty_guest_token';
        if ($range !== null) {
            $guestWhere .= ' AND created_at >= :from AND created_at < :to_next';
        }
        $guestStatement = $this->db->prepare('SELECT COUNT(DISTINCT guest_token) FROM summaries' . $guestWhere);
        $guestParams = ['empty_guest_token' => ''];
        if ($range !== null) {
            $guestParams['from'] = $range[0];
            $guestParams['to_next'] = $toNext;
        }
        $guestStatement->execute($guestParams);

        return [
            'user_count' => (int)$userStatement->fetchColumn(),
            'summary_count' => (int)$summaryStatement->fetchColumn(),
            'guest_user_count' => (int)$guestStatement->fetchColumn(),
        ];
    }

    /** Validate date inputs before they are bound to system-stat queries. */
    private function normalizeStatsRange(?string $from, ?string $to): ?array {
        if ($from === null && $to === null) {
            return null;
        }
        if ($from === null || $to === null) {
            throw new \InvalidArgumentException('Both system-stat range dates are required.');
        }

        $timezone = new \DateTimeZone(date_default_timezone_get());
        $fromDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $toDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $timezone);
        if (!$fromDate || !$toDate || $fromDate->format('Y-m-d') !== $from || $toDate->format('Y-m-d') !== $to) {
            throw new \InvalidArgumentException('Invalid system-stat date range.');
        }
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }
        return [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
    }

    public function toggleUserStatus(int $userId, int $status): bool {
        if (!in_array($status, [0, 1], true)) {
            $_SESSION['flash_error'] = 'Invalid status value.';
            return false;
        }
        $auditTransaction=false;
        try {
            $this->db->beginTransaction();
            $auditTransaction=true;
            $stmt=$this->db->prepare('SELECT active FROM users WHERE id=? FOR UPDATE');$stmt->execute([$userId]);$previous=$stmt->fetchColumn();
            if($previous===false){$this->db->rollBack();$this->audit('admin_status_change_failed','Account not found.','user',$userId);return false;}
            if((int)$previous!==$status){
                $this->db->prepare('UPDATE users SET active=? WHERE id=?')->execute([$status,$userId]);
                $this->auditSvc->recordAudit((int)$_SESSION['user_id'],$status===1?'admin_user_activated':'admin_user_deactivated',
                    'Account status: '.((int)$previous===1?'active':'inactive').' -> '.($status===1?'active':'inactive').'.','user',$userId);
            }
            $this->db->commit();return true;
        }catch(\Throwable $exception){
            if($auditTransaction&&$this->db->inTransaction())$this->db->rollBack();
            $this->audit('admin_status_change_failed','Account status update could not be committed.','user',$userId);
            $_SESSION['flash_error']='The account status could not be saved. No changes were made.';return false;
        }
    }

    public function getStorageMaintenanceStats(): array {
        require_once __DIR__.'/../Services/StorageMaintenanceService.php';
        return (new \App\Src\Services\StorageMaintenanceService($this->db))->snapshot((int)($_SESSION['user_id']??0));
    }

    public function getUploadOrphanStats() {
        $count = 0;
        $size = 0;
        foreach (RuntimePaths::uploadDirectories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . DIRECTORY_SEPARATOR . '*.*');
            if (!$files) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file)) {
                    $count++;
                    $size += filesize($file);
                }
            }
        }
        return ['count' => $count, 'size_mb' => round($size / 1048576, 2)];
    }

    public function cleanOrphanedFiles() {
        $deleted = 0;
        $freed = 0;
        $timeNow = time();
        foreach (RuntimePaths::uploadDirectories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . DIRECTORY_SEPARATOR . '*.*');
            if (!$files) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file) && ($timeNow - filemtime($file)) > 86400) {
                    $freed += filesize($file);
                    unlink($file);
                    $deleted++;
                }
            }
        }
        $freedMb = round($freed / 1048576, 2);
        $this->audit(
            'admin_bulk_deleted_records',
            "Orphan cleanup: deleted {$deleted} file(s), freed {$freedMb} MB",
            'upload'
        );
        $_SESSION['flash_success'] = "Cleared {$deleted} orphaned file(s), freed {$freedMb} MB.";
    }

    public function deleteAllFiles() {
        $deleted = 0;
        $freed = 0;
        foreach (RuntimePaths::uploadDirectories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . DIRECTORY_SEPARATOR . '*.*');
            if (!$files) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file)) {
                    $freed += filesize($file);
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }
        $freedMb = round($freed / 1048576, 2);
        $this->audit(
            'admin_deleted_upload',
            "Bulk delete all uploads: {$deleted} file(s) deleted, {$freedMb} MB freed",
            'upload'
        );
        $_SESSION['flash_success'] = "Deleted {$deleted} file(s), freed {$freedMb} MB.";
    }

    public function purgeStaleGuestSummaries() {
        $result = (new GuestSessionService())->purgeExpiredGuests();
        $count = $result['sessions'] + $result['legacy_summaries'];
        $_SESSION['flash_success'] = "Purged " . $count . " stale guest session record(s).";
    }

    public function hardDeleteUser(int $userId) {
        $callerAdminId = (int)($_SESSION['user_id'] ?? 0);

        // Rule 1: Admin cannot delete themselves.
        if ($callerAdminId === $userId) {
            $this->audit('admin_delete_denied', 'Admin attempted self-deletion', 'user', $userId);
            $_SESSION['flash_error'] = 'Action Denied: You cannot delete your own account.';
            return;
        }

        $auditTransaction=false;
        try {
            $this->db->beginTransaction();
            $auditTransaction=true;
            $stmt = $this->db->prepare("SELECT role, username FROM users WHERE id = :id LIMIT 1 FOR UPDATE");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->db->rollBack();
                $this->audit('admin_delete_failed', 'User not found', 'user', $userId);
                $_SESSION['flash_error'] = 'User not found.';
                return;
            }

            // Rule 2: Admin accounts cannot be hard-deleted at all. Deactivate instead.
            if ($user['role'] === 'admin') {
                $this->db->rollBack();
                $this->audit('admin_delete_denied', 'Attempted to hard-delete an admin account', 'user', $userId);
                $_SESSION['flash_error'] = 'Action Denied: Admin accounts cannot be deleted. Use deactivation instead.';
                return;
            }

            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);

            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                $this->audit('admin_delete_failed', 'DELETE affected 0 rows', 'user', $userId);
                $_SESSION['flash_error'] = 'Delete failed: user may have already been removed.';
                return;
            }

            $this->auditSvc->recordAudit($callerAdminId,'admin_user_deleted','Account permanently deleted.','user',$userId);
            $this->db->commit();
            $_SESSION['flash_success'] = "User ID {$userId} ({$user['username']}) has been permanently deleted.";
        } catch (\Throwable $e) {
            if($auditTransaction&&$this->db->inTransaction())$this->db->rollBack();
            error_log('[AdminController] Account deletion unavailable: ' . get_class($e));
            $this->audit('admin_delete_failed', 'Database error during user delete', 'user', $userId);
            $_SESSION['flash_error'] = 'Delete failed due to a server error.';
        }
    }

    /**
     * Preferred over hard delete. Deactivates a user account.
     * Rules: cannot deactivate self. Cannot deactivate the last active admin.
     */
    public function deactivateUser(int $userId): void {
        $callerAdminId = (int)($_SESSION['user_id'] ?? 0);

        // Rule 1: Cannot deactivate yourself.
        if ($callerAdminId === $userId) {
            $this->audit('admin_delete_denied', 'Admin attempted self-deactivation', 'user', $userId);
            $_SESSION['flash_error'] = 'Action Denied: You cannot deactivate your own account.';
            return;
        }

        $auditTransaction=false;
        try {
            $this->db->beginTransaction();
            $auditTransaction=true;
            $stmt = $this->db->prepare("SELECT role, username, active FROM users WHERE id = :id LIMIT 1 FOR UPDATE");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->db->rollBack();
                $this->audit('admin_delete_failed', 'Deactivation target user not found', 'user', $userId);
                $_SESSION['flash_error'] = 'User not found.';
                return;
            }

            // Rule 2: Prevent deactivating the last active admin.
            if ($user['role'] === 'admin') {
                $cntStmt = $this->db->prepare(
                    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id != :id"
                );
                $cntStmt->execute(['id' => $userId]);
                $otherActiveAdmins = (int)$cntStmt->fetchColumn();

                if ($otherActiveAdmins === 0) {
                    $this->db->rollBack();
                    $this->audit(
                        'admin_delete_denied',
                        'Attempted to deactivate the last active admin account',
                        'user',
                        $userId
                    );
                    $_SESSION['flash_error'] = 'Action Denied: Cannot deactivate the last active administrator.';
                    return;
                }
            }

            if((int)$user['active']===0){$this->db->commit();$_SESSION['flash_success']='This account is already inactive.';return;}
            $stmt = $this->db->prepare("UPDATE users SET active = 0 WHERE id = :id");
            $stmt->execute(['id' => $userId]);

            $this->auditSvc->recordAudit($callerAdminId,'admin_user_deactivated','Account status: active -> inactive.','user',$userId);
            $this->db->commit();
            $_SESSION['flash_success'] = "User ID {$userId} ({$user['username']}) has been deactivated.";
        } catch (\Throwable $e) {
            if($auditTransaction&&$this->db->inTransaction())$this->db->rollBack();
            error_log('[AdminController] Account deactivation unavailable: ' . get_class($e));
            $this->audit('admin_delete_failed', 'Database error during user deactivation', 'user', $userId);
            $_SESSION['flash_error'] = 'Deactivation failed due to a server error.';
        }
    }

    public function getFeedbackStats(): array
    {
        return FeedbackHandler::getSystemFeedbackStats();
    }
}

