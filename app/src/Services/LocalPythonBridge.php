<?php
namespace App\Src\Services;

class LocalPythonBridge
{
    private string $scriptPath;
    private string $workingDirectory;
    private array $pythonCandidates;
    private ?string $configuredPythonBinary;

    public function __construct(?string $pythonBinary = null)
    {
        // Resolve the worker script and candidate Python binaries once for the current PHP request.
        $this->scriptPath = config('python.script');
        $this->workingDirectory = dirname($this->scriptPath);
        $this->configuredPythonBinary = $this->resolveConfiguredPythonBinary($pythonBinary);
        $this->pythonCandidates = $this->buildPythonCandidates(dirname($this->scriptPath, 2), $this->configuredPythonBinary);
    }

    public function summarize(array $payload, int $timeoutSeconds = 60): array
    {
        return $this->run('summarize', $payload, $timeoutSeconds);
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
            throw new \RuntimeException('No readable text found');
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

    public function runSelfTest(int $timeoutSeconds = 15): array
    {
        return $this->run('self-test', ['probe' => 'native-environment'], $timeoutSeconds);
    }

    private function run(string $action, array $payload, int $timeoutSeconds): array
    {
        // Fail fast when the PHP runtime itself cannot spawn the local worker.
        if (!$this->procOpenIsAvailable()) {
            throw new \RuntimeException('PHP proc_open() is disabled. Enable proc_open to use summarization, translation, and text-to-speech.');
        }

        if (!is_file($this->scriptPath)) {
            throw new \RuntimeException('Local Python worker script was not found.');
        }

        if (!is_readable($this->scriptPath)) {
            throw new \RuntimeException('Local Python worker script is not readable.');
        }

        $lastError = null;
        $firstAvailableCandidateError = null;
        $candidateAttempted = false;

        // Try configured and local Python binaries in order so the app can recover from partial setups.
        foreach ($this->pythonCandidates as $pythonCommand) {
            $candidateLabel = implode(' ', $pythonCommand);
            if ($this->commandStartsWithExplicitPath($pythonCommand) && !is_file($pythonCommand[0])) {
                continue;
            }

            try {
                $candidateAttempted = true;
                return $this->execute(array_merge($pythonCommand, [$this->scriptPath, $action]), $payload, $timeoutSeconds);
            } catch (\RuntimeException $exception) {
                $firstAvailableCandidateError ??= $exception;
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

        throw $firstAvailableCandidateError
            ?? $lastError
            ?? new \RuntimeException('Unable to start the local Python worker.');
    }

    private function execute(array $command, array $payload, int $timeoutSeconds): array
    {
        // The PHP/Python contract is JSON over stdin/stdout to avoid shell quoting issues.
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new \RuntimeException('Failed to encode the local Python request payload.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Force UTF-8 at the process boundary so worker output stays decodable on Windows and Linux.
        putenv('PYTHONIOENCODING=utf-8');

        $pipes = [];
        $process = $this->openProcess($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch the local Python worker process.');
        }

        fwrite($pipes[0], $jsonPayload);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        $status = null;

        // Poll both streams until exit so long-running jobs do not deadlock on full buffers.
        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!is_array($status) || !$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                // Kill hung workers rather than leaving the browser request open indefinitely.
                proc_terminate($process);
                usleep(100000);
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new \RuntimeException('Local Python worker timed out.');
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $reportedExitCode = (
            is_array($status)
            && array_key_exists('exitcode', $status)
            && is_int($status['exitcode'])
            && $status['exitcode'] >= 0
        ) ? $status['exitcode'] : null;

        $closeExitCode = proc_close($process);
        $exitCode = $reportedExitCode ?? $closeExitCode;
        if ($exitCode !== 0) {
            throw new \RuntimeException($this->buildProcessErrorMessage($stderr, $stdout, $exitCode));
        }

        // Every successful worker call must end with a JSON object the PHP side can trust structurally.
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Local Python worker returned an invalid response.');
        }

        return $decoded;
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
            'NLTK_DATA' => config('python.nltk_data'),
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

        try {
            $process = proc_open($command, $descriptorSpec, $pipes, $this->workingDirectory, $envVars);
        } finally {
            restore_error_handler();
        }

        if (is_resource($process)) {
            return $process;
        }

        $warning = strtolower((string)$capturedWarning);
        if ($warning !== '') {
            if (str_contains($warning, 'error code: 1920')) {
                throw new \RuntimeException('Configured Python command could not start. Check LOCAL_PYTHON_BIN in .env and verify the local Python setup.');
            }

            throw new \RuntimeException('Unable to launch the local Python worker process. ' . $capturedWarning);
        }

        throw new \RuntimeException('Unable to launch the local Python worker process.');
    }

    private function buildProcessErrorMessage(string $stderr, string $stdout, ?int $exitCode): string
    {
        // Prefer specific worker errors first, then fall back to a short process-level diagnostic.
        $errorMessage = trim($stderr);
        if ($errorMessage !== '') {
            $lowerError = strtolower($errorMessage);
            if (str_contains($lowerError, 'import error:') || str_contains($lowerError, 'modulenotfounderror')) {
                return 'Python dependencies are missing. Run setup-xampp.bat or setup-native.sh.';
            }
            if (str_contains($lowerError, 'permission denied')) {
                return 'Permission denied while starting the local Python worker.';
            }

            $decoded = json_decode($errorMessage, true);
            if (is_array($decoded) && !empty($decoded['error']) && is_string($decoded['error'])) {
                return $decoded['error'];
            }

            return $errorMessage;
        }

        $decoded = json_decode(trim($stdout), true);
        if (is_array($decoded) && !empty($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }

        $stdoutSnippet = mb_strimwidth(trim($stdout), 0, 100, '...');
        $stderrSnippet = mb_strimwidth(trim($stderr), 0, 100, '...');
        return sprintf(
            'Local Python worker failed unexpectedly. (Exit Code: %s) [stdout: %s] [stderr: %s]',
            $exitCode ?? 'unknown',
            $stdoutSnippet ?: 'empty',
            $stderrSnippet ?: 'empty'
        );
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
}
