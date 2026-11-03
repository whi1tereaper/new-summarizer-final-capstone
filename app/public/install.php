<?php
/**
 * DEPENDED Web Deployment Installer
 * For HestiaCP / cPanel environments without SSH root terminal access.
 */
declare(strict_types=1);

$lockFile = dirname(__DIR__, 2) . '/storage/installed.lock';
if (file_exists($lockFile)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center;"><h2>DEPENDED is already installed.</h2><p>For security, delete <code>storage/installed.lock</code> if you need to re-run the installer.</p><a href="index.php">Go to Website &rarr;</a></body></html>');
}

$error = '';
$success = false;
$logs = [];

$defaultDbHost = '127.0.0.1';
$defaultDbName = 'article_rizer_depended_db';
$defaultDbUser = 'article_rizer_depended_user';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? 'article_rizer_depended_db');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    try {
        if ($dbUser === '') {
            throw new Exception('Database username cannot be blank.');
        }

        $logs[] = "Testing connection to {$dbHost}:{$dbPort}...";
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);
        $logs[] = "Successfully connected to database '{$dbName}'.";

        $root = dirname(__DIR__, 2);

        // 1. Write / update .env
        $envPath = $root . '/.env';
        $envExample = $root . '/.env.example';
        $envContent = file_exists($envExample) ? file_get_contents($envExample) : '';
        
        $envContent = preg_replace('/^DB_HOST=.*/m', "DB_HOST={$dbHost}", $envContent);
        $envContent = preg_replace('/^DB_PORT=.*/m', "DB_PORT={$dbPort}", $envContent);
        $envContent = preg_replace('/^DB_NAME=.*/m', "DB_NAME={$dbName}", $envContent);
        $envContent = preg_replace('/^DB_USERNAME=.*/m', "DB_USERNAME={$dbUser}", $envContent);
        $envContent = preg_replace('/^DB_PASSWORD=.*/m', 'DB_PASSWORD="' . addcslashes($dbPass, '"\\$') . '"', $envContent);
        $envContent = preg_replace('/^APP_ENV=.*/m', 'APP_ENV=production', $envContent);
        $envContent = preg_replace('/^APP_DEBUG=.*/m', 'APP_DEBUG=false', $envContent);
        $envContent = preg_replace('/^SUMMARIZER_ENGINE_VERSION=.*/m', 'SUMMARIZER_ENGINE_VERSION=legacy', $envContent);
        $envContent = preg_replace('/^LOCAL_PYTHON_BIN=.*/m', 'LOCAL_PYTHON_BIN="' . $root . '/.venv/bin/python"', $envContent);

        file_put_contents($envPath, $envContent);
        @chmod($envPath, 0600);
        $logs[] = "Generated production .env file (chmod 600).";

        // 2. Import Base Schema
        $schemaPath = $root . '/database/schema.sql';
        if (file_exists($schemaPath)) {
            $sql = file_get_contents($schemaPath);
            $cleanSql = preg_replace('/CREATE DATABASE IF NOT EXISTS\s+`?[a-zA-Z0-9_-]+`?;|USE\s+`?[a-zA-Z0-9_-]+`?;/i', '', $sql);
            $pdo->exec((string)$cleanSql);
            $logs[] = "Base schema imported successfully.";
        }

        // 3. Import Analytics Schema
        $analyticsPath = $root . '/database/analytics_schema.sql';
        if (file_exists($analyticsPath)) {
            $sql = file_get_contents($analyticsPath);
            $pdo->exec((string)$sql);
            $logs[] = "Analytics schema imported successfully.";
        }

        // 4. Apply Migrations
        $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL UNIQUE,
            `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $applied = $pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
        $appliedMap = array_fill_keys($applied, true);

        $migrationFiles = glob($root . '/database/migrations/*.sql');
        sort($migrationFiles, SORT_NATURAL);

        $newCount = 0;
        foreach ($migrationFiles as $filePath) {
            $filename = basename($filePath);
            if (isset($appliedMap[$filename])) {
                continue;
            }

            $sql = file_get_contents($filePath);
            if ($sql !== false && trim($sql) !== '') {
                $pdo->exec($sql);
            }
            $stmt = $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
            $stmt->execute([$filename]);
            $newCount++;
        }
        $logs[] = "Applied {$newCount} database migrations (up to date).";

        // 5. Ensure Storage Directories
        $storageDirs = ['storage/uploads', 'storage/audio', 'storage/logs', 'storage/tmp'];
        foreach ($storageDirs as $dir) {
            $target = $root . '/' . $dir;
            if (!is_dir($target)) {
                @mkdir($target, 0770, true);
            }
            @chmod($target, 0770);
        }
        $logs[] = "Runtime storage directories initialized with chmod 770.";

        // 6. Setup Python venv if exec / proc_open is available
        if (function_exists('exec')) {
            $pythonCheck = [];
            $pyCode = 0;
            @exec('python3 --version 2>&1', $pythonCheck, $pyCode);
            if ($pyCode === 0) {
                $venvPath = $root . '/.venv';
                if (!is_dir($venvPath)) {
                    @exec("python3 -m venv {$venvPath} 2>&1");
                    $logs[] = "Created Python 3 virtual environment (.venv).";
                }
                $reqFile = $root . '/python-engine/requirements-render.txt';
                if (file_exists($reqFile) && is_file($venvPath . '/bin/python')) {
                    @exec("{$venvPath}/bin/python -m pip install -r {$reqFile} --quiet 2>&1");
                    $logs[] = "Installed production Python dependencies.";
                }
            } else {
                $logs[] = "Note: python3 command not found; configure Python venv manually if summarization needs it.";
            }
        }

        // Lock file
        file_put_contents($lockFile, "Installed on " . date('c'));
        $logs[] = "Created storage/installed.lock.";
        $success = true;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DEPENDED — Web Setup &amp; Deployment</title>
    <style>
        :root { --primary: #7f00ff; --bg: #f5f4f0; --surface: #ffffff; --border: #d9d8d4; --text: #16171c; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); padding: 40px 20px; margin: 0; }
        .card { max-width: 600px; margin: 0 auto; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        h1 { margin-top: 0; font-size: 24px; color: var(--primary); }
        p { color: #555; font-size: 14px; line-height: 1.5; }
        label { display: block; font-size: 13px; font-weight: 600; margin-top: 16px; margin-bottom: 6px; }
        input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(127,0,255,0.15); }
        button { width: 100%; margin-top: 24px; padding: 12px; background: var(--primary); color: #fff; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; }
        button:hover { background: #6800d4; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; padding: 12px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 16px; border-radius: 6px; font-size: 14px; }
        ul.logs { background: #16171c; color: #8bc34a; font-family: monospace; font-size: 13px; padding: 16px 20px; border-radius: 6px; list-style: none; margin: 20px 0; max-height: 240px; overflow-y: auto; }
        ul.logs li { margin-bottom: 6px; }
        .btn-link { display: inline-block; margin-top: 16px; text-decoration: none; background: var(--primary); color: #fff; padding: 10px 20px; border-radius: 6px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1>DEPENDED Web Setup</h1>
        <p>This automated installer connects to your MariaDB database, imports all schemas and migrations, and initializes the storage directories.</p>

        <?php if ($error !== ''): ?>
            <div class="alert-error"><strong>Setup Error:</strong> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success">
                <strong>Deployment Successful!</strong>
                <p>Your database and storage paths are configured and ready for production.</p>
            </div>
            <ul class="logs">
                <?php foreach ($logs as $log): ?>
                    <li>&check; <?= htmlspecialchars($log) ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="index.php" class="btn-link">Open DEPENDED Website &rarr;</a>
        <?php else: ?>
            <form method="POST">
                <label>Database Host</label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? $defaultDbHost) ?>" required>

                <label>Database Port</label>
                <input type="text" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" required>

                <label>Database Name</label>
                <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? $defaultDbName) ?>" required>

                <label>Database User</label>
                <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? $defaultDbUser) ?>" placeholder="e.g. article_rizer_depended_user" required>

                <label>Database Password</label>
                <input type="password" name="db_pass" placeholder="Enter password for article_rizer_depended_db" required autofocus>

                <button type="submit">Run Automated Database Setup</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

