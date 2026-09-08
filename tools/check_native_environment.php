<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden.\n";
    exit(1);
}

require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Support/RuntimePaths.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Services/LocalPythonBridge.php';

use App\Src\Database;
use App\Src\Services\LocalPythonBridge;
use App\Src\Support\RuntimePaths;

RuntimePaths::ensureRequiredDirectories();

$failures = 0;

function reportCheck(string $label, bool $passed, string $detail): void
{
    $status = $passed ? 'PASS' : 'FAIL';
    echo sprintf("[%s] %s: %s\n", $status, $label, $detail);
}

function checkCondition(string $label, bool $passed, string $detail, int &$failures): void
{
    reportCheck($label, $passed, $detail);
    if (!$passed) {
        $failures++;
    }
}

checkCondition('PHP version', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION, $failures);
checkCondition('Composer autoload', is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'), 'vendor/autoload.php', $failures);

$extensions = ['curl', 'dom', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql'];
foreach ($extensions as $extension) {
    checkCondition(
        "PHP extension {$extension}",
        extension_loaded($extension),
        extension_loaded($extension) ? 'loaded' : 'missing',
        $failures
    );
}

$procOpenAvailable = function_exists('proc_open')
    && !in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
checkCondition('proc_open', $procOpenAvailable, $procOpenAvailable ? 'enabled' : 'disabled', $failures);

$storageDirectories = [
    'storage root'    => RuntimePaths::storageRoot(),
    'storage/uploads' => RuntimePaths::uploadsDirectory(),
    'storage/audio'   => RuntimePaths::audioDirectory(),
    'storage/logs'    => RuntimePaths::logsDirectory(),
    'storage/tmp'     => RuntimePaths::temporaryDirectory(),
];
foreach ($storageDirectories as $label => $path) {
    $writable = is_dir($path) && is_writable($path);
    checkCondition($label, $writable, $path, $failures);
}

$configuredDbHost = config('database.host', '127.0.0.1');
$dockerOnlyDbHosts = ['db', 'mysql', 'ai_summarizer_db'];
$dbHostLooksNative = !in_array(strtolower($configuredDbHost), $dockerOnlyDbHosts, true);
checkCondition('DB_HOST', $dbHostLooksNative, $configuredDbHost, $failures);

try {
    $db = Database::getInstance()->getConnection();
    $databaseName = (string)($db->query('SELECT DATABASE()')->fetchColumn() ?: 'unknown');
    checkCondition('Database connection', true, $databaseName, $failures);

    $requiredTables = ['users', 'summaries', 'feedback', 'guest_sessions', 'password_resets', 'rate_limits'];
    foreach ($requiredTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($table));
        $exists = $stmt !== false && $stmt->fetchColumn() !== false;
        checkCondition("Table {$table}", $exists, $exists ? 'present' : 'missing', $failures);
    }
} catch (Throwable $throwable) {
    checkCondition('Database connection', false, $throwable->getMessage(), $failures);
}

$configuredPython = trim((string)config('python.bin', ''));
if ($configuredPython === '') {
    reportCheck('python.bin', true, 'not set; fallback detection will be used');
} else {
    $isExplicitPath = str_contains($configuredPython, DIRECTORY_SEPARATOR)
        || str_contains($configuredPython, '/')
        || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configuredPython) === 1;
    $looksValid = !$isExplicitPath || is_file($configuredPython);
    checkCondition('python.bin', $looksValid, $configuredPython, $failures);
}

$configuredPiper = trim((string)config('tts.piper_bin', ''));
if ($configuredPiper === '') {
    reportCheck('piper_bin', true, 'not set; configure this before using TTS');
} else {
    $piperLooksValid = is_file($configuredPiper);
    checkCondition('piper_bin', $piperLooksValid, $configuredPiper, $failures);
}

try {
    $bridge = new LocalPythonBridge();
    $selfTest = $bridge->runSelfTest(20);
    $pythonVersion = (string)($selfTest['python_version'] ?? 'unknown');
    checkCondition('Python worker self-test', true, "python {$pythonVersion}", $failures);
} catch (Throwable $throwable) {
    checkCondition('Python worker self-test', false, $throwable->getMessage(), $failures);
}

exit($failures > 0 ? 1 : 0);
