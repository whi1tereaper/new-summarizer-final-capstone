<?php
require_once __DIR__ . '/../src/whitereaper.php';

// This page only exists between form submission and the final result page.
if (empty($_SESSION['pending_summary'])) {
    header('Location: summarizer.php');
    exit;
}

$pending = $_SESSION['pending_summary'];
$sourceType = (string)($pending['source_type'] ?? 'file');
$extension = strtolower(pathinfo((string)($pending['file_path'] ?? ''), PATHINFO_EXTENSION));
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
$modeLabels = ['general' => 'General', 'academic' => 'Academic', 'executive' => 'Executive', 'technical' => 'Technical', 'study' => 'Study', 'news' => 'News'];
$formatLabels = ['paragraph' => 'Paragraph', 'bullets' => 'Bullet points', 'hybrid' => 'Hybrid', 'structured' => 'Structured sections'];
$depthLabels = ['brief' => 'Brief', 'short' => 'Short', 'balanced' => 'Balanced', 'detailed' => 'Detailed', 'comprehensive' => 'Comprehensive'];
$depthLabel = $depthLabels[(string)($pending['summary_depth'] ?? 'balanced')] ?? 'Balanced';
$modeLabel = $modeLabels[(string)($pending['analysis_mode'] ?? 'general')] ?? 'General';
$formatLabel = $formatLabels[(string)($pending['output_format'] ?? 'paragraph')] ?? 'Paragraph';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preparing your summary — LIGHT</title>
    <link rel="preload" href="assets/fonts/satoshi-700.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/processing.css?v=<?= filemtime(__DIR__ . '/assets/css/processing.css') ?>">
    <link rel="stylesheet" href="assets/css/global-button-effects.css?v=4">
    <script src="assets/js/processing.js?v=<?= filemtime(__DIR__ . '/assets/js/processing.js') ?>" defer></script>
    <noscript><style>.processing-document__scan, .processing-eyebrow, .processing-description, .processing-activity, .processing-track, .processing-help { display: none; }</style></noscript>
</head>
<body class="processing-page">
    <main class="processing-shell" aria-labelledby="processing-title">
        <div class="processing-brand" aria-label="LIGHT document summarizer">LIGHT<span>Document summarizer</span></div>
        <section class="processing-card" id="processing-card" data-state="working" data-source-type="<?= htmlspecialchars($sourceType, ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="processing-title">
            <div class="processing-illustration" aria-hidden="true">
                <div class="processing-document processing-document--back"></div>
                <div class="processing-document processing-document--front">
                    <span class="processing-document__heading"></span>
                    <span class="processing-document__line"></span>
                    <span class="processing-document__line"></span>
                    <span class="processing-document__line processing-document__line--short"></span>
                    <span class="processing-document__scan"></span>
                </div>
                <span class="processing-state-icon processing-state-icon--success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg></span>
                <span class="processing-state-icon processing-state-icon--error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 6v8m0 3v1"/></svg></span>
            </div>
            <div class="processing-message" role="status" aria-live="polite" aria-atomic="true">
                <p class="processing-eyebrow" id="processing-state-label">In progress</p>
                <h1 id="processing-title">Preparing your summary</h1>
                <p class="processing-description" id="processing-status">Turning your document into a focused, readable summary.</p>
            </div>
            <div class="processing-source">
                <div class="processing-source__heading">
                    <svg class="processing-source__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M8 12h8m-8 4h5"/></svg>
                    <div>
                        <p class="processing-source__label"><?= htmlspecialchars($sourceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <p class="processing-source__title" title="<?= htmlspecialchars($processingTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($processingTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    </div>
                </div>
                <dl class="processing-settings">
                    <div><dt>Analysis</dt><dd><?= htmlspecialchars($modeLabel) ?></dd></div>
                    <div><dt>Depth</dt><dd><?= htmlspecialchars($depthLabel) ?></dd></div>
                    <div><dt>Format</dt><dd><?= htmlspecialchars($formatLabel) ?></dd></div>
                </dl>
            </div>
            <div class="processing-activity" id="processing-activity">
                <span class="processing-activity__state"><span class="processing-dot" aria-hidden="true"></span><span id="processing-activity-label">Working on your document</span></span>
                <span class="processing-elapsed">Elapsed <span id="processing-elapsed" role="timer" aria-live="off">0:00</span></span>
            </div>
            <div class="processing-track" id="processing-track" aria-hidden="true"><span></span></div>
            <div class="processing-recovery" id="processing-recovery" hidden>
                <p id="processing-recovery-note"></p>
                <a href="summarizer.php" class="processing-button global-button-effect" id="processing-return">Back to summary settings<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5"/></svg></a>
            </div>
            <a class="processing-result-link" id="processing-result-link" hidden>Open your summary</a>
            <noscript><p class="processing-noscript">JavaScript is needed to start your summary. Enable it, then reload this page, or <a href="summarizer.php">return to summary settings</a>.</p></noscript>
        </section>
        <p class="processing-help" id="processing-help">Keep this tab open. Your summary will appear here when it’s ready.</p>
    </main>
</body>
</html>
