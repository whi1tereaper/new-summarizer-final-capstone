<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Support/ProcessingError.php';
require_once __DIR__ . '/../Support/config.php';

use App\Src\Support\ProcessingError;
use App\Src\Support\ProcessingDiagnostics;

final class PythonWorkerLaunchException extends \RuntimeException
{
}

class LocalPythonBridge
{
    private string $scriptPath;
    private string $lightEngineScriptPath;
    private string $workingDirectory;
    private array $pythonCandidates;
    private ?string $configuredPythonBinary;

    public function __construct(?string $pythonBinary = null)
    {
        // Resolve the worker script and candidate Python binaries once for the current PHP request.
        $this->scriptPath = config('python.script');
        $this->workingDirectory = dirname($this->scriptPath);
        $this->lightEngineScriptPath = $this->workingDirectory . DIRECTORY_SEPARATOR . 'light_engine_cli.py';
        $this->configuredPythonBinary = $this->resolveConfiguredPythonBinary($pythonBinary);
        $this->pythonCandidates = $this->buildPythonCandidates(dirname($this->scriptPath, 2), $this->configuredPythonBinary);
    }

    public function summarize(array $payload, ?int $timeoutSeconds = null): array
    {
        // A server-owned per-request flag reaches both daemon and subprocess.
        $payload['automatic_article_routing'] = (bool)config('summarizer.automatic_article_routing', false);
        return $this->run('summarize', $payload, $this->configuredSummaryTimeout($timeoutSeconds));
    }

    public function summarizeV2(array $payload, ?int $timeoutSeconds = null): array
    {
        $timeoutSeconds = $this->configuredSummaryTimeout($timeoutSeconds);
        $payload['schema_version'] = 1;
        $slot = $this->acquireLightEngineSlot($timeoutSeconds);
        try {
            return $this->run(
                'summarize',
                $payload,
                $timeoutSeconds,
                $this->lightEngineScriptPath,
                false
            );
        } finally {
            flock($slot, LOCK_UN);
            fclose($slot);
        }
    }

    public function generateSummary(string $sourceText, string $profile, string $length, int $timeoutSeconds = 60): array
    {
        return $this->run('generate-summary', [
            'text' => $sourceText,
            'profile' => $profile,
            'length' => $length,
        ], $timeoutSeconds);
    }

    public function nutshell(array $payload, int $timeoutSeconds = 60): array
    {
        return $this->run('nutshell', $payload, $timeoutSeconds);
    }

    public function extractPdfText(string $filePath, int $timeoutSeconds = 60): string
    {
        $result = $this->run('extract-pdf', [
            'file_path' => $filePath,
        ], $timeoutSeconds);

        $text = isset($result['raw_text']) && is_string($result['raw_text'])
            ? trim($result['raw_text'])
            : '';
        if ($text === '') {
            throw new ProcessingError('FILE_TEXT_UNAVAILABLE');
        }

        return $text;
    }

    public function translate(string $text, string $targetLang = 'tl', int $timeoutSeconds = 60): array
    {
        return $this->run('translate', [
            'text' => $text,
            'target_lang' => $targetLang,
        ], $timeoutSeconds);
    }

    public function generateTts(string $text, string $language = 'en', int $timeoutSeconds = 600): array
    {
        return $this->run('tts-generate', [
            'text' => $text,
            'language' => $language,
        ], $timeoutSeconds);
    }

    public function qa(array $payload, int $timeoutSeconds = 30): array
    {
        return $this->run('qa', $payload, $timeoutSeconds);
    }

    public function runSelfTest(int $timeoutSeconds = 15): array
    {
        return $this->run('self-test', ['probe' => 'native-environment'], $timeoutSeconds);
    }

    public function cancelJob(string $jobId): bool
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $jobId) !== 1) return false;
        try {
            // Cancellation is best effort against the existing authenticated
            // supervisor. It must never start or fall back to a CLI worker.
            $response = $this->tryExecuteViaDaemon('cancel', ['job_id' => $jobId], 2);
            return ($response['cancelled'] ?? null) === true;
        } catch (\Throwable $error) {
            ProcessingError::fromThrowable($error, 'WORKER_UNAVAILABLE')->log();
            return false;
        }
    }

    private function run(
        string $action,
        array $payload,
        int $timeoutSeconds,
        ?string $scriptPath = null,
        bool $useDaemon = true
    ): array
    {
        ProcessingDiagnostics::stage('python_bridge', self::class, 'run');
        ProcessingDiagnostics::engine(!empty($payload['automatic_article_routing']) ? 'article_pending'
            : config('summarizer.engine_version', 'legacy'));
        try {
            return $this->runWorker($action, $payload, $timeoutSeconds, $scriptPath, $useDaemon);
        } catch (\Throwable $exception) {
            // Transport/setup failures remain safe and typed. Worker-reported
            // source and NLP failures retain their own classification.
            throw ProcessingError::fromThrowable($exception, 'WORKER_UNAVAILABLE');
        }
    }

    private function runWorker(
        string $action,
        array $payload,
        int $timeoutSeconds,
        ?string $scriptPath,
        bool $useDaemon
    ): array
    {
        $workerScript = $scriptPath ?? $this->scriptPath;
        // Attempt fast loopback execution if the persistent NLP daemon is active
        $daemonResponse = $useDaemon
            ? $this->tryExecuteViaDaemon($action, $payload, $timeoutSeconds)
            : null;
        if ($daemonResponse !== null) {
            return $daemonResponse;
        }

        // Fail fast when the PHP runtime itself cannot spawn the local worker.
        if (!$this->procOpenIsAvailable()) {
            throw new \RuntimeException('PHP proc_open() is disabled. Enable proc_open to use summarization, translation, and text-to-speech.');
        }

        if (!is_file($workerScript)) {
            throw new \RuntimeException('Local Python worker script was not found.');
        }

        if (!is_readable($workerScript)) {
            throw new \RuntimeException('Local Python worker script is not readable.');
        }

        $lastError = null;
        $candidateAttempted = false;

        // Try configured and local Python binaries in order so the app can recover from partial setups.
        foreach ($this->pythonCandidates as $pythonCommand) {
            $candidateLabel = implode(' ', $pythonCommand);
            if ($this->commandStartsWithExplicitPath($pythonCommand) && !is_file($pythonCommand[0])) {
                continue;
            }

            try {
                $candidateAttempted = true;
                return $this->execute(array_merge($pythonCommand, [$workerScript, $action]), $payload, $timeoutSeconds);
            } catch (PythonWorkerLaunchException $exception) {
                $lastError = new \RuntimeException(
                    sprintf('%s [python: %s]', $exception->getMessage(), $candidateLabel),
                    0,
                    $exception
                );
            }
        }

        if (!$candidateAttempted) {
            if ($this->configuredPythonBinary !== null && $this->configuredPythonBinary !== '') {
                throw new \RuntimeException('Configured LOCAL_PYTHON_BIN was not found. Update .env or run the native setup script.');
            }

            throw new \RuntimeException('Python was not found. Set LOCAL_PYTHON_BIN in .env or run setup-xampp.bat / setup-native.sh.');
        }

        throw $lastError
            ?? new \RuntimeException('Unable to start the local Python worker.');
    }

    private function execute(array $command, array $payload, int $timeoutSeconds): array
    {
        // The PHP/Python contract is JSON over stdin/stdout to avoid shell quoting issues.
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new \RuntimeException('Failed to encode the local Python request payload.');
        }

        $this->cleanupStaleWorkerOutputFiles(sys_get_temp_dir());
        $stdoutPath = tempnam(sys_get_temp_dir(), 'light-worker-out-');
        if ($stdoutPath === false) {
            throw new \RuntimeException('Unable to create local Python worker output storage.');
        }
        $stderrPath = tempnam(sys_get_temp_dir(), 'light-worker-err-');
        if ($stderrPath === false) {
            @unlink($stdoutPath);
            throw new \RuntimeException('Unable to create local Python worker error storage.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            // File-backed output avoids blocking reads from anonymous pipes on Windows.
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];

        $pipes = [];
        $process = null;
        $deadline = microtime(true) + max(1, $timeoutSeconds);

        try {
            $this->restrictWorkerOutputFile($stdoutPath);
            $this->restrictWorkerOutputFile($stderrPath);

            // Force UTF-8 at the process boundary so worker output stays decodable on Windows and Linux.
            putenv('PYTHONIOENCODING=utf-8');

            $process = $this->openProcess($command, $descriptorSpec, $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException('Unable to launch the local Python worker process.');
            }

            stream_set_blocking($pipes[0], false);
            $payloadWritten = 0;
            $payloadLength = strlen($jsonPayload);
            $writeDeadline = min($deadline, microtime(true) + 10.0);

            while ($payloadWritten < $payloadLength) {
                if (microtime(true) > $writeDeadline) {
                    throw new \RuntimeException('Failed to write request payload to local Python worker.');
                }

                $written = fwrite($pipes[0], substr($jsonPayload, $payloadWritten, 8192));
                if ($written === false || $written === 0) {
                    usleep(10000);
                    continue;
                }
                $payloadWritten += $written;
            }
            fclose($pipes[0]);
            unset($pipes[0]);

            // Poll process status only; reading output pipes here can block past the deadline on Windows.
            while (true) {
                $status = proc_get_status($process);
                if (!is_array($status) || !$status['running']) {
                    break;
                }

                if (microtime(true) > $deadline) {
                    $pid = isset($status['pid']) ? (int)$status['pid'] : null;
                    $this->terminateProcessTree($process, $pid);
                    ProcessingDiagnostics::workerExit(null, (string)@file_get_contents($stderrPath));
                    throw new ProcessingError('PROCESSING_TIMEOUT');
                }

                usleep(50000);
            }

            $reportedExitCode = (
                is_array($status)
                && array_key_exists('exitcode', $status)
                && is_int($status['exitcode'])
                && $status['exitcode'] >= 0
            ) ? $status['exitcode'] : null;

            $closeExitCode = proc_close($process);
            $process = null;
            $exitCode = $reportedExitCode ?? $closeExitCode;
            $stdout = file_get_contents($stdoutPath);
            $stderr = file_get_contents($stderrPath);
            if ($stdout === false || $stderr === false) {
                throw new \RuntimeException('Could not read local Python worker output.');
            }
            ProcessingDiagnostics::workerExit($exitCode, $stderr);
            if ($exitCode !== 0) {
                throw $this->buildProcessFailure($stderr, $stdout, $exitCode);
            }

            // Every successful worker call must end with a JSON object the PHP side can trust structurally.
            $decoded = json_decode($stdout, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Local Python worker returned an invalid response.');
            }
            $failure = $this->workerFailure($decoded);
            if ($failure !== null) {
                throw $failure;
            }

            return $decoded;
        } finally {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if (is_array($status) && $status['running']) {
                    $pid = isset($status['pid']) ? (int)$status['pid'] : null;
                    $this->terminateProcessTree($process, $pid);
                }
                proc_close($process);
            }
            @unlink($stdoutPath);
            @unlink($stderrPath);
        }
    }

    private function restrictWorkerOutputFile(string $path): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            if (!chmod($path, 0600)) {
                throw new \RuntimeException('Unable to restrict local Python worker output permissions.');
            }
            return;
        }

        $systemRoot = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), '\\/');
        $whoamiPath = $systemRoot . '\\System32\\whoami.exe';
        $icaclsPath = $systemRoot . '\\System32\\icacls.exe';
        if (!is_file($whoamiPath) || !is_file($icaclsPath)) {
            throw new \RuntimeException('Unable to secure local Python worker output permissions.');
        }

        [, $identityOutput] = $this->runWindowsCommand([
            $whoamiPath,
            '/user',
            '/fo',
            'csv',
            '/nh',
        ]);
        if (preg_match('/\b(S-\d-\d+(?:-\d+)+)\b/i', $identityOutput, $matches) !== 1) {
            throw new \RuntimeException('Unable to identify the local Python worker service account.');
        }

        [$exitCode] = $this->runWindowsCommand([
            $icaclsPath,
            $path,
            '/inheritance:r',
            '/grant:r',
            '*' . $matches[1] . ':(F)',
            '*S-1-5-18:(F)',
            '*S-1-5-32-544:(F)',
        ]);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Unable to secure local Python worker output permissions.');
        }
    }

    private function runWindowsCommand(array $command): array
    {
        $pipes = [];
        $process = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to secure local Python worker output permissions.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($stdout === false || $stderr === false) {
            throw new \RuntimeException('Unable to secure local Python worker output permissions.');
        }

        return [$exitCode, $stdout, $stderr];
    }

    private function cleanupStaleWorkerOutputFiles(string $directory): void
    {
        $cutoff = time() - 1800;
        foreach (['light-worker-out-', 'light-worker-err-'] as $prefix) {
            $paths = glob($directory . DIRECTORY_SEPARATOR . $prefix . '*');
            if (!is_array($paths)) {
                continue;
            }

            foreach ($paths as $path) {
                if (!is_file($path) || is_link($path)) {
                    continue;
                }

                $modifiedAt = filemtime($path);
                if ($modifiedAt !== false && $modifiedAt < $cutoff) {
                    @unlink($path);
                }
            }
        }
    }

    private function terminateProcessTree($process, ?int $pid): void
    {
        if (DIRECTORY_SEPARATOR === '\\' && $pid !== null && $pid > 0) {
            // On Windows, virtualenv shims and multi-process workers spawn child processes.
            // Standard proc_terminate only signals the top process handle, leaving child
            // workers alive. Force-kill the full tree via taskkill.
            @exec("taskkill /F /T /PID {$pid} 2>&1");
        }
        if (is_resource($process)) {
            @proc_terminate($process);
        }
    }

    private function openProcess(array $command, array $descriptorSpec, array &$pipes)
    {
        $capturedWarning = null;

        // Convert proc_open warnings into exceptions so callers get one consistent failure path.
        set_error_handler(
            static function (int $severity, string $message) use (&$capturedWarning): bool {
                $capturedWarning = trim($message);
                return true;
            }
        );

        $envVars = array_merge(getenv(), [
            // Pass runtime configuration through the environment so the worker stays stateless.
            'PYTHONIOENCODING' => 'utf-8',
            'OPENBLAS_NUM_THREADS' => '1',
            'OMP_NUM_THREADS' => '1',
            'MKL_NUM_THREADS' => '1',
            // The configured embedding model is local-only. Do not let optional
            // tokenizer/adapter resolution stall a service account on network
            // retries. Missing local resources retain the existing fallback.
            'HF_HUB_OFFLINE' => '1',
            'TRANSFORMERS_OFFLINE' => '1',
            'TOKENIZERS_PARALLELISM' => 'false',
            'LIGHT_STAGE_TRACE' => '1',
            'SUMMARIZER_DIAGNOSTICS_DIR' => config('storage.logs'),
            'NLTK_DATA' => config('python.nltk_data'),
            'SUMMARIZER_UPLOADS_DIR' => config('storage.uploads'),
            'SUMMARIZER_TMP_DIR' => config('storage.tmp'),
            'SUMMARIZER_LOCAL_EMBEDDING_MODEL' => config('summarizer.local_embedding_model', ''),
            'TESSERACT_CMD' => config('python.tesseract_cmd'),
            'POPPLER_PATH' => config('python.poppler_path'),
            'TRANSLATION_PROVIDER' => config('translation.provider'),
            'TRANSLATION_TARGET_LANG' => config('translation.target_lang'),
            'TTS_MAX_CHARS' => config('tts.max_chars'),
            'TTS_MAX_TEXT_LENGTH' => config('tts.max_text_length'),
            'TTS_REQUEST_TIMEOUT_SECONDS' => config('tts.timeout_seconds'),
            'TTS_OUTPUT_DIR' => config('tts.output_dir'),
            'AUDIO_OUTPUT_DIR' => config('tts.audio_output_dir'),
            'AUDIO_PUBLIC_URL' => config('tts.audio_public_url'),
            'PIPER_BIN' => config('tts.piper_bin'),
            'PIPER_VOICE' => config('tts.piper_voice'),
            'PIPER_MODEL_EN' => config('tts.piper_model_en'),
            'PIPER_MODEL_FIL' => config('tts.piper_model_fil'),
            'PIPER_CONFIG_EN' => config('tts.piper_config_en'),
            'PIPER_CONFIG_FIL' => config('tts.piper_config_fil'),
            'PIPER_CACHE_DIR' => config('tts.piper_cache_dir'),
            'PIPER_DATA_DIR' => config('tts.piper_data_dir'),
            'PIPER_DOWNLOAD_TIMEOUT_SECONDS' => config('tts.piper_download_timeout_seconds'),
            'PIPER_USE_CUDA' => config('tts.piper_use_cuda'),
        ]);

        $processOptions = DIRECTORY_SEPARATOR === '\\' ? ['bypass_shell' => true] : [];
        try {
            $process = proc_open($command, $descriptorSpec, $pipes, $this->workingDirectory, $envVars, $processOptions);
        } finally {
            restore_error_handler();
        }

        if (is_resource($process)) {
            return $process;
        }

        $warning = strtolower((string)$capturedWarning);
        if ($warning !== '') {
            if (str_contains($warning, 'error code: 1920')) {
                throw new PythonWorkerLaunchException('Configured Python command could not start. Check LOCAL_PYTHON_BIN in .env and verify the local Python setup.');
            }

            throw new PythonWorkerLaunchException('Unable to launch the local Python worker process. ' . $capturedWarning);
        }

        throw new PythonWorkerLaunchException('Unable to launch the local Python worker process.');
    }

    private function buildProcessFailure(string $stderr, string $stdout, ?int $exitCode): ProcessingError
    {
        // Inspect both output channels: CLI versions have used each for JSON
        // failures. Diagnostics on stderr must not mask a typed source error.
        $fallback = null;
        foreach ([$stdout, $stderr] as $output) {
            // Libraries may write a warning before the worker's final JSON
            // line. A valid contract at the end still describes the failure.
            $candidates = [trim($output), ...array_reverse(array_slice(preg_split('/\R/', trim($output)) ?: [], -20))];
            foreach ($candidates as $candidate) {
                $decoded = json_decode($candidate, true);
                if (!is_array($decoded)) continue;
                $failure = $this->workerFailure($decoded);
                if ($failure === null) continue;
                if ($failure->errorCode !== 'PROCESSING_FAILED') return $failure;
                $fallback ??= $failure;
            }
        }
        $diagnostic = strtolower($stderr);
        if (str_contains($diagnostic, 'modulenotfounderror')
            || str_contains($diagnostic, 'importerror:')
            || str_contains($diagnostic, 'import error:')
            || str_contains($diagnostic, 'python was not found')) {
            return new ProcessingError('WORKER_UNAVAILABLE');
        }
        // A nonzero exit alone cannot prove whether the source or runtime failed.
        return $fallback ?? new ProcessingError('PROCESSING_FAILED');
    }

    private function workerFailure(array $response, ?int $httpStatus = null): ?ProcessingError
    {
        $reportedCode = $response['failure']['code'] ?? $response['code'] ?? null;
        if (in_array($httpStatus, [401, 403], true) && ($reportedCode === null || $reportedCode === 'AUTH_REQUIRED')) {
            // The worker secret belongs to the service. Signing in again cannot
            // repair an internal credential mismatch or rotation race.
            return new ProcessingError('WORKER_UNAVAILABLE');
        }
        if (is_array($response['failure'] ?? null)) {
            return ProcessingError::fromWorker($response['failure']);
        }
        if (is_string($response['code'] ?? null) && isset(ProcessingError::catalog()[$response['code']])) {
            return ProcessingError::fromWorker($response);
        }
        if (!empty($response['timeout'])) {
            return new ProcessingError('PROCESSING_TIMEOUT');
        }
        if (($response['accepted'] ?? null) === false && in_array($httpStatus, [429, 503], true)) {
            return new ProcessingError('WORKER_UNAVAILABLE');
        }
        if (!empty($response['error']) || ($httpStatus !== null && $httpStatus >= 400)) {
            // Compatibility with old v2 workers while they are restarted. Do
            // not interpret arbitrary exception strings as precise diagnoses.
            $legacyCodes = [
                'empty_input' => 'INPUT_EMPTY',
                'empty_source' => 'INPUT_EMPTY',
                'input_too_short' => 'INPUT_TOO_SHORT',
                'input_too_large' => 'INPUT_TOO_LARGE',
                'unsupported_file_type' => 'FILE_UNSUPPORTED',
                'encrypted_document' => 'FILE_ENCRYPTED',
                'unreadable_document' => 'FILE_TEXT_UNAVAILABLE',
                'extraction_failed' => 'DOCUMENT_EXTRACTION_FAILED',
                'source_unavailable' => 'DOCUMENT_EXTRACTION_FAILED',
                'no_candidates' => 'NLP_NO_VALID_CANDIDATES',
                'no_sentences' => 'NLP_NO_VALID_CANDIDATES',
                'no_summary' => 'NLP_NO_VALID_CANDIDATES',
                'quality_failed' => 'SUMMARY_QUALITY_FAILED',
                'provenance_failure' => 'SUMMARY_QUALITY_FAILED',
                'worker_failure' => 'PROCESSING_FAILED',
            ];
            if (is_string($response['code'] ?? null) && isset($legacyCodes[$response['code']])) {
                return new ProcessingError($legacyCodes[$response['code']]);
            }
            return new ProcessingError('PROCESSING_FAILED');
        }
        return null;
    }

    private function resolveConfiguredPythonBinary(?string $pythonBinary): ?string
    {
        // Explicit constructor input wins, then .env, then the fallback candidate list.
        foreach ([$pythonBinary, config('python.bin')] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function buildPythonCandidates(string $projectRoot, ?string $configuredPythonBinary): array
    {
        $commandLists = [];
        if ($configuredPythonBinary !== null && $configuredPythonBinary !== '') {
            $commandLists[] = [$configuredPythonBinary];
        }

        // Prefer the repo-local virtual environments before falling back to system Python.
        $localCandidates = [
            $projectRoot . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe',
            $projectRoot . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python',
            $projectRoot . DIRECTORY_SEPARATOR . '.venv311' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe',
            $projectRoot . DIRECTORY_SEPARATOR . '.venv312' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe',
            $projectRoot . DIRECTORY_SEPARATOR . '.venv311' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python',
            $projectRoot . DIRECTORY_SEPARATOR . '.venv312' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python',
        ];

        foreach ($localCandidates as $candidate) {
            $commandLists[] = [$candidate];
        }

        $commandLists[] = ['python'];
        if (DIRECTORY_SEPARATOR === '\\') {
            $commandLists[] = ['py', '-3'];
            $commandLists[] = ['py'];
        }
        $commandLists[] = ['python3'];

        $uniqueCommands = [];
        $seen = [];
        foreach ($commandLists as $command) {
            $normalizedCommand = array_values(array_filter(
                array_map(
                    static fn($segment) => is_string($segment) ? trim($segment) : '',
                    $command
                ),
                static fn($segment) => $segment !== ''
            ));
            if ($normalizedCommand === []) {
                continue;
            }

            $commandKey = implode("\0", $normalizedCommand);
            if (isset($seen[$commandKey])) {
                continue;
            }

            $seen[$commandKey] = true;
            $uniqueCommands[] = $normalizedCommand;
        }

        return $uniqueCommands;
    }

    private function acquireLightEngineSlot(int $timeoutSeconds): mixed
    {
        $lockDirectory = sys_get_temp_dir();
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        do {
            for ($slotIndex = 0; $slotIndex < 2; $slotIndex++) {
                $lock = @fopen(
                    $lockDirectory . DIRECTORY_SEPARATOR . 'light-nlp-v2-slot-' . $slotIndex . '.lock',
                    'c'
                );
                if ($lock === false) {
                    throw new ProcessingError('WORKER_UNAVAILABLE');
                }
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    return $lock;
                }
                fclose($lock);
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        throw new ProcessingError('WORKER_UNAVAILABLE');
    }

    private function procOpenIsAvailable(): bool
    {
        // Some shared-hosting style PHP builds disable proc_open entirely.
        if (!function_exists('proc_open')) {
            return false;
        }

        $disabledFunctions = array_map(
            'trim',
            explode(',', (string)ini_get('disable_functions'))
        );

        return !in_array('proc_open', $disabledFunctions, true);
    }

    private function commandStartsWithExplicitPath(array $command): bool
    {
        return isset($command[0]) && is_string($command[0]) && $this->isExplicitPath($command[0]);
    }

    private function isExplicitPath(string $candidate): bool
    {
        // Distinguish real paths from PATH-resolved commands like `python` or `py`.
        return str_contains($candidate, DIRECTORY_SEPARATOR)
            || str_contains($candidate, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidate) === 1;
    }

    private function tryExecuteViaDaemon(string $action, array $payload, int $timeoutSeconds): ?array
    {
        $isCancellation = $action === 'cancel';
        $port = (int)(getenv('PYTHON_WORKER_PORT') ?: 5005);
        if ($port <= 0 || $port > 65535) {
            $port = 5005;
        }

        // Reliable non-blocking socket check: fallback to CLI is only safe and justified if daemon is offline
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.75);
        if (!$socket) {
            return null; // Daemon is offline -> safe and justified fallback to CLI
        }
        fclose($socket);

        $secret = $this->resolveWorkerToken();
        $healthHeaders = [];
        if ($secret !== '') {
            $healthHeaders[] = 'X-Worker-Token: ' . $secret;
        }
        $healthContext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $healthHeaders),
                'timeout' => $isCancellation ? 2 : 3,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
        ]);
        $healthRaw = @file_get_contents("http://127.0.0.1:{$port}/health", false, $healthContext);
        $health = is_string($healthRaw) ? json_decode($healthRaw, true) : null;
        if (!is_array($health) || ($health['status'] ?? null) !== 'ok') {
            throw new \RuntimeException('Local Python supervisor failed its authenticated health check.');
        }
        if ((int)($health['protocol'] ?? 0) !== 3 || empty($health['supervised'])) {
            throw new \RuntimeException('Local Python supervisor is outdated. Restart the LIGHT NLP worker.');
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new \RuntimeException('Failed to encode worker request payload.');
        }

        // Keep the daemon's execution deadline aligned with PHP's configured
        // budget, while allowing a small transport margin for its JSON reply.
        $effectiveTimeout = $isCancellation ? 2 : max(10, min($timeoutSeconds, 300));
        $daemonDeadline = (float)($health['execution_deadline_seconds'] ?? 0);
        if (!$isCancellation && $daemonDeadline < $effectiveTimeout) {
            throw new \RuntimeException(sprintf(
                'Local Python supervisor deadline is %d seconds, below this request budget. Restart the LIGHT NLP worker.',
                (int)$daemonDeadline
            ));
        }
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($jsonPayload),
            'X-Worker-Deadline: ' . $effectiveTimeout,
        ];

        $secret = $this->resolveWorkerToken();
        if ($secret !== '') {
            $headers[] = 'X-Worker-Token: ' . $secret;
        }

        $transportTimeout = $isCancellation ? 2 : $effectiveTimeout + 5;
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $jsonPayload,
                'timeout' => $transportTimeout,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
        ];

        $url = "http://127.0.0.1:{$port}/" . urlencode($action);
        $context = stream_context_create($options);
        $requestStarted = microtime(true);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            // Never launch a second worker after a request could have started.
            $timedOut = microtime(true) - $requestStarted >= $transportTimeout - 0.5;
            throw new ProcessingError($timedOut ? 'PROCESSING_TIMEOUT' : 'WORKER_UNAVAILABLE');
        }
        try {
            $raw = stream_get_contents($stream);
            $metadata = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        if (!empty($metadata['timed_out'])) {
            throw new ProcessingError('PROCESSING_TIMEOUT');
        }
        if ($raw === false) {
            throw new ProcessingError('WORKER_UNAVAILABLE');
        }
        $httpStatus = null;
        foreach (($metadata['wrapper_data'] ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $httpStatus = (int)$matches[1];
            }
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ProcessingError('WORKER_UNAVAILABLE');
        }
        $failure = $this->workerFailure($decoded, $httpStatus);
        if ($failure !== null) {
            throw $failure;
        }

        return $decoded;
    }

    private function resolveWorkerToken(): string
    {
        $token = trim((string)(getenv('PYTHON_WORKER_SECRET') ?: ''));
        if ($token !== '') {
            return $token;
        }
        $tokenFile = dirname($this->scriptPath, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'worker.token';
        if (is_file($tokenFile) && is_readable($tokenFile)) {
            return trim((string)file_get_contents($tokenFile));
        }
        return '';
    }

    private function configuredSummaryTimeout(?int $timeoutSeconds): int
    {
        $configuredTimeout = $timeoutSeconds ?? (int)config('python.timeout', 180);
        return max(30, min($configuredTimeout, 300));
    }
}
