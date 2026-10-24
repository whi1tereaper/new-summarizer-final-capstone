<?php
namespace App\Src\Support;

/** Private allow-listed telemetry. Never serialize source content or exception prose. */
final class ProcessingDiagnostics
{
    private static array $context = [];
    private static float $started = 0;

    public static function begin(?string $correlation = null): void
    {
        self::$started = microtime(true);
        self::$context = ['correlation_id' => is_string($correlation) && preg_match('/\A[a-f0-9]{24,32}\z/', $correlation)
            ? $correlation : bin2hex(random_bytes(12)), 'stage' => 'request_bootstrap',
            'module' => null, 'function' => null, 'engine' => null, 'python_exit_status' => null];
    }

    public static function stage(string $stage, string $module, string $function): void
    {
        if (!self::$context) self::begin();
        foreach (['stage' => $stage, 'module' => $module, 'function' => $function] as $key => $value) {
            self::$context[$key] = preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\:.-]{0,159}\z/', $value) ? $value : 'unclassified';
        }
    }

    public static function engine(?string $engine): void
    {
        if (!self::$context) self::begin();
        $engine = match ($engine) { 'summarizer_core' => 'legacy', 'light_engine' => 'v2', default => $engine };
        self::$context['engine'] = in_array($engine, ['legacy', 'v2', 'recipe_article', 'article_pending'], true) ? $engine : null;
    }

    public static function workerExit(?int $status, string $stderr = ''): void
    {
        if (!self::$context) self::begin();
        self::$context['python_exit_status'] = $status;
        if (preg_match('/\b(ModuleNotFoundError|ImportError|MemoryError|SyntaxError|TimeoutError)\b/', $stderr, $match)) {
            self::$context['python_exception_class'] = $match[1];
        }
        preg_match_all('/^LIGHT_STAGE_(START|END) ([a-z_]{1,48})\r?$/m', $stderr, $events, PREG_SET_ORDER);
        $active = [];
        foreach ($events as $event) {
            if ($event[1] === 'START') $active[] = $event[2];
            elseif ($active && end($active) === $event[2]) array_pop($active);
        }
        if ($active) self::$context['python_stage'] = end($active);
    }

    public static function failure(ProcessingError $failure): void
    {
        if (!self::$context) self::begin();
        $cause = $failure;
        while ($cause->getPrevious() !== null) $cause = $cause->getPrevious();
        $record = self::$context + ['timestamp_utc' => gmdate('c'), 'event' => 'request_failed',
            'reference' => $failure->reference, 'code' => $failure->errorCode,
            'exception_class' => get_class($cause), 'duration_ms' => round(1000 * (microtime(true) - self::$started), 3),
            'diagnostic_message' => 'Failure recorded at the identified processing stage.'];
        if ($cause instanceof \PDOException && preg_match('/\A[A-Z0-9]{5}\z/', (string)$cause->getCode())) {
            $record['sqlstate'] = (string)$cause->getCode();
        }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        try {
            require_once __DIR__ . '/config.php';
            $directory = config('storage.logs');
            $path = $directory . DIRECTORY_SEPARATOR . 'request-diagnostics.jsonl';
            if (is_dir($directory) && @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) return;
        } catch (\Throwable $ignored) {
            // Configuration failures still have a matching opaque reference in
            // the normal PHP error log; logging cannot replace the real error.
        }
        error_log('[LIGHT diagnostic] ' . trim($line));
    }
}
