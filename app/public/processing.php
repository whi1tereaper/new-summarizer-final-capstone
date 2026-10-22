<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Utils/validation.php';

// This page only exists between form submission and the final result page.
if (empty($_SESSION['pending_summary'])) {
    header('Location: summarizer.php');
    exit;
}

$pending = $_SESSION['pending_summary'];
$sourceType = (string)($pending['source_type'] ?? 'file');
$extension = strtolower(pathinfo((string)($pending['original_file_name'] ?? $pending['file_path'] ?? ''), PATHINFO_EXTENSION));
$sourceLabel = match ($sourceType) {
    'url' => 'Web article',
    'text' => 'Pasted text',
    default => in_array($extension, ['pdf', 'docx'], true) ? strtoupper($extension) . ' document' : 'Uploaded document',
};
$processingTitle = trim((string)($pending['document_title'] ?? ''));
if ($processingTitle === '' && $sourceType === 'file') {
    $processingTitle = trim((string)($pending['original_file_name'] ?? ''));
}
if ($processingTitle === '') {
    $processingTitle = match ($sourceType) {
        'url' => (string)(parse_url((string)($pending['source_url'] ?? $pending['original_text'] ?? ''), PHP_URL_HOST) ?: 'Your web article'),
        'text' => 'Your pasted text',
        default => 'Your document',
    };
}
$formatLabels = [
    'paragraph' => 'Paragraph',
    'bullets' => 'Bullet Points',
    'hybrid' => 'Hybrid (Overview + Bullets)',
    'structured' => 'Structured Sections',
    'key_takeaways' => 'Key Takeaways',
];
$analysisModeLabels = [
    'general' => 'General',
    'academic' => 'Academic',
    'executive' => 'Executive',
    'technical' => 'Technical',
    'study' => 'Study',
    'news' => 'News',
    'legal' => 'Legal & Policy',
    'scientific' => 'Scientific',
];
$depthLabels = [
    'very_brief' => 'Very Brief',
    'brief' => 'Brief',
    'short' => 'Short',
    'balanced' => 'Balanced',
    'detailed' => 'Detailed',
    'comprehensive' => 'Comprehensive',
];
$outputFormat = (string)($pending['output_format'] ?? 'paragraph');
$analysisMode = (string)($pending['analysis_mode'] ?? 'general');
$summaryDepth = (string)($pending['summary_depth'] ?? 'balanced');
$summarySettings = [
    ['Output format', $formatLabels[$outputFormat] ?? $formatLabels['paragraph']],
    ['Analysis mode', $analysisModeLabels[$analysisMode] ?? $analysisModeLabels['general']],
    ['Summary depth', $depthLabels[$summaryDepth] ?? $depthLabels['balanced']],
];
$focusQuery = trim((string)($pending['focus_query'] ?? ''));
if ($focusQuery !== '') {
    $summarySettings[] = ['Focus', $focusQuery];
}
if (config('summarizer.llm_available', false)) {
    $summarySettings[] = ['AI synthesis', !empty($pending['use_llm_synthesis']) ? 'Enabled' : 'Not selected'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preparing your summary — LIGHT</title>
    <script src="assets/js/theme-toggle.js?v=2"></script>
    <link rel="preload" href="assets/fonts/satoshi-700.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/design-tokens.css?v=<?= filemtime(__DIR__ . '/assets/css/design-tokens.css') ?>">
    <link rel="stylesheet" href="assets/css/theme-toggle.css?v=2">
    <link rel="stylesheet" href="assets/css/processing.css?v=<?= filemtime(__DIR__ . '/assets/css/processing.css') ?>">
    <link rel="stylesheet" href="assets/css/global-button-effects.css?v=4">
    <?php require __DIR__ . '/partials/error-support.php'; ?>
    <script src="assets/js/processing.js?v=<?= filemtime(__DIR__ . '/assets/js/processing.js') ?>" defer></script>
    <noscript><style>.loader, .processing-activity { display: none; }</style></noscript>
</head>
<body class="processing-page">
    <div class="theme-toggle-floating">
        <?php require __DIR__ . '/partials/theme-toggle-button.php'; ?>
    </div>
    <main class="processing-shell" aria-label="Summary processing">
        <div class="processing-brand" aria-label="LIGHT Article summarizer">LIGHT<span>Article summarizer</span></div>
        <section class="processing-card" id="processing-card" data-state="working" data-source-type="<?= htmlspecialchars($sourceType, ENT_QUOTES, 'UTF-8') ?>" data-csrf-token="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>" data-job-id="<?= htmlspecialchars((string)($pending['job_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" aria-label="Summary processing">
            <!-- From Uiverse.io by alexruix -->
            <div class="loader" aria-hidden="true">
                <span class="loader-text">loading</span>
                <span class="load"></span>
            </div>
            <span class="processing-sr-only" id="processing-live" role="status" aria-live="polite">Preparing your summary.</span>
            <div class="processing-source">
                <div class="processing-source__heading">
                    <svg class="processing-source__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M8 12h8m-8 4h5"/></svg>
                    <div>
                        <p class="processing-source__label"><?= htmlspecialchars($sourceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <p class="processing-source__title" title="<?= htmlspecialchars($processingTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($processingTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
            <section class="processing-settings" aria-labelledby="processing-settings-title">
                <h2 class="processing-settings__title" id="processing-settings-title">Summary settings</h2>
                <dl class="processing-settings__list">
                    <?php foreach ($summarySettings as [$label, $value]): ?>
                        <div>
                            <dt><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></dt>
                            <dd><?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
            <div class="processing-activity" id="processing-activity">
                <button type="button" class="processing-cancel-action" id="processing-cancel-button">Cancel</button>
            </div>
            <div class="processing-recovery" id="processing-recovery" hidden>
                <div class="light-error" id="processing-error" role="alert" tabindex="-1" hidden></div>
                <p id="processing-recovery-note" role="status" aria-live="polite"></p>
                <div class="light-recovery-actions"><button type="button" id="processing-retry" hidden>Retry summary</button></div>
                <a href="summarizer.php" class="processing-button global-button-effect" id="processing-return">Back to summary settings<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5"/></svg></a>
            </div>
            <noscript><p class="processing-noscript">JavaScript is needed to start your summary. Enable it, then reload this page, or <a href="summarizer.php">return to summary settings</a>.</p></noscript>
        </section>
    </main>
</body>
</html>
