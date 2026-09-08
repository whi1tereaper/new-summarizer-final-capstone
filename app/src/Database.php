<?php
namespace App\Src;

use PDO;
use PDOException;

class Database {
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct() {
        // Read connection details from shared config so every service talks to the same database.
        $host = config('database.host', '127.0.0.1');
        $db   = config('database.name', 'ai_summarizer');
        $user = config('database.username', 'root');
        $pass = config('database.password', '');
        $port = config('database.port', '3306');
        $charset = config('database.charset', 'utf8mb4');

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
        $options = [
            // Fail loudly and return associative rows so database errors do not get hidden downstream.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Log the real driver failure, but keep the browser message generic.
            error_log('[Database Connection Error] ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed. Please try again later.');
        }
    }

    // Keep one shared PDO instance so request handlers do not recreate connections unnecessarily.
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    // Block alternate construction paths that would break the singleton contract.
    private function __clone() {}
    public function __wakeup() {}
}
