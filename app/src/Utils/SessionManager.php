<?php
namespace App\Src\Utils;

class SessionManager
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $sessionName = 'LIGHT_SESSID';
        if (function_exists('config')) {
            $configured = config('session.name');
            if (is_string($configured) && $configured !== '') {
                $sessionName = $configured;
            }
        }
        session_name($sessionName);

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '86400');
        ini_set('session.cookie_lifetime', '0');

        if (self::isHttps()) {
            ini_set('session.cookie_secure', '1');
        }

        session_start();
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https') {
            return true;
        }

        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        $cfVisitor = (string)($_SERVER['HTTP_CF_VISITOR'] ?? '');
        return str_contains($cfVisitor, '"scheme":"https"');
    }
}
