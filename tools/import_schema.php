<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
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

$analyticsSchemaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'analytics_schema.sql';

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

    // Step 1: Import base application schema
    $schemaSql = file_get_contents($schemaPath);
    if ($schemaSql === false || trim($schemaSql) === '') {
        throw new RuntimeException("Base schema file is empty or unreadable: {$schemaPath}");
    }
    $normalizedSchema = preg_replace(
        '/CREATE DATABASE IF NOT EXISTS\s+`?[a-zA-Z0-9_-]+`?;|USE\s+`?[a-zA-Z0-9_-]+`?;/i',
        '',
        $schemaSql
    );
    $databasePdo->exec((string)$normalizedSchema);
    fwrite(STDOUT, "  PASS: Base application schema imported successfully.\n");

    // Step 2: Import base analytics schema if available
    if (is_file($analyticsSchemaPath)) {
        $analyticsSql = file_get_contents($analyticsSchemaPath);
        if ($analyticsSql !== false && trim($analyticsSql) !== '') {
            $databasePdo->exec($analyticsSql);
            fwrite(STDOUT, "  PASS: Base analytics schema imported successfully.\n");
        }
    }

    // Step 3: Run migrations runner to bring database to latest version
    fwrite(STDOUT, "  Applying sequential migrations...\n");
    $migratePath = __DIR__ . DIRECTORY_SEPARATOR . 'migrate.php';
    if (is_file($migratePath)) {
        $cmd = PHP_BINARY . ' ' . escapeshellarg($migratePath);
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        foreach ($output as $line) {
            fwrite(STDOUT, "    " . $line . "\n");
        }
        if ($exitCode !== 0) {
            throw new RuntimeException("Migration runner failed with exit code {$exitCode}");
        }
    }

    // Step 4: Ensure storage directories exist
    \App\Src\Support\RuntimePaths::ensureRequiredDirectories();

    fwrite(STDOUT, "Database setup completed successfully for '{$config['database']}'.\n");
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, "Database setup failed: " . $throwable->getMessage() . "\n");
    exit(1);
}
