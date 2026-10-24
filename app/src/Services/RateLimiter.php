<?php
namespace App\Src\Services;

use App\Src\Database;
use PDO;

// keeps login/reset limits in MySQL so they work across PHP requests
class RateLimiter
{
    private PDO $db;

    // max attempts and window length per action
    private const LIMITS = [
        'login'         => ['max' => 5,  'window' => 900],   // 5 attempts per 15 min
        'register'      => ['max' => 5,  'window' => 900],   // 5 attempts per 15 min
        'reset_request' => ['max' => 1,  'window' => 60],    // 1 per 1 min
        'otp_verify'    => ['max' => 5,  'window' => 900],   // 5 per 15 min
        'admin_code_request_user' => ['max' => 3, 'window' => 900],
        'admin_code_request_ip'   => ['max' => 3, 'window' => 900],
        'admin_code_verify_user'  => ['max' => 5, 'window' => 900],
        'admin_code_verify_ip'    => ['max' => 5, 'window' => 900],
        'account_deactivation' => ['max' => 5, 'window' => 900],
        'landing_beacon' => ['max' => 30, 'window' => 60],   // telemetry batches per minute
        'nutshell'      => ['max' => 10, 'window' => 3600],  // 10 new generations per hour
        'feedback'      => ['max' => 10, 'window' => 60],    // 10 feedback submissions per minute
        'student_submit' => ['max' => 5, 'window' => 900],
        'student_review' => ['max' => 20, 'window' => 60],
        'registry_import' => ['max' => 10, 'window' => 900],
        'registry_status' => ['max' => 20, 'window' => 60],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // true means this action should be blocked for now
    public function isLimited(string $action, string $identifier): bool
    {
        $config = $this->getActionConfig($action);
        if (!$config) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT attempts, window_start FROM rate_limits
             WHERE action_type = ? AND identifier = ?"
        );
        $stmt->execute([$action, $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $windowStart = strtotime($row['window_start']);
        $elapsed = time() - $windowStart;

        // old window; the next attempt will reset the counter
        if ($elapsed >= $config['window']) {
            return false;
        }

        return $row['attempts'] >= $config['max'];
    }

    // record one attempt without race issues between close requests
    public function recordAttempt(string $action, string $identifier): void
    {
        $config = $this->getActionConfig($action);
        if (!$config) {
            return;
        }

        // insert first attempt or bump the current window
        $stmt = $this->db->prepare("
                INSERT INTO rate_limits (action_type, identifier, attempts, window_start)
                VALUES (?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    attempts = IF(
                        TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?,
                        1,
                        attempts + 1
                    ),
                    window_start = IF(
                        TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?,
                        NOW(),
                        window_start
                    )
            ");
        $stmt->execute([$action, $identifier, $config['window'], $config['window']]);

        // clean stale rows once in a while
        if (random_int(1, 100) === 1) {
            $this->purgeExpired();
        }
    }

    // clear attempts after a successful action
    public function resetAttempts(string $action, string $identifier): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM rate_limits WHERE action_type = ? AND identifier = ?"
        );
        $stmt->execute([$action, $identifier]);
    }

    // seconds left before this action can be tried again
    public function getRetryAfter(string $action, string $identifier): int
    {
        $config = $this->getActionConfig($action);
        if (!$config) {
            return 0;
        }

        $stmt = $this->db->prepare(
            "SELECT window_start FROM rate_limits
             WHERE action_type = ? AND identifier = ?"
        );
        $stmt->execute([$action, $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return 0;
        }

        $windowEnd = strtotime($row['window_start']) + $config['window'];
        return max(0, $windowEnd - time());
    }

    private function getActionConfig(string $action): ?array
    {
        return self::LIMITS[$action] ?? null;
    }

    // remove rows older than the longest active window
    public function purgeExpired(): int
    {
        $maxWindow = max(array_column(self::LIMITS, 'window'));
        $cutoff = date('Y-m-d H:i:s', time() - $maxWindow);
        $stmt = $this->db->prepare(
            "DELETE FROM rate_limits WHERE window_start < ?"
        );
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    // Forwarded addresses are trusted only when REMOTE_ADDR is an explicitly trusted proxy.
    public static function getClientIp(): string
    {
        $remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        $trustedProxies = function_exists('config')
            ? config('security.trusted_proxies', [])
            : [];
        if (!is_array($trustedProxies) || !in_array($remoteAddress, $trustedProxies, true)) {
            return $remoteAddress;
        }

        $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            $addresses = array_map('trim', explode(',', $forwardedFor));
            $addresses[] = $remoteAddress;

            foreach (array_reverse($addresses) as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                    return $remoteAddress;
                }

                if (!in_array($address, $trustedProxies, true)) {
                    return $address;
                }
            }

            return $remoteAddress;
        }

        $realAddress = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        return filter_var($realAddress, FILTER_VALIDATE_IP) !== false
            ? $realAddress
            : $remoteAddress;
    }
}
