<?php
namespace App\Src\Services;

use App\Src\Database;
use PDO;

class RememberMeService
{
    private const COOKIE_NAME = 'remember_token';
    private const COOKIE_DAYS = 30;

    /**
     * Ensure the user_tokens table exists.
     */
    public static function ensureSchema(): void
    {
        $db = Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                selector VARCHAR(255) NOT NULL,
                hashed_validator VARCHAR(255) NOT NULL,
                expires DATETIME NOT NULL,
                UNIQUE KEY selector_idx (selector),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * Create a new remember me token, save to DB, and set the cookie.
     */
    public static function createCookieForUser(int $userId): void
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        
        $token = $selector . ':' . $validator;
        $hashedValidator = hash('sha256', $validator);
        
        $expires = time() + (self::COOKIE_DAYS * 24 * 60 * 60);
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("INSERT INTO user_tokens (user_id, selector, hashed_validator, expires) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $selector, $hashedValidator, date('Y-m-d H:i:s', $expires)]);
        
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        
        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Clear the token from DB and unset the cookie.
     */
    public static function clearCookie(): void
    {
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($cookie) {
            $parts = explode(':', $cookie);
            if (count($parts) === 2) {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("DELETE FROM user_tokens WHERE selector = ?");
                $stmt->execute([$parts[0]]);
            }
        }

        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Validate the cookie and automatically log the user in if valid.
     */
    public static function loginFromCookie(): void
    {
        if (isset($_SESSION['user_id']) || empty($_COOKIE[self::COOKIE_NAME])) {
            return;
        }

        $cookie = $_COOKIE[self::COOKIE_NAME];
        $parts = explode(':', $cookie);
        if (count($parts) !== 2) {
            self::clearCookie();
            return;
        }

        [$selector, $validator] = $parts;
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT t.id, t.user_id, t.hashed_validator, t.expires, u.username, u.role, u.terms_accepted 
            FROM user_tokens t 
            JOIN users u ON t.user_id = u.id 
            WHERE t.selector = ? AND u.active = 1
        ");
        $stmt->execute([$selector]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            self::clearCookie();
            return;
        }

        if (strtotime($record['expires']) < time()) {
            $deleteStmt = $db->prepare("DELETE FROM user_tokens WHERE id = ?");
            $deleteStmt->execute([$record['id']]);
            self::clearCookie();
            return;
        }

        if (hash_equals($record['hashed_validator'], hash('sha256', $validator))) {
            // Token is valid, log them in
            session_regenerate_id(true);
            $_SESSION['user_id'] = $record['user_id'];
            $_SESSION['role'] = $record['role'];
            $_SESSION['username'] = $record['username'];
            
            // Set terms state
            $userForTerms = [
                'id' => $record['user_id'],
                'terms_accepted' => $record['terms_accepted']
            ];
            $termsAcceptanceService = new TermsAcceptanceService();
            $termsAcceptanceService->storeTermsStateInSession($userForTerms);

            // Re-issue a fresh token for security (rotating tokens)
            $deleteStmt = $db->prepare("DELETE FROM user_tokens WHERE id = ?");
            $deleteStmt->execute([$record['id']]);
            self::createCookieForUser($record['user_id']);
        } else {
            // Validator didn't match, possible token theft! Clear it.
            self::clearCookie();
        }
    }
}
