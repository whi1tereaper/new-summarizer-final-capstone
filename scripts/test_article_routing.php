<?php
declare(strict_types=1);

// CLI-only integration probes: no session/bootstrap or database connection.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$scenario = $argv[1] ?? 'all';
$python = $argv[2] ?? '';
if ($scenario === 'all') {
    $failures = 0;
    foreach (['disabled', 'enabled', 'v2', 'metadata', 'bridge'] as $case) {
        $process = proc_open([PHP_BINARY, __FILE__, $case, $python],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start PHP test process.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        echo ($code === 0 ? 'PASS ' : 'FAIL ') . $case . ': ' . trim($output . $error) . PHP_EOL;
        $failures += (int)($code !== 0);
    }
    exit($failures === 0 ? 0 : 1);
}

putenv('SUMMARIZER_AUTO_ARTICLE_ROUTING=' . ($scenario === 'disabled' || $scenario === 'v2' ? 'false' : 'true'));
putenv('SUMMARIZER_ENGINE_VERSION=' . ($scenario === 'v2' ? 'v2' : 'legacy'));
// Force subprocess transport for this probe; it cannot contact an existing worker.
putenv('PYTHON_WORKER_PORT=1');
require_once __DIR__ . '/../app/src/Services/ArticleService.php';

use App\Src\Services\ArticleService;
use App\Src\Services\LocalPythonBridge;

function checkRouting(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class RoutingProbeBridge extends LocalPythonBridge
{
    public function __construct() {}
    public function summarize(array $payload, ?int $timeoutSeconds = null): array
    {
        return ['path' => 'legacy_worker', 'payload' => $payload];
    }
    public function summarizeV2(array $payload, ?int $timeoutSeconds = null): array
    {
        return ['path' => 'v2_worker', 'payload' => $payload];
    }
}

$reflection = new ReflectionClass(ArticleService::class);
$service = $reflection->newInstanceWithoutConstructor();
if ($scenario === 'metadata') {
    $method = $reflection->getMethod('normalizeSourceMetadata');
    $metadata = $method->invoke($service, [
        'engine' => 'legacy',
        'engine_routing' => [
            'enabled' => true, 'selected_engine' => 'legacy', 'reason' => 'article_processing_failed',
            'fallback_used' => true, 'failure_type' => 'RuntimeError',
            'detection' => ['is_article' => true, 'article_type' => 'news', 'confidence' => 0.9,
                'classification_reason' => 'article_structure_and_language',
                'detected_features' => ['words' => 150, 'paragraphs' => 3]],
        ],
        'article_analysis' => ['focus_status' => 'unsupported', 'topic_keywords' => ['transit']],
    ]);
    checkRouting(($metadata['engine_routing']['fallback_used'] ?? false) === true, 'Fallback telemetry was lost.');
    checkRouting(($metadata['engine_routing']['detection']['is_article'] ?? false) === true, 'Detection was lost.');
    checkRouting(($metadata['article_analysis']['focus_status'] ?? '') === 'unsupported', 'Focus status was lost.');
    echo 'Routing and focus metadata survive normalization.';
    exit;
}

$bridge = $scenario === 'bridge' ? new LocalPythonBridge($python !== '' ? $python : null) : new RoutingProbeBridge();
$reflection->getProperty('pythonBridge')->setValue($service, $bridge);
$text = <<<'TEXT'
City tests a new evening bus service

City officials announced an evening bus service on Monday after residents requested safer journeys home. The transport department said three routes would operate until midnight during a six month pilot. Officials reported that passengers currently wait more than forty minutes after the last regular service ends.

The council will fund the pilot from its existing transport budget rather than raise ticket prices. Independent observers will count passengers at each stop and report their findings monthly. The department has not promised to extend the service permanently because future funding remains uncertain.

Residents welcomed the announcement but said several industrial neighborhoods still lacked reliable connections. The mayor said the council would review those concerns after the first passenger survey. A final decision will depend on operating costs and evidence collected throughout the pilot.
TEXT;
$result = $service->requestSummary($text, '', 8, 'standard_paragraph', 'balanced', '', 'general', 'general', 'paragraph', false, 0, '');
if ($scenario === 'bridge') {
    checkRouting(($result['source_metadata']['engine_routing']['selected_engine'] ?? '') === 'recipe_article', 'Real worker did not use recipe_article.');
    checkRouting(count($result['sentences'] ?? []) > 0, 'Real worker returned no evidence.');
    $normalized = $reflection->getMethod('normalizeSourceMetadata')->invoke($service, $result['source_metadata']);
    checkRouting(($normalized['engine_routing']['selected_engine'] ?? '') === 'recipe_article', 'Worker provenance did not survive PHP normalization.');
    echo 'Real PHP/Python JSON round trip and persisted metadata shape passed.';
} else {
    checkRouting($result['path'] === ($scenario === 'v2' ? 'v2_worker' : 'legacy_worker'), 'Existing worker selection changed.');
    checkRouting($result['payload']['summary_depth'] === 'balanced', 'Depth changed.');
    checkRouting(config('summarizer.automatic_article_routing') === ($scenario === 'enabled'), 'Feature flag did not load.');
    echo 'Server selection and original parameters passed.';
}
