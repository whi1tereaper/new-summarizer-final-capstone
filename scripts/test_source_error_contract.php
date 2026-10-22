<?php
declare(strict_types=1);

// Deterministic source transport fixtures. They are scoped to this test process;
// production URL safety checks still run before the mocked network exchange.
namespace App\Src\Services {
    final class SourceHttpFixture
    {
        public static array $responses = [];
        public static array $requests = [];
        public array $options = [];
        public function __construct(public string $url, public array $response) {}
    }
    function dns_get_record(string $host, int $type): array
    {
        return match ($host) {
            'article.example' => [['ip' => '93.184.216.34']],
            'mixed.example' => [['ip' => '93.184.216.34'], ['ip' => '127.0.0.1']],
            default => [],
        };
    }
    function gethostbynamel(string $host): array|false { return false; }
    function curl_init(string $url): SourceHttpFixture
    {
        if (SourceHttpFixture::$responses === []) throw new \RuntimeException('Unexpected network request.');
        $fixture = new SourceHttpFixture($url, array_shift(SourceHttpFixture::$responses));
        SourceHttpFixture::$requests[] = $fixture;
        return $fixture;
    }
    function curl_setopt_array(SourceHttpFixture $fixture, array $options): bool
    {
        $fixture->options = $options + $fixture->options;
        return true;
    }
    function curl_setopt(SourceHttpFixture $fixture, int $option, mixed $value): bool
    {
        $fixture->options[$option] = $value;
        return true;
    }
    function curl_exec(SourceHttpFixture $fixture): bool
    {
        foreach (($fixture->response['headers'] ?? ['content-type' => 'text/html']) as $key => $value) {
            ($fixture->options[CURLOPT_HEADERFUNCTION])($fixture, $key . ': ' . $value . "\r\n");
        }
        $body = $fixture->response['body'] ?? '';
        if ($body !== '' && ($fixture->options[CURLOPT_WRITEFUNCTION])($fixture, $body) !== strlen($body)) {
            $fixture->response['errno'] = CURLE_WRITE_ERROR;
            return false;
        }
        return empty($fixture->response['errno']);
    }
    function curl_errno(SourceHttpFixture $fixture): int { return $fixture->response['errno'] ?? 0; }
    function curl_getinfo(SourceHttpFixture $fixture, int $option): int { return $fixture->response['status'] ?? 200; }
    function curl_close(SourceHttpFixture $fixture): void {}
}

namespace {
    use App\Src\Services\LocalPythonBridge;
    use App\Src\Services\SourceHttpFixture;
    use App\Src\Services\SourceResolver;
    use App\Src\Support\Env;
    use App\Src\Support\ProcessingError;

    if (($argv[1] ?? '') === '--nutshell-fixture') {
        $scenario = $argv[2] ?? 'anonymous';
        $_SERVER['REQUEST_METHOD'] = $scenario === 'method' ? 'GET' : 'POST';
        $_SESSION = $scenario === 'csrf' ? ['user_id' => 1] : [];
        require __DIR__ . '/../app/public/nutshell_generate.php';
        $expectedStatus = match ($scenario) { 'method' => 405, 'csrf' => 403, default => 401 };
        if (http_response_code() !== $expectedStatus) { echo "\nUNEXPECTED_HTTP_STATUS"; exit(1); }
        // Also inspect the actual helpers without requiring a privileged user or
        // a database fixture; legacy keys and precise failure types must coexist.
        foreach (['FILE_CORRUPTED' => 422, 'FILE_ENCRYPTED' => 422, 'SUMMARY_QUALITY_FAILED' => 422, 'WORKER_UNAVAILABLE' => 503, 'PROCESSING_TIMEOUT' => 504] as $code => $status) {
            [$actualStatus, $payload] = nutshellError(503, 'generation_failed', 'private /internal/path', new ProcessingError($code));
            if ($actualStatus !== $status || $payload['error'] !== 'generation_failed' || $payload['failure']['code'] !== $code || str_contains($payload['message'], '/internal/path')) {
                echo "\nINVALID_NUTSHELL_FAILURE_CONTRACT"; exit(1);
            }
            [$previousStatus, $previous] = nutshellPreviousFailure(['failure_reason' => $code]);
            if ($previousStatus !== $status || $previous['failure']['code'] !== $code) { echo "\nINVALID_SAVED_NUTSHELL_FAILURE"; exit(1); }
        }
        exit(0);
    }

    if (($argv[1] ?? '') === '--worker-fixture') {
        stream_get_contents(STDIN);
        $code = $argv[2] ?? 'FILE_CORRUPTED';
        if ($code === 'sleep') { usleep(3000000); exit(0); }
        if ($code === 'malformed') { echo 'not-json'; exit(0); }
        if ($code === 'success') { echo '{"raw_text":"Readable text returned successfully."}'; exit(0); }
        if ($code === 'noisy_corrupted') {
            fwrite(STDERR, "Extraction library diagnostic\n");
            $code = 'FILE_CORRUPTED';
        }
        fwrite(STDERR, json_encode(['error' => 'private /internal/path', 'failure' => [
            'code' => $code, 'reference' => '1234567890abcdef', 'explanation' => 'private /internal/path',
        ]]));
        exit(2);
    }

    if (($argv[1] ?? '') === '--daemon-fixture') {
        $server = stream_socket_server('tcp://127.0.0.1:' . (int)$argv[2], $errno, $error);
        if (!$server) exit(4);
        echo "READY\n";
        fflush(STDOUT);
        while ($connection = @stream_socket_accept($server, 8)) {
            stream_set_timeout($connection, 3);
            $firstLine = fgets($connection);
            if (!$firstLine) { fclose($connection); continue; }
            $length = 0;
            $workerToken = '';
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $match)) $length = (int)$match[1];
                if (preg_match('/^X-Worker-Token:\s*(.+)/i', $line, $match)) $workerToken = trim($match[1]);
            }
            $body = '';
            while (strlen($body) < $length) $body .= fread($connection, $length - strlen($body));
            $isHealth = str_starts_with($firstLine, 'GET /health');
            $isCancel = str_starts_with($firstLine, 'POST /cancel');
            $response = $isHealth
                ? ['status' => 'ok', 'protocol' => 3, 'supervised' => true, 'execution_deadline_seconds' => 300]
                : ['error' => true, 'message' => 'private daemon traceback', 'failure' => [
                    'code' => json_decode($body, true)['fixture_code'] ?? 'FILE_ENCRYPTED',
                    'reference' => '1234567890abcdef',
                ]];
            if ($isCancel && ($argv[3] ?? '') !== 'cancel_failure') {
                $request = json_decode($body, true);
                $response = ['cancelled' => $workerToken === 'test-worker-secret'
                    && ($request['job_id'] ?? '') === str_repeat('a', 32)
                    && ($argv[3] ?? '') !== 'cancel_miss'];
            }
            $json = json_encode($response);
            fwrite($connection, 'HTTP/1.1 ' . ($isHealth || ($isCancel && empty($response['error'])) ? '200 OK' : '422 Unprocessable Content')
                . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json)
                . "\r\nConnection: close\r\n\r\n" . $json);
            fclose($connection);
            if (!$isHealth) break;
        }
        fclose($server);
        exit(0);
    }

    require_once __DIR__ . '/../app/src/Services/SourceResolver.php';
    // Isolate engine choice in memory; never edit the developer's .env.
    $envValues = new ReflectionProperty(Env::class, 'values');
    $values = $envValues->getValue();
    $values['SUMMARIZER_ENGINE_VERSION'] = 'legacy';
    $envValues->setValue(null, $values);

    $passed = 0;
    function check(bool $condition, string $description): void
    {
        global $passed;
        if (!$condition) throw new RuntimeException('FAIL: ' . $description);
        $passed++;
        echo 'PASS: ' . $description . "\n";
    }
    function expectCode(callable $operation, string $code, string $description): ProcessingError
    {
        try { $operation(); } catch (ProcessingError $error) {
            check($error->errorCode === $code, $description . ' [' . $error->errorCode . ']');
            $public = json_encode($error->toArray());
            check(!str_contains($public, '/internal/path') && !str_contains($public, 'traceback'), $description . ' has safe public text');
            return $error;
        }
        throw new RuntimeException('FAIL: expected ' . $code . ' for ' . $description);
    }
    function queueResponse(array $response): void { SourceHttpFixture::$responses = [$response]; }

    $fakeBridge = new class extends LocalPythonBridge {
        public ?string $failureCode = null;
        public string $seenPath = '';
        public function extractPdfText(string $filePath, int $timeoutSeconds = 60): string
        {
            $this->seenPath = $filePath;
            if ($this->failureCode !== null) throw new ProcessingError($this->failureCode);
            return 'The project completed successfully and delivered the expected results.';
        }
    };
    $resolver = new SourceResolver($fakeBridge);
    expectCode(fn() => $resolver->resolve('  '), 'INPUT_EMPTY', 'blank source');
    check($resolver->resolve('A valid short statement.')->getText() === 'A valid short statement.', 'short plain text remains available for engine validation');
    foreach (['https://', 'https://bad host/', 'ftp://article.example/document', 'https://user:password@article.example/'] as $url) {
        expectCode(fn() => $resolver->resolve($url), 'URL_INVALID', 'invalid URL structure ' . parse_url($url, PHP_URL_SCHEME));
    }
    foreach (['http://localhost/', 'http://127.0.0.1/', 'http://10.1.2.3/', 'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://100.64.0.1/', 'https://mixed.example/'] as $url) {
        expectCode(fn() => $resolver->validateUrl($url), 'URL_UNSAFE', 'unsafe URL blocked before fetching');
    }
    expectCode(fn() => $resolver->validateUrl('https://missing.example/'), 'URL_UNREACHABLE', 'DNS failure is not invalid syntax');

    foreach ([401 => 'URL_ACCESS_RESTRICTED', 403 => 'URL_ACCESS_RESTRICTED', 451 => 'URL_ACCESS_RESTRICTED', 404 => 'URL_CONTENT_UNAVAILABLE', 410 => 'URL_CONTENT_UNAVAILABLE', 429 => 'URL_RATE_LIMITED', 503 => 'URL_UNREACHABLE'] as $status => $code) {
        queueResponse(['status' => $status]);
        expectCode(fn() => $resolver->resolve('https://article.example/story'), $code, 'remote HTTP ' . $status);
    }
    foreach ([CURLE_OPERATION_TIMEDOUT => 'URL_TIMEOUT', CURLE_COULDNT_CONNECT => 'URL_UNREACHABLE', 60 => 'URL_TLS_FAILED'] as $errno => $code) {
        queueResponse(['status' => 0, 'errno' => $errno]);
        expectCode(fn() => $resolver->resolve('https://article.example/story'), $code, 'network failure ' . $errno);
    }
    queueResponse(['status' => 302, 'headers' => ['location' => 'http://127.0.0.1/private']]);
    expectCode(fn() => $resolver->resolve('https://article.example/story'), 'URL_UNSAFE', 'unsafe redirect is revalidated');
    queueResponse(['status' => 302, 'headers' => ['location' => 'file:///etc/passwd']]);
    expectCode(fn() => $resolver->resolve('https://article.example/story'), 'URL_UNSAFE', 'non-HTTP redirect rejected');
    queueResponse(['headers' => ['content-type' => 'application/json'], 'body' => '{"ok":true}']);
    expectCode(fn() => $resolver->resolve('https://article.example/story'), 'URL_CONTENT_UNAVAILABLE', 'wrong URL content type');
    queueResponse(['body' => '<html><body><script>private app()</script></body></html>']);
    expectCode(fn() => $resolver->resolve('https://article.example/story'), 'URL_CONTENT_UNAVAILABLE', 'unreadable webpage');
    queueResponse(['headers' => ['content-type' => 'text/html', 'content-length' => 5 * 1024 * 1024 + 1]]);
    expectCode(fn() => $resolver->resolve('https://article.example/story'), 'URL_TOO_LARGE', 'HTML fetch limit');

    $article = 'The town library reopened after completing essential building repairs.';
    queueResponse(['body' => '<html><body><p>Unrelated page chrome should stay outside the article text.</p><article><p>' . $article . '</p></article></body></html>']);
    check($resolver->resolve('https://article.example/story')->getText() === $article, 'valid short article selects article text');
    $lastRequest = SourceHttpFixture::$requests[array_key_last(SourceHttpFixture::$requests)];
    check(($lastRequest->options[CURLOPT_RESOLVE] ?? []) === ['article.example:443:93.184.216.34'], 'cURL pins the validated DNS address');
    check(($lastRequest->options[CURLOPT_PROXY] ?? null) === '', 'proxy environment cannot bypass destination validation');
    check($lastRequest->options[CURLOPT_SSL_VERIFYPEER] === true && $lastRequest->options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS verification remains enabled');
    queueResponse(['headers' => ['content-type' => 'text/plain'], 'body' => 'Short readable article.']);
    check($resolver->resolve('https://article.example/story')->getText() === 'Short readable article.', 'short text/plain source is preserved');

    queueResponse(['headers' => ['content-type' => 'application/pdf'], 'body' => '<html>Access page, not the document.</html>']);
    expectCode(fn() => $resolver->resolve('https://article.example/document.pdf'), 'URL_CONTENT_UNAVAILABLE', 'PDF signature mismatch');
    foreach (['FILE_CORRUPTED', 'FILE_ENCRYPTED', 'FILE_TEXT_UNAVAILABLE', 'DOCUMENT_EXTRACTION_FAILED', 'WORKER_UNAVAILABLE', 'PROCESSING_TIMEOUT'] as $code) {
        $fakeBridge->failureCode = $code;
        queueResponse(['headers' => ['content-type' => 'application/pdf'], 'body' => '%PDF-1.7 fixture']);
        expectCode(fn() => $resolver->resolve('https://article.example/document.pdf'), $code, 'PDF extraction preserves ' . $code);
        check(!file_exists($fakeBridge->seenPath), 'failed PDF extraction removes temporary download');
    }
    $fakeBridge->failureCode = null;
    queueResponse(['headers' => ['content-type' => 'application/pdf'], 'body' => '%PDF-1.7 fixture']);
    $resolution = $resolver->resolve('https://article.example/document.pdf');
    check(str_contains($resolution->getText(), 'project completed'), 'PDF extraction success preserved');
    check(is_file($fakeBridge->seenPath), 'successful source keeps download through processing');
    $resolution->cleanup();
    check(!file_exists($fakeBridge->seenPath), 'successful source cleanup removes download');

    $bridge = new LocalPythonBridge();
    $workerFailure = new ReflectionMethod(LocalPythonBridge::class, 'workerFailure');
    $unknown = $workerFailure->invoke($bridge, ['error' => true, 'message' => 'private /internal/path'], 500);
    check($unknown->errorCode === 'PROCESSING_FAILED', 'unknown worker exception does not invent infrastructure cause');
    check($workerFailure->invoke($bridge, ['timeout' => true, 'error' => true], 504)->errorCode === 'PROCESSING_TIMEOUT', 'legacy daemon timeout classification');
    check($workerFailure->invoke($bridge, ['accepted' => false, 'error' => true], 503)->errorCode === 'WORKER_UNAVAILABLE', 'daemon admission failure classification');
    check($workerFailure->invoke($bridge, ['error' => true, 'failure' => ['code' => 'AUTH_REQUIRED']], 401)->errorCode === 'WORKER_UNAVAILABLE', 'internal worker authentication does not ask the user to sign in');
    check($workerFailure->invoke($bridge, ['error' => 'private text', 'code' => 'encrypted_document'])->errorCode === 'FILE_ENCRYPTED', 'legacy v2 extraction code survives upgrade');
    $processFailure = new ReflectionMethod(LocalPythonBridge::class, 'buildProcessFailure');
    $structuredOutput = json_encode(['failure' => ['code' => 'FILE_CORRUPTED'], 'error' => true]);
    check($processFailure->invoke($bridge, 'unrelated runtime warning', $structuredOutput, 2)->errorCode === 'FILE_CORRUPTED', 'stdout source failure beats stderr diagnostics');
    check($processFailure->invoke($bridge, "Library warning\n" . $structuredOutput, '{"error":"unspecific exception"}', 2)->errorCode === 'FILE_CORRUPTED', 'typed stderr error survives warnings and generic stdout diagnostics');
    check($processFailure->invoke($bridge, 'ModuleNotFoundError: absent dependency', '', 1)->errorCode === 'WORKER_UNAVAILABLE', 'missing worker dependency is infrastructure');

    $execute = new ReflectionMethod(LocalPythonBridge::class, 'execute');
    foreach (['FILE_ENCRYPTED', 'FILE_CORRUPTED', 'SUMMARY_QUALITY_FAILED'] as $code) {
        $error = expectCode(fn() => $execute->invoke($bridge, [PHP_BINARY, __FILE__, '--worker-fixture', $code], [], 5), $code, 'real CLI transport preserves ' . $code);
        check($error->reference === '1234567890abcdef', 'CLI diagnostic reference preserved');
    }
    expectCode(fn() => $execute->invoke($bridge, [PHP_BINARY, __FILE__, '--worker-fixture', 'noisy_corrupted'], [], 5), 'FILE_CORRUPTED', 'real CLI preserves failure after library diagnostics');
    expectCode(fn() => $execute->invoke($bridge, [PHP_BINARY, __FILE__, '--worker-fixture', 'sleep'], [], 1), 'PROCESSING_TIMEOUT', 'real CLI execution deadline');
    check($execute->invoke($bridge, [PHP_BINARY, __FILE__, '--worker-fixture', 'success'], [], 5)['raw_text'] === 'Readable text returned successfully.', 'real CLI successful response');
    foreach (['anonymous' => 'AUTH_REQUIRED', 'method' => 'REQUEST_INVALID', 'csrf' => 'ACCESS_DENIED'] as $scenario => $code) {
        expectCode(fn() => $execute->invoke($bridge, [PHP_BINARY, __FILE__, '--nutshell-fixture', $scenario], [], 5), $code, 'Nutshell endpoint and saved failure mapping ' . $scenario);
    }

    foreach (['extraction', 'cancel_success', 'cancel_miss', 'cancel_failure'] as $daemonScenario) {
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$reservation) throw new RuntimeException('Cannot reserve fixture daemon port.');
    $port = (int)substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
    fclose($reservation);
    $process = proc_open([PHP_BINARY, __FILE__, '--daemon-fixture', (string)$port, $daemonScenario], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture daemon.');
    $previousPort = getenv('PYTHON_WORKER_PORT');
    $previousSecret = getenv('PYTHON_WORKER_SECRET');
    try {
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 5);
        check(trim((string)fgets($pipes[1])) === 'READY', 'local fixture daemon ready');
        putenv('PYTHON_WORKER_PORT=' . $port);
        putenv('PYTHON_WORKER_SECRET=test-worker-secret');
        if ($daemonScenario === 'extraction') {
        $error = expectCode(fn() => $bridge->summarize(['fixture_code' => 'FILE_ENCRYPTED'], 30), 'FILE_ENCRYPTED', 'real daemon HTTP transport preserves extraction failure');
        check($error->reference === '1234567890abcdef', 'daemon diagnostic reference preserved');
        } else {
            check($bridge->cancelJob(str_repeat('a', 32)) === ($daemonScenario === 'cancel_success'), 'authenticated daemon ' . $daemonScenario . ' returns precise best-effort result');
        }
    } finally {
        putenv($previousPort === false ? 'PYTHON_WORKER_PORT' : 'PYTHON_WORKER_PORT=' . $previousPort);
        putenv($previousSecret === false ? 'PYTHON_WORKER_SECRET' : 'PYTHON_WORKER_SECRET=' . $previousSecret);
        foreach ([1, 2] as $index) if (isset($pipes[$index]) && is_resource($pipes[$index])) fclose($pipes[$index]);
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
    }
    $previousPort = getenv('PYTHON_WORKER_PORT');
    try {
        putenv('PYTHON_WORKER_PORT=' . $port);
        check($bridge->cancelJob(str_repeat('a', 32)) === false, 'offline daemon cancellation returns false without starting a worker');
        check($bridge->cancelJob('invalid-job') === false, 'invalid cancellation job ID is rejected');
    } finally {
        putenv($previousPort === false ? 'PYTHON_WORKER_PORT' : 'PYTHON_WORKER_PORT=' . $previousPort);
    }
    echo "\nAll {$passed} source and bridge checks passed. HTTP source fixtures are deterministic; no external site restrictions were bypassed.\n";
}
