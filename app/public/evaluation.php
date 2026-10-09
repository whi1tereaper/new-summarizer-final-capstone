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

try { $actorId = EvaluationAccess::requireAdmin(); }
catch (Throwable $e) {
    http_response_code(403);
    ev_header('Access restricted', false);
    echo '<p class="analytics-alert">Evaluation management requires an administrator account.</p><p><a href="login.php?context=admin">Administrator sign in</a></p>';
    ev_footer(); exit;
}
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
$tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'overview';
if (!in_array($tab, ['overview', 'datasets', 'runs', 'assignments', 'reports', 'document', 'output'], true)) { $tab = 'overview'; }
$error = null;
$notice = $_SESSION['evaluation_notice'] ?? null;
unset($_SESSION['evaluation_notice']);
$service = null;
$record = null;
$datasets = $runs = $documents = $outputs = [];
$selectedRun = null;
$selectedDataset = null;
try {
    $service = new EvaluationService();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        EvaluationAccess::requirePost();
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $redirect = 'evaluation.php?tab=' . rawurlencode($tab);
        switch ($action) {
            case 'create_dataset':
                $id = $service->createDataset($_POST, $actorId);
                $redirect = 'evaluation.php?tab=datasets&dataset=' . $id;
                $message = 'Dataset version created. Add sources and annotations before queuing a run.';
                break;
            case 'add_document':
                $id = $service->addDocument(ev_id($_POST['dataset_id'] ?? null), $_POST, $actorId);
                $redirect = 'evaluation.php?tab=document&id=' . $id;
                $message = 'Document added. Its source is stored privately in the evaluation corpus.';
                break;
            case 'add_reference':
                $service->addReference(ev_id($_POST['document_id'] ?? null), $_POST, $actorId);
                $message = 'Reference version saved.';
                break;
            case 'add_content_unit':
                $service->addContentUnit(ev_id($_POST['document_id'] ?? null), $_POST, $actorId);
                $message = 'Essential information unit saved.';
                break;
            case 'add_challenge':
                $facts = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['facts'] ?? '')))));
                $prohibited = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['prohibited'] ?? '')))));
                $factKinds = ['negation' => 'negations', 'percentages' => 'numbers', 'decimal_values' => 'numbers', 'units' => 'numbers', 'named_entities' => 'entities', 'statistical_significance' => 'qualifiers', 'causal_vs_correlational' => 'causal_language'];
                $factKind = $factKinds[$_POST['category'] ?? ''] ?? ($_POST['category'] ?? 'annotated');
                $service->addChallengeCase(ev_id($_POST['document_id'] ?? null), [
                    'category' => $_POST['category'] ?? '',
                    'expected_facts' => array_map(static fn(string $fact): array => ['kind' => $factKind, 'text' => $fact], $facts),
                    'prohibited_distortions' => $prohibited,
                    'notes' => $_POST['notes'] ?? '',
                ], $actorId);
                $message = 'Challenge assertions saved. They will be evaluated against generated outputs.';
                break;
            case 'queue_run':
                $config = [
                    'name' => $_POST['name'] ?? '', 'dataset_split' => $_POST['dataset_split'] ?? '',
                    'modes' => $_POST['modes'] ?? [], 'systems' => $_POST['systems'] ?? [],
                    'ablations' => $_POST['ablations'] ?? [], 'seed' => $_POST['seed'] ?? '20261002',
                    'bertscore' => isset($_POST['bertscore']), 'notes' => $_POST['notes'] ?? '',
                ];
                $id = $service->queueRun(ev_id($_POST['dataset_id'] ?? null), $config, $actorId);
                $redirect = 'evaluation.php?tab=runs&run=' . $id;
                $message = 'Run queued. This dataset version is now frozen. The offline worker will generate and evaluate its outputs.';
                break;
            case 'cancel_run':
                $service->cancelRun(ev_id($_POST['run_id'] ?? null), $actorId);
                $message = 'Cancellation requested. Completed results are retained.';
                break;
            case 'resume_run':
                $service->resumeRun(ev_id($_POST['run_id'] ?? null), $actorId);
                $message = 'Remaining work queued. Previously generated results are preserved.';
                break;
            case 'set_dataset_active':
                $service->setDatasetActive(ev_id($_POST['dataset_id'] ?? null), ($_POST['active'] ?? '') === '1', $actorId);
                $message = 'Dataset availability updated.';
                break;
            case 'set_document_active':
                $service->setDocumentActive(ev_id($_POST['document_id'] ?? null), ($_POST['active'] ?? '') === '1', $actorId);
                $redirect = 'evaluation.php?tab=document&id=' . ev_id($_POST['document_id']);
                $message = 'Document availability updated for this draft dataset.';
                break;
            case 'assign':
                $users = array_map('ev_id', preg_split('/[\s,]+/', trim((string) ($_POST['user_ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY));
                $docIds = array_map('ev_id', preg_split('/[\s,]+/', trim((string) ($_POST['document_ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY));
                $n = $service->createAssignments(ev_id($_POST['run_id'] ?? null), $users, $actorId, $docIds);
                $message = $n . ' blinded assignments created. Existing assignments were preserved.';
                break;
            case 'classify_error':
                $service->classifyError(ev_id($_POST['output_id'] ?? null), (string) ($_POST['category'] ?? ''), (string) ($_POST['notes'] ?? ''), $actorId);
                $message = 'Review classification saved.';
                break;
            case 'verify_unit':
                $service->verifyContentUnit(ev_id($_POST['output_id'] ?? null), ev_id($_POST['unit_id'] ?? null), ($_POST['represented'] ?? '') === '1', (string) ($_POST['notes'] ?? ''), $actorId);
                $message = 'Human verification saved. Refresh the offline report to include the latest annotations.';
                break;
            case 'correct_rating':
                $service->correctRating(ev_id($_POST['rating_id'] ?? null), is_array($_POST['ratings'] ?? null) ? $_POST['ratings'] : [], (string) ($_POST['reason'] ?? ''), $actorId);
                $message = 'Administrator-approved correction recorded. Previous values remain in the audit log.';
                break;
            default: throw new InvalidArgumentException('Unknown evaluation action.');
        }
        if (in_array($action, ['add_reference', 'add_content_unit', 'add_challenge'], true)) { $redirect = 'evaluation.php?tab=document&id=' . ev_id($_POST['document_id']); }
        if (in_array($action, ['classify_error', 'verify_unit', 'correct_rating'], true)) { $redirect = 'evaluation.php?tab=output&id=' . ev_id($_POST['output_id']); }
        $_SESSION['evaluation_notice'] = $message;
        rotateCsrfToken();
        header('Location: ' . $redirect, true, 303);
        exit;
    }
    $datasets = $service->listDatasets();
    $runs = $service->listRuns();
    if (isset($_GET['dataset'])) { $selectedDataset = ev_id($_GET['dataset']); $documents = $service->listDocuments($selectedDataset); }
    if (isset($_GET['run'])) {
        $selectedRun = $service->getRun(ev_id($_GET['run']));
        if (!$selectedRun) { throw new InvalidArgumentException('Evaluation run not found.'); }
        $outputs = $service->getOutputs((int) $selectedRun['id']);
    }
    if ($tab === 'document' || $tab === 'output') {
        $id = ev_id($_GET['id'] ?? null);
        $record = $tab === 'document' ? $service->getDocument($id) : $service->getOutputDetail($id);
        if (!$record) { http_response_code(404); throw new InvalidArgumentException('Evaluation record not found.'); }
    }
} catch (InvalidArgumentException | DomainException $e) { $error = $e->getMessage(); }
catch (Throwable $e) {
    error_log('[evaluation page] ' . $e->getMessage());
    $error = 'Evaluation data could not be loaded or saved. Please check that the evaluation migration is installed and try again.';
}
ev_header('Evaluation', true, $tab);
if ($notice): ?><p class="evaluation-notice" role="status"><?= ev_h($notice) ?></p><?php endif;
if ($error): ?><p class="analytics-alert" role="alert"><?= ev_h($error) ?></p><?php endif;

if ($tab === 'overview'):
    $latest = $runs[0] ?? null;
    ?><section class="evaluation-section"><h2>Evidence from your evaluation runs</h2><p>No single measure establishes overall summary quality. Compare lexical overlap, factual checks, compression, and independent human judgments separately.</p></section>
    <?php if (!$latest): ?><section class="empty-state"><h2>No evaluation runs yet</h2><p>Create a versioned dataset, add source documents, and queue a run to collect measurements.</p><a href="evaluation.php?tab=datasets">Manage datasets</a></section>
    <?php else: ?><section class="metric-grid" aria-label="Latest run progress"><?php foreach ([['Dataset', $latest['dataset_version'] ?? '', 'Version used for this run'], ['Documents', $latest['documents_processed'] ?? 0, 'Processed in this run'], ['Outputs', $latest['generated_summaries'] ?? 0, 'Generated summaries'], ['Failures', $latest['failed_summaries'] ?? 0, 'Generation failures']] as [$label, $value, $note]): ?><article class="metric-card"><span><?= ev_h($label) ?></span><strong><?= ev_h($value) ?></strong><small><?= ev_h($note) ?></small></article><?php endforeach; ?></section><p>Latest run: <a href="evaluation.php?tab=runs&run=<?= (int) $latest['id'] ?>"><?= ev_h($latest['name']) ?></a> · <?= ev_h(ev_label($latest['status'])) ?></p><?php endif; ?>
    <?php if ($latest && $service): $latestReport = $service->getRunReport((int) $latest['id']); $latestAssignments = $service->getAssignmentsForRun((int) $latest['id']); $observations = $latestReport['statistics']['automatic'] ?? []; $attempted = array_sum(array_column($observations, 'n_attempted')); $valid = array_sum(array_column($observations, 'n_valid')); $timedCount = 0; $timedTotal = 0.0; foreach ($observations as $measurement) { if ($measurement['metric'] === 'processing_seconds' && $measurement['mean'] !== null) { $timedCount += (int) $measurement['n_valid']; $timedTotal += $measurement['mean'] * $measurement['n_valid']; } } ?><section class="performance-section"><div><p class="eyebrow">Latest recorded analysis</p><h2>Evaluation coverage</h2></div><div class="performance-grid"><span>Mean total evaluation time<b><?= $timedCount ? ev_number($timedTotal / $timedCount, 3) . ' s' : 'Not available' ?></b></span><span>Valid measurements<b><?= (int) $valid ?> / <?= (int) $attempted ?></b></span><span>Assessments submitted<b><?= count(array_filter($latestAssignments, static fn(array $a): bool => $a['status'] === 'submitted')) ?> / <?= count($latestAssignments) ?></b></span><span>Assigned evaluators<b><?= count(array_unique(array_column($latestAssignments, 'evaluator_code'))) ?></b></span></div></section><p class="evaluation-note">Measurement counts include each recorded metric and timing measure. <?= $latestReport['pending'] ? 'The latest statistical refresh is pending.' : 'See the report for metric-specific availability and processing durations.' ?></p><?php endif; ?>
    <section class="evaluation-section"><h2>Research workflow</h2><ol><li>Choose a corpus and record its provenance, category, and development, validation, or test split.</li><li>Add independent references, essential information units, and challenge assertions before freezing the dataset.</li><li>Run the proposed system, optional hybrid model, comparable baselines, and selected ablations through the evaluation worker.</li><li>Assign blinded reviews, classify observed errors, and verify content coverage.</li><li>Export reports with actual sample sizes, unavailable measurements, and version information.</li></ol><p class="evaluation-note">Suggested planning sizes are 48 corpus documents, 24 reference documents, and 3 independent evaluators. These are study targets, not measured results or software limits. Final test results must not guide algorithm tuning.</p></section>
<?php elseif ($tab === 'datasets'): ?>
    <section class="evaluation-section"><h2>Dataset versions</h2><div class="table-wrap"><table><caption>Corpus register</caption><thead><tr><th>Version</th><th>Name</th><th>State</th><th>Actions</th></tr></thead><tbody><?php foreach ($datasets as $dataset): ?><tr><td><a href="evaluation.php?tab=datasets&dataset=<?= (int) $dataset['id'] ?>"><?= ev_h($dataset['version']) ?></a></td><td><?= ev_h($dataset['name']) ?></td><td><?= !empty($dataset['sealed_at']) ? 'Frozen' : 'Draft' ?> · <?= !empty($dataset['active']) ? 'Active' : 'Inactive' ?></td><td><?php ev_form_start('set_dataset_active', ['dataset_id' => $dataset['id'], 'active' => empty($dataset['active']) ? 1 : 0]); ev_submit(empty($dataset['active']) ? 'Activate' : 'Deactivate'); ?></td></tr><?php endforeach; ?><?php if (!$datasets): ?><tr><td colspan="4">No dataset versions have been created.</td></tr><?php endif; ?></tbody></table></div></section>
    <?php if ($selectedDataset):
        $currentDataset = null; foreach ($datasets as $dataset) { if ((int) $dataset['id'] === $selectedDataset) { $currentDataset = $dataset; break; } }
        ?><section class="evaluation-section"><h2>Documents in <?= ev_h($currentDataset['version'] ?? 'selected dataset') ?></h2><div class="table-wrap"><table><thead><tr><th>Title</th><th>Category</th><th>Split</th><th>Profile</th><th>Words</th></tr></thead><tbody><?php foreach ($documents as $document): ?><tr><td><a href="evaluation.php?tab=document&id=<?= (int) $document['id'] ?>"><?= ev_h($document['title']) ?></a></td><td><?= ev_h($document['category']) ?></td><td><?= ev_h($document['dataset_split']) ?></td><td><?= ev_h($document['profile']) ?></td><td><?= (int) $document['original_word_count'] ?></td></tr><?php endforeach; ?><?php if (!$documents): ?><tr><td colspan="5">No documents in this version.</td></tr><?php endif; ?></tbody></table></div></section>
        <?php if ($currentDataset && empty($currentDataset['sealed_at'])): ?><section class="chart-card evaluation-section"><h2>Add a source document</h2><p class="evaluation-note">Paste extracted text. The original provenance is recorded as text and is never fetched automatically.</p><?php ev_form_start('add_document', ['dataset_id' => $selectedDataset]); ?><div class="evaluation-fields"><?php ev_input('title', 'Document title'); ev_select('category', 'Category', ['academic', 'news', 'technical', 'reports']); ev_select('profile', 'Document profile', EvaluationService::PROFILES); ev_select('dataset_split', 'Dataset split', ['development', 'validation', 'test']); ?></div><?php ev_textarea('source_text', 'Original document text'); ev_input('provenance', 'Source citation / provenance'); ev_textarea('notes', 'Inclusion notes', false); ev_submit('Add document'); ?></section><?php elseif ($currentDataset): ?><p class="evaluation-notice">This version is frozen. Create a new dataset version for source or annotation changes.</p><?php endif;
    endif; ?>
    <details class="chart-card evaluation-section"<?= !$datasets ? ' open' : '' ?>><summary>Create a dataset version</summary><?php ev_form_start('create_dataset'); ?><div class="evaluation-fields"><?php ev_input('name', 'Dataset name'); ev_input('version', 'Unique version', 'text', 'EVAL-2026-v1'); ev_input('target_count', 'Planned document count', 'number', '48'); ?></div><?php ev_textarea('notes', 'Selection protocol / notes', false); ev_submit('Create dataset'); ?></details>
<?php elseif ($tab === 'document' && $record): ?>
    <section class="evaluation-section"><h2><?= ev_h($record['title']) ?></h2><p class="evaluation-note"><?= ev_h($record['category']) ?> · <?= ev_h($record['dataset_split']) ?> · <?= ev_h($record['profile']) ?> · <?= (int) $record['original_word_count'] ?> words</p><p>Provenance: <?= ev_h($record['provenance']) ?></p><p>Document state: <?= !empty($record['active']) ? 'Active' : 'Inactive' ?></p><?php if (empty($record['dataset_sealed_at'])): ev_form_start('set_document_active', ['document_id' => $record['id'], 'active' => empty($record['active']) ? 1 : 0]); ev_submit(empty($record['active']) ? 'Activate draft document' : 'Deactivate draft document'); else: ?><p class="evaluation-notice">This dataset version is frozen. Create a new version to change sources or annotations.</p><?php endif; ?><details><summary>Read original text</summary><div class="evaluation-prose"><?= ev_h($record['source_text']) ?></div></details></section>
    <section class="evaluation-section"><h2>Human reference summaries</h2><?php foreach ($record['references'] ?? [] as $reference): ?><article class="chart-card evaluation-section"><p><?= ev_h($reference['version'] ?? $reference['reference_version'] ?? '') ?> · <?= ev_h($reference['review_status']) ?></p><div class="evaluation-prose"><?= ev_h($reference['text'] ?? $reference['reference_text'] ?? '') ?></div></article><?php endforeach; ?>
    <?php if (empty($record['dataset_sealed_at'])): ?><details class="chart-card"><summary>Add an independent reference</summary><p class="evaluation-note">References must be written independently by a human. Use a private evaluator code; do not include personal contact information.</p><?php ev_form_start('add_reference', ['document_id' => $record['id']]); ev_textarea('text', 'Reference summary'); ev_input('version', 'Reference version', 'text', 'v1'); ev_input('author_identifier', 'Private author code'); ev_select('review_status', 'Review status', ['draft', 'approved', 'rejected']); ev_submit('Save reference'); ?></details><?php endif; ?></section>
    <section class="evaluation-section"><h2>Essential information units</h2><ul><?php foreach ($record['content_units'] ?? [] as $unit): ?><li><?= ev_h($unit['text'] ?? $unit['unit_text'] ?? '') ?></li><?php endforeach; ?></ul><?php if (empty($record['dataset_sealed_at'])): ?><details class="chart-card"><summary>Add an information unit</summary><?php ev_form_start('add_content_unit', ['document_id' => $record['id']]); ev_textarea('text', 'Essential fact, finding, method, or recommendation'); ev_submit('Save content unit'); ?></details><?php endif; ?></section>
    <section class="evaluation-section"><h2>Factual challenge assertions</h2><p class="evaluation-note">Use controlled cases to test particular distortions. A passing assertion establishes only the check described.</p><?php foreach ($record['challenge_cases'] ?? [] as $case): ?><p><?= ev_h(ev_label($case['category'])) ?> · <?= count(ev_array($case['expected_facts'] ?? $case['expected_facts_json'] ?? [])) ?> expected facts</p><?php endforeach; ?><?php if (empty($record['dataset_sealed_at'])): ?><details class="chart-card"><summary>Add a challenge case</summary><?php ev_form_start('add_challenge', ['document_id' => $record['id']]); ev_select('category', 'Challenge category', ['negation', 'percentages', 'decimal_values', 'dates', 'units', 'named_entities', 'qualifiers', 'comparisons', 'statistical_significance', 'causal_vs_correlational']); ev_textarea('facts', 'Expected facts — one per line'); ev_textarea('prohibited', 'Prohibited distortions — one per line', false); ev_textarea('notes', 'Challenge notes', false); ev_submit('Save challenge case'); ?></details><?php endif; ?></section>
<?php elseif ($tab === 'runs' || $tab === 'reports'): ?>
    <section class="evaluation-section"><h2><?= $tab === 'reports' ? 'Select a run to report' : 'Evaluation runs' ?></h2><div class="table-wrap"><table><thead><tr><th>Run</th><th>Dataset / split</th><th>Status</th><th>Generated / failed</th><th>Started</th></tr></thead><tbody><?php foreach ($runs as $run): ?><tr><td><a href="evaluation.php?tab=<?= ev_h($tab) ?>&run=<?= (int) $run['id'] ?>"><?= ev_h($run['name']) ?></a></td><td><?= ev_h($run['dataset_version']) ?> / <?= ev_h($run['dataset_split']) ?></td><td><?= ev_h(ev_label($run['status'])) ?></td><td><?= (int) $run['generated_summaries'] ?> / <?= (int) $run['failed_summaries'] ?></td><td><?= ev_h($run['started_at'] ?? 'Queued') ?></td></tr><?php endforeach; ?><?php if (!$runs): ?><tr><td colspan="5">No runs have been queued.</td></tr><?php endif; ?></tbody></table></div></section>
    <?php if ($selectedRun && $tab === 'runs'): ?><section class="chart-card evaluation-section"><h2><?= ev_h($selectedRun['name']) ?></h2><p><?= ev_h(ev_label($selectedRun['status'])) ?> · <?= (int) $selectedRun['documents_processed'] ?> / <?= (int) $selectedRun['documents_total'] ?> documents processed.</p><progress value="<?= (int) $selectedRun['documents_processed'] ?>" max="<?= max(1, (int) $selectedRun['documents_total']) ?>" aria-label="Documents processed"></progress><p>Run identifier: <?= ev_h($selectedRun['run_uuid']) ?></p><p>Configuration fingerprint: <code><?= ev_h($selectedRun['configuration_hash']) ?></code></p><div class="evaluation-actions"><a href="evaluation.php?tab=runs&run=<?= (int) $selectedRun['id'] ?>">Refresh progress</a><a href="evaluation.php?tab=reports&run=<?= (int) $selectedRun['id'] ?>">View report</a></div><?php if (in_array($selectedRun['status'], ['queued', 'running'], true)): ev_form_start('cancel_run', ['run_id' => $selectedRun['id']]); ev_submit('Cancel remaining work'); endif; if (in_array($selectedRun['status'], ['running', 'cancelled', 'failed'], true)): ev_form_start('resume_run', ['run_id' => $selectedRun['id']]); ev_submit('Resume unfinished work'); endif; ?></section>
    <section class="evaluation-section"><h2>Generated outputs and error review</h2><div class="table-wrap"><table><thead><tr><th>Document</th><th>System</th><th>Mode</th><th>Status</th><th>Words / target</th></tr></thead><tbody><?php foreach ($outputs as $output): ?><tr><td><a href="evaluation.php?tab=output&id=<?= (int) $output['id'] ?>"><?= ev_h($output['title'] ?? $output['document_title'] ?? ('Document ' . $output['document_id'])) ?></a></td><td><?= ev_h($output['system_name'] ?? $output['system_key'] ?? $output['system'] ?? '') ?><?= empty($output['ablation']) ? '' : ' minus ' . ev_h(ev_label($output['ablation'])) ?></td><td><?= ev_h($output['mode']) ?></td><td><?= ev_h(ev_label($output['status'])) ?></td><td><?= (int) $output['word_count'] ?> / <?= isset($output['target_words']) ? (int) $output['target_words'] : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php elseif ($selectedRun && $tab === 'reports'):
        $report = $service->getRunReport((int) $selectedRun['id']);
        require __DIR__ . '/partials/evaluation-report.php';
    endif; ?>
    <?php if ($tab === 'runs' && $datasets): ?><details class="chart-card evaluation-section"<?= !$runs ? ' open' : '' ?>><summary>Queue a reproducible evaluation</summary><p class="evaluation-note">Queuing freezes this dataset version and its annotations. Test results are for final evaluation; use development or validation documents for tuning. Baselines use the proposed system’s measured word budget, with whole-sentence deviations recorded.</p><?php ev_form_start('queue_run');
        $datasetOptions = []; foreach ($datasets as $dataset) { if (!empty($dataset['active'])) { $datasetOptions[(string) $dataset['id']] = $dataset['version'] . ' — ' . $dataset['name']; } }
        ev_select('dataset_id', 'Dataset version', $datasetOptions); ev_input('name', 'Run name'); ev_select('dataset_split', 'Split to evaluate', ['development', 'validation', 'test']); ev_input('seed', 'Random seed', 'number', '20261002');
        ev_checks('modes', 'Summary modes', EvaluationService::MODES, ['brief', 'balanced', 'detailed', 'comprehensive']);
        ev_checks('systems', 'Systems to compare', EvaluationService::SYSTEMS, ['proposed', 'lead_n', 'tfidf', 'textrank']);
        ?><p class="evaluation-note">Hybrid LLM is optional and makes provider calls for each document. It sends selected evidence passages to the configured endpoint and may incur charges. Use it only when the corpus may be processed there; unavailable or rejected generations are recorded as failed, not replaced with extractive outputs.</p><?php
        ev_checks('ablations', 'Add full system minus one feature', EvaluationService::ABLATIONS);
        ?><label class="evaluation-check"><input type="checkbox" name="bertscore" value="1">Include optional BERTScore in the offline worker</label><p class="evaluation-note">Requires locally installed metric dependencies and model files. Missing dependencies are reported as unavailable.</p><?php ev_textarea('notes', 'Preregistered comparison / notes', false); ev_submit('Queue evaluation'); ?></details><?php endif; ?>
<?php elseif ($tab === 'assignments'): ?>
    <section class="evaluation-section"><h2>Independent human evaluation</h2><p>Assign the same document subset to independent evaluators. Each receives a stored, randomized presentation order and opaque assignment links. System identity, automatic scores, and other evaluators’ ratings stay hidden.</p><p><a href="evaluate.php">Open my assigned reviews</a></p></section>
    <?php if ($runs): ?><section class="chart-card evaluation-section"><h2>Create blinded assignments</h2><?php ev_form_start('assign'); $runOptions = []; foreach ($runs as $run) { $runOptions[(string) $run['id']] = $run['name']; } ev_select('run_id', 'Evaluation run', $runOptions); ev_input('user_ids', 'Evaluator account IDs — comma separated'); ev_input('document_ids', 'Document subset IDs — leave blank for all', 'text', '', false); ev_submit('Assign reviews'); ?></section><?php endif; ?>
    <?php if ($service && $runs): ?><section class="evaluation-section"><h2>Assignment progress</h2><div class="table-wrap"><table><thead><tr><th>Run</th><th>Assigned</th><th>Submitted</th><th>Evaluators</th></tr></thead><tbody><?php foreach ($runs as $run): $assignedRows = $service->getAssignmentsForRun((int) $run['id']); ?><tr><td><?= ev_h($run['name']) ?></td><td><?= count($assignedRows) ?></td><td><?= count(array_filter($assignedRows, static fn(array $a): bool => $a['status'] === 'submitted')) ?></td><td><?= count(array_unique(array_column($assignedRows, 'evaluator_code'))) ?></td></tr><?php endforeach; ?></tbody></table></div><details><summary>Eligible evaluator account codes</summary><p class="evaluation-note">Use these account IDs in the assignment form. No names or email addresses are displayed here.</p><?php foreach ($service->listEvaluatorCandidates() as $candidate): ?><span>Account <?= (int) $candidate['user_id'] ?> ? <?= ev_h($candidate['pseudonym']) ?>; </span><?php endforeach; ?></details></section><?php endif; ?>
    <p class="evaluation-note">Submitted assessments are locked. Corrections require an administrator, a reason, and an audit record. Open an output’s error-review page to approve a correction. Evaluator account IDs remain administrative information; public reports exclude them.</p>
<?php elseif ($tab === 'output' && $record):
    require __DIR__ . '/partials/evaluation-output.php';
endif;
ev_footer();
