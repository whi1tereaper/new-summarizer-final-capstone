<?php
declare(strict_types=1);
namespace App\Src\Services;

require_once __DIR__ . '/AdminSecurityService.php';
require_once __DIR__ . '/../Utils/validation.php';

/** Shared guards for server-rendered evaluation pages; never derive identity from a form. */
final class EvaluationAccess
{
    public static function requireAdmin(): int
    {
        if (!AdminSecurityService::isVerifiedAdmin()) {
            if (PHP_SAPI !== 'cli') http_response_code(403);
            throw new \RuntimeException('Administrator access is required.', 403);
        }
        return self::requireEvaluator();
    }

    public static function requireEvaluator(): int
    {
        $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            if (PHP_SAPI !== 'cli') http_response_code(403);
            throw new \RuntimeException('Sign in to access assigned evaluations.', 403);
        }
        // Revoked/disabled accounts must not retain evaluation access through a stale session.
        $db = \App\Src\Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT active, role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user || !(bool)$user['active'] || (AdminSecurityService::isVerifiedAdmin() && $user['role'] !== 'admin')) {
            if (PHP_SAPI !== 'cli') http_response_code(403);
            throw new \RuntimeException('Evaluation access is unavailable.', 403);
        }
        return (int)$id;
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            if (PHP_SAPI !== 'cli') http_response_code(405);
            throw new \RuntimeException('A form submission is required.', 405);
        }
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !verifyCsrfToken($token)) {
            if (PHP_SAPI !== 'cli') http_response_code(403);
            throw new \RuntimeException('Invalid security token. Reload the page and try again.', 403);
        }
    }
}
