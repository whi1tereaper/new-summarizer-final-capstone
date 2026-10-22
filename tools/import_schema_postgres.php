<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Support/Env.php';
require_once __DIR__ . '/../app/src/Support/RuntimePaths.php';
use App\Src\Support\Env;

Env::load();

$unpooledUrl = Env::string('DATABASE_URL_UNPOOLED');
if ($unpooledUrl === '') {
    $unpooledUrl = Env::string('DATABASE_URL');
}

if ($unpooledUrl === '') {
    fwrite(STDERR, "Error: DATABASE_URL_UNPOOLED or DATABASE_URL must be set in .env\n");
    exit(1);
}

$parsed = parse_url($unpooledUrl);
$host = $parsed['host'] ?? '127.0.0.1';
$port = $parsed['port'] ?? 5432;
$dbname = ltrim($parsed['path'] ?? '', '/');
$user = $parsed['user'] ?? '';
$pass = $parsed['pass'] ?? '';

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    fwrite(STDOUT, "Connected to Neon PostgreSQL (direct endpoint: {$host})\n");

    $schemaFile = dirname(__DIR__) . '/database/schema.postgres.sql';
    if (!is_file($schemaFile)) {
        throw new RuntimeException("Schema file not found: {$schemaFile}");
    }

    $sql = file_get_contents($schemaFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Schema file is empty: {$schemaFile}");
    }

    $pdo->exec($sql);
    fwrite(STDOUT, "  PASS: PostgreSQL base application schema imported successfully.\n");

    // Ensure storage directories exist
    \App\Src\Support\RuntimePaths::ensureRequiredDirectories();

    fwrite(STDOUT, "Neon PostgreSQL schema setup completed successfully.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Schema import failed: " . $e->getMessage() . "\n");
    exit(1);
}
