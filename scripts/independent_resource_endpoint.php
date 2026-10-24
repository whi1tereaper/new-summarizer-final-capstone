<?php
declare(strict_types=1);
// Mounted ONLY into an isolated, loopback-bound benchmark container. Never a
// production public endpoint. No database, mail, auth or user records are used.
header('Content-Type: application/json');
$expectedToken = (string)getenv('IV_BENCH_TOKEN');
if (getenv('IV_RESOURCE_SANDBOX') !== 'true' || strlen($expectedToken) < 32 ||
    !hash_equals($expectedToken, (string)($_SERVER['HTTP_X_IV_TOKEN'] ?? ''))) {
    http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
}
$started = microtime(true);
require_once '/var/www/html/app/src/Services/ArticleService.php';
use App\Src\Services\ArticleService;
use App\Src\Services\LocalPythonBridge;
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$corpus = json_decode(file_get_contents('/ivdata/corpus.json'), true);
$doc = null;
foreach ($corpus as $candidate) if ($candidate['id'] === ($input['id'] ?? 'IV-BUSINESS')) $doc = $candidate;
if (!$doc) { http_response_code(400); echo json_encode(['error' => 'unknown_fixture']); exit; }
try {
    // Constructor only opens a DB connection; bypass it to isolate NLP capacity
    // without sending benchmark writes to any real database.
    $reflection = new ReflectionClass(ArticleService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('pythonBridge')->setValue($service, new LocalPythonBridge('/opt/venv/bin/python'));
    $file = !empty($input['large_guide']) ? '/ivbook/guide.pdf' : (!empty($input['pdf']) ? '/ivdata/resource-large.pdf' : '');
    $result = $service->requestSummary($file ? '' : $doc['source_text'], $file, 15,
        'standard_paragraph', 'comprehensive', '', 'general', 'general', 'paragraph',
        false, 0, (string)($input['focus'] ?? ''));
    echo json_encode(['ok' => true, 'seconds' => microtime(true)-$started,
        'sentences' => count($result['sentences'] ?? []),
        'words' => $result['readability']['summary_word_count'] ?? null,
        'engine' => $result['source_metadata']['engine'] ?? null,
        'engine_version' => $result['source_metadata']['engine_version'] ?? null,
        'routing' => $result['source_metadata']['engine_routing'] ?? null,
        'annotation_status' => $result['source_metadata']['article_analysis']['annotation_status'] ?? null,
        'focus_status' => $result['source_metadata']['article_analysis']['focus_status'] ?? null,
        'daemon_elapsed_seconds' => $result['source_metadata']['daemon_elapsed_seconds'] ?? null]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'seconds' => microtime(true)-$started,
        'exception' => get_class($error),
        'failure_code' => $error instanceof App\Src\Support\ProcessingError ? $error->errorCode : null]);
}
