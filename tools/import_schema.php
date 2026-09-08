<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden.\n";
    exit(1);
}

require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Support/RuntimePaths.php';


$config = [
    'host'     => config('database.host', '127.0.0.1'),
    'port'     => config('database.port', '3306'),
    'database' => config('database.name', 'ai_summarizer'),
    'username' => config('database.username', 'root'),
    'password' => config('database.password', ''),
];

$schemaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
if (!is_file($schemaPath)) {
    fwrite(STDERR, "Schema file not found: {$schemaPath}\n");
    exit(1);
}

$schemaSql = file_get_contents($schemaPath);
if ($schemaSql === false || trim($schemaSql) === '') {
    fwrite(STDERR, "Schema file is empty or unreadable.\n");
    exit(1);
}

try {
    $serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']);
    $serverPdo = new PDO($serverDsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $serverPdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '``', $config['database'])
    ));

    $dbDsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);
    $databasePdo = new PDO($dbDsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);

    $normalizedSchema = preg_replace(
        '/CREATE DATABASE IF NOT EXISTS\s+`?ai_summarizer`?;|USE\s+`?ai_summarizer`?;/i',
        '',
        $schemaSql
    );
    if (!is_string($normalizedSchema)) {
        throw new RuntimeException('Failed to prepare schema SQL.');
    }

    $databasePdo->exec($normalizedSchema);
    \App\Src\Support\RuntimePaths::ensureRequiredDirectories();

    fwrite(STDOUT, "Schema import completed for database '{$config['database']}'.\n");
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, "Schema import failed: " . $throwable->getMessage() . "\n");
    exit(1);
}
