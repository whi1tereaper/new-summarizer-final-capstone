<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';
require_once __DIR__ . '/../src/Services/EvaluationAccess.php';
require_once __DIR__ . '/../src/Services/EvaluationService.php';
require_once __DIR__ . '/partials/evaluation-ui.php';

use App\Src\Services\EvaluationAccess;
use App\Src\Services\EvaluationService;

header('Cache-Control: no-store, private');
try {
    EvaluationAccess::requireAdmin();
    $runId = ev_id($_GET['run'] ?? null);
    $format = $_GET['format'] ?? 'json';
    if (!in_array($format, ['json', 'csv', 'html'], true)) { throw new InvalidArgumentException('Unsupported export format.'); }
    $service = new EvaluationService();
    $data = $service->exportRun($runId);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="evaluation-' . $runId . '.json"');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } elseif ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="evaluation-' . $runId . '.csv"');
        $stream = fopen('php://output', 'wb');
        $write = static function (array $row) use ($stream): void {
            // Prevent spreadsheet formula injection in researcher/source-controlled strings.
            $row = array_map(static function ($value) {
                if ($value === null) { return ''; }
                if (is_int($value) || is_float($value)) { return $value; }
                $text = (string) $value;
                return preg_match('/^[\s]*[=+@-]/u', $text) ? "'" . $text : $text;
            }, $row);
            fputcsv($stream, $row, ',', '"', '');
        };
        $write(['record_type','run_uuid','dataset_version','split','document_id','output_id','system','mode','ablation','metric_or_criterion','version','status','value','precision','recall','f1','summary_words','target_words','evaluator_code','note']);
        foreach ($data['outputs'] as $output) {
            $prefix = [$data['run']['run_uuid'],$data['run']['dataset_version'],$data['run']['dataset_split'],$output['document_id'],$output['id'],$output['system_name'],$output['mode'],$output['ablation']];
            $write(array_merge(['output'], $prefix, ['', '', $output['status'], '', '', '', '', $output['word_count'], $output['target_words'], '', $output['error_message']]));
            foreach ($output['metrics'] as $metric) { $write(array_merge(['metric'], $prefix, [$metric['name'],$metric['version'],$metric['status'],$metric['value'],$metric['precision'],$metric['recall'],$metric['f1'],$output['word_count'],$output['target_words'],'',$metric['error']])); }
        }
        foreach ($data['ratings'] as $rating) {
            foreach (EvaluationService::CRITERIA as $criterion) { $write(['rating',$data['run']['run_uuid'],$data['run']['dataset_version'],$data['run']['dataset_split'],'',$rating['output_id'],'','','',$criterion,'Likert-1-5',$rating['status'],$rating[$criterion] ?? null,'','','','','',$rating['evaluator_code'],'']); }
        }
        foreach ($data['errors'] as $error) { $write(['error',$data['run']['run_uuid'],$data['run']['dataset_version'],$data['run']['dataset_split'],'',$error['output_id'],'','','',$error['category'],'','reviewed','','','','','','','',$error['notes']]); }
        fclose($stream);
    } else {
        $selectedRun = $service->getRun($runId);
        $report = $data['report'];
        ev_header('Evaluation report', true, 'reports');
        require __DIR__ . '/partials/evaluation-report.php';
        ev_footer();
    }
} catch (Throwable $e) {
    $status = in_array($e->getCode(), [403,404,405], true) ? $e->getCode() : 400;
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $status === 403 ? 'Evaluation export requires administrator access.' : 'The requested evaluation export is unavailable.';
    error_log('[evaluation export] ' . $e->getMessage());
}
