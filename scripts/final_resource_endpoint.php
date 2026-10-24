<?php
declare(strict_types=1);
// Evaluation-only alias in a disposable loopback container. Fails closed on host.
header('Content-Type: application/json');
$expected = (string)getenv('IV_BENCH_TOKEN');
if (getenv('IV_RESOURCE_SANDBOX') !== 'true' || strlen($expected) < 32 ||
    !hash_equals($expected, (string)($_SERVER['HTTP_X_IV_TOKEN'] ?? ''))) {
    http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
}
$started = microtime(true);
require_once '/var/www/html/app/src/Services/ArticleService.php';
use App\Src\Services\ArticleService;
use App\Src\Services\LocalPythonBridge;
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$corpus = json_decode(file_get_contents('/ivdata/corpus.json'), true);
$doc = null;
foreach ($corpus as $candidate) if ($candidate['id'] === 'IV-RESEARCH') $doc = $candidate;
try {
    $reflection = new ReflectionClass(ArticleService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('pythonBridge')->setValue($service, new LocalPythonBridge('/opt/venv/bin/python'));
    $result = $service->requestSummary(!empty($input['pdf']) ? '' : $doc['source_text'],
        !empty($input['pdf']) ? '/ivdata/resource-large.pdf' : '', 15,
        'standard_paragraph', 'comprehensive', '', 'general', 'general', 'paragraph',
        false, 0, (string)($input['focus'] ?? ''));
    echo json_encode(['ok'=>true,'seconds'=>microtime(true)-$started,
        'engine'=>$result['source_metadata']['engine'] ?? null,
        'engine_version'=>$result['source_metadata']['engine_version'] ?? null,
        'daemon_elapsed_seconds'=>$result['source_metadata']['daemon_elapsed_seconds'] ?? null,
        'annotation_status'=>$result['source_metadata']['article_analysis']['annotation_status'] ?? null,
        'php_allocated_heap_peak_bytes'=>memory_get_peak_usage(true),
        'php_used_heap_peak_bytes'=>memory_get_peak_usage(false)]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'seconds'=>microtime(true)-$started,
        'exception'=>get_class($error),
        'failure_code'=>$error instanceof App\Src\Support\ProcessingError ? $error->errorCode : null,
        'php_allocated_heap_peak_bytes'=>memory_get_peak_usage(true),
        'php_used_heap_peak_bytes'=>memory_get_peak_usage(false)]);
}
