<?php
namespace App\Src;

use PDO;
use PDOException;

class Database {
    private static ?self $instance = null;
    private PDO $pdo;
    private string $driver = 'mysql';

    private function __construct() {
        // Prefer Neon PostgreSQL connection string (DATABASE_URL)
        $dbUrl = config('database.url', '');
        $useNeon = $dbUrl !== '' && (str_starts_with($dbUrl, 'postgresql://') || str_starts_with($dbUrl, 'postgres://'));

        if ($useNeon) {
            if (!extension_loaded('pdo_pgsql')) {
                error_log('[Database Warning] DATABASE_URL is configured for Neon PostgreSQL, but the pdo_pgsql extension is not loaded in this PHP environment (' . PHP_SAPI . '). Please restart Apache in XAMPP Control Panel. Falling back to MySQL in the meantime.');
            } else {
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];

                try {
                    $this->pdo = self::createPgsqlPdo($dbUrl, $options);
                    $this->driver = 'pgsql';
                    return;
                } catch (PDOException $e) {
                    error_log('[Database Connection Error] Failed connecting to Neon PostgreSQL: ' . $e->getMessage() . '. Falling back to MySQL if available.');
                }
            }
        }

        // Fallback to MySQL if DATABASE_URL is not configured or pdo_pgsql is unavailable
        $host = config('database.host', '127.0.0.1');
        $db   = config('database.name', 'ai_summarizer');
        $user = config('database.username', 'root');
        $pass = config('database.password', '');
        $port = config('database.port', '3306');
        $charset = config('database.charset', 'utf8mb4');

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
        $options = self::mysqlOptions();

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
            $this->driver = 'mysql';
        } catch (PDOException $e) {
            error_log('[Database Connection Error] MySQL connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed. Please try again later.');
        }
    }

    // Keep one shared PDO instance so request handlers do not recreate connections unnecessarily.
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->pdo;
    }

    public function getDriver(): string {
        return $this->driver;
    }

    public function isPostgres(): bool {
        return $this->driver === 'pgsql';
    }

    // Direct unpooled connection factory for migrations as recommended by Neon best practices
    public static function getDirectConnection(): PDO {
        $unpooledUrl = config('database.unpooled_url', '');
        if ($unpooledUrl === '') {
            $unpooledUrl = config('database.url', '');
        }

        if (extension_loaded('pdo_pgsql') && $unpooledUrl !== '' && (str_starts_with($unpooledUrl, 'postgresql://') || str_starts_with($unpooledUrl, 'postgres://'))) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            return self::createPgsqlPdo($unpooledUrl, $options);
        }

        return self::getInstance()->getConnection();
    }

    private static function createPgsqlPdo(string $dbUrl, array $options): PDO {
        $parsed = parse_url($dbUrl);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 5432;
        $db   = ltrim($parsed['path'] ?? '', '/');
        $user = $parsed['user'] ?? '';
        $pass = $parsed['pass'] ?? '';

        $baseDsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

        try {
            return new PDO($baseDsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Handle older libpq clients without SNI support (e.g. XAMPP Apache)
            if (str_contains($e->getMessage(), 'Endpoint ID is not specified')) {
                $endpoint = explode('.', $host)[0];
                $endpoint = preg_replace('/-pooler$/', '', $endpoint);
                $sniFallbackDsn = $baseDsn . ";options='endpoint={$endpoint}'";
                return new PDO($sniFallbackDsn, $user, $pass, $options);
            }
            throw $e;
        }
    }

    private static function mysqlOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (!config('database.ssl_enabled', false)) {
            return $options;
        }

        if (!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            throw new \RuntimeException('MySQL TLS verification is unavailable in this PHP runtime.');
        }

        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        $caPath = trim((string)config('database.ssl_ca', ''));
        if ($caPath !== '') {
            if (!is_file($caPath) || !is_readable($caPath)) {
                throw new \RuntimeException('Configured MySQL TLS CA file is missing or unreadable.');
            }

            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        }

        return $options;
    }

    // Block alternate construction paths that would break the singleton contract.
    private function __clone() {}
    public function __wakeup() {}
}
