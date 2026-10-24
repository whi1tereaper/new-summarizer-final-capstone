<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/src/Support/ProcessingError.php';
require_once __DIR__ . '/../app/src/Services/SummaryJobService.php';
use App\Src\Support\ProcessingDiagnostics as Diagnostics;
use App\Src\Support\ProcessingError;

$checks = 0;
function verifyRuntime(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    ++$checks;
    echo 'PASS: ' . $label . PHP_EOL;
}
$correlation = bin2hex(random_bytes(16));
Diagnostics::begin($correlation);
Diagnostics::stage('python_bridge', 'LocalPythonBridge', 'execute');
Diagnostics::engine('legacy');
Diagnostics::workerExit(1, 'MemoryError: submitted-document-secret C:\\private\\source.pdf');
$failure = ProcessingError::fromThrowable(new RuntimeException('password=private-source-secret'));
$failure->log();
$path = config('storage.logs') . DIRECTORY_SEPARATOR . 'request-diagnostics.jsonl';
$record = null;
foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
    $data = json_decode($line, true);
    if (($data['reference'] ?? null) === $failure->reference) $record = $data;
}
verifyRuntime(is_array($record), 'public reference matches a private diagnostic record');
verifyRuntime($record['correlation_id'] === $correlation, 'job correlation retained');
verifyRuntime($record['stage'] === 'python_bridge', 'failure stage retained');
verifyRuntime($record['module'] === 'LocalPythonBridge' && $record['function'] === 'execute', 'module/function retained');
verifyRuntime($record['exception_class'] === RuntimeException::class, 'underlying exception class retained');
verifyRuntime($record['duration_ms'] >= 0, 'execution duration recorded');
verifyRuntime($record['python_exit_status'] === 1, 'Python exit status recorded');
verifyRuntime($record['python_exception_class'] === 'MemoryError', 'stderr reduced to allowed classification');
verifyRuntime($record['engine'] === 'legacy', 'engine retained');
verifyRuntime(!str_contains(json_encode($record), 'secret') && !str_contains(json_encode($record), 'source.pdf'), 'private log excludes content, paths and exception prose');
verifyRuntime(!str_contains(json_encode($failure->toArray()), 'exception_class'), 'private diagnostics absent from public contract');
$service = (new ReflectionClass(\App\Src\Services\SummaryJobService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($service, 'buildDetachedWindowsCommand');
$method->setAccessible(true);
foreach (['mysql', 'pgsql'] as $driver) {
    $command = $method->invoke($service, 'C:\\PHP\\php.exe', 'C:\\app path\\summary_worker.php', $correlation, $driver);
    verifyRuntime(str_contains($command, '"--database-driver=' . $driver . '"'), 'detached ' . $driver . ' identity pinned');
}
try {
    $method->invoke($service, 'php', 'worker', $correlation, 'mysql & injected');
    verifyRuntime(false, 'invalid backend rejected');
} catch (RuntimeException $expected) {
    verifyRuntime(true, 'invalid backend rejected');
}
require_once __DIR__ . '/../app/src/Services/ArticleService.php';
$article = (new ReflectionClass(\App\Src\Services\ArticleService::class))->newInstanceWithoutConstructor();
$unsupportedMethod = new ReflectionMethod($article, 'isUnsupportedArticleFocusResult');
$unsupportedMethod->setAccessible(true);
$unsupported = ['source_metadata' => ['engine'=>'recipe_article','article_analysis'=>['focus_status'=>'unsupported'],
    'source_guard'=>['status'=>'exact_source_and_context_validated','sentences_checked'=>0,'issues'=>[]]],
    'validation_passed'=>true,'focus_query'=>'absent subject','sentences'=>[],'evidence'=>[],
    'plain_summary'=>'The article does not contain enough information about the selected focus.'];
verifyRuntime($unsupportedMethod->invoke($article,$unsupported), 'recognized unsupported Focus remains informational');
foreach (['plain_summary'=>'arbitrary worker error','focus_query'=>'','sentences'=>['Invented excerpt.'],'evidence'=>[['invented'=>true]],'validation_passed'=>false] as $key=>$value) {
    verifyRuntime(!$unsupportedMethod->invoke($article,array_replace($unsupported,[$key=>$value])), 'malformed unsupported Focus rejected: '.$key);
}
$blockMethod = new ReflectionMethod($article,'normalizeSummaryBlocks');
$blockMethod->setAccessible(true);
verifyRuntime($blockMethod->invoke($article,[],true)===[], 'unsupported Focus does not fabricate evidence');
try { $blockMethod->invoke($article,[]); verifyRuntime(false,'ordinary empty result rejected'); }
catch (ProcessingError $expected) { verifyRuntime($expected->errorCode==='SUMMARY_QUALITY_FAILED','ordinary empty result still rejected'); }
echo "{$checks} runtime diagnostic checks passed.\n";
