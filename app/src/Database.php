<?php
declare(strict_types=1);
namespace App\Src;

use PDO;
use PDOException;

/** XAMPP-only MySQL/MariaDB connection. Cloud database URLs are rejected. */
final class Database
{
    private static ?self $instance=null;
    private PDO $pdo;
    private function __construct()
    {
        $driver=strtolower((string)config('database.driver','mysql'));
        if($driver!=='mysql') throw new \RuntimeException('This installation supports XAMPP MySQL/MariaDB only.');
        try { $this->pdo=self::connect(); }
        catch(PDOException $e){ error_log('[Database] MySQL connection failed: '.get_class($e).' code='.$e->getCode()); throw new \RuntimeException('Local MySQL is unavailable. Start XAMPP MySQL and check the configured database.',0,$e); }
    }
    public static function getInstance(): self { return self::$instance??=new self(); }
    public function getConnection(): PDO { return $this->pdo; }
    public function getDriver(): string { return 'mysql'; }
    public static function getConfiguredMySqlConnection(): PDO { return self::connect(); }
    private static function connect(): PDO
    {
        $host=(string)config('database.host','127.0.0.1');$port=(string)config('database.port','3306');$db=(string)config('database.name','ai_summarizer');$charset=(string)config('database.charset','utf8mb4');
        return new PDO("mysql:host={$host};port={$port};dbname={$db};charset={$charset}",(string)config('database.username','root'),(string)config('database.password',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    private function __clone(){}
    public function __wakeup(): void { throw new \LogicException('Database singleton cannot be unserialized.'); }
}
