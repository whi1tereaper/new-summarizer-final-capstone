<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/FeedbackHandler.php';
require_once __DIR__ . '/../src/Controllers/HistoryHandler.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Controllers\FeedbackHandler;
use App\Src\Controllers\HistoryHandler;
use App\Src\Utils\Formatter;

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id = (int)$_GET['id'];
$userId = $_SESSION['user_id'] ?? null;
$guestToken = $_SESSION['guest_token'] ?? null;
$shareToken = isset($_GET['share']) && is_string($_GET['share']) ? trim($_GET['share']) : null;

$summaryData = HistoryHandler::getSummaryById($id, $userId, $guestToken, $shareToken);

if (!$summaryData || isset($summaryData['error'])) {
    $error = $summaryData['error'] ?? 'Summary not found.';
}

if (
    !isset($error)
    && is_string($summaryData['share_token'] ?? null)
    && $summaryData['share_token'] !== ''
    && $shareToken !== $summaryData['share_token']
) {
    $redirectUrl = 'result.php?id=' . $id . '&share=' . rawurlencode($summaryData['share_token']);
    header('Location: ' . $redirectUrl);
    exit;
}

$existingRating = null;
$existingFeedback = null;
$ratingStats = ['avg' => null, 'count' => 0];
$summaryComments = [];
$csrfToken = generateCsrfToken();
$currentUserName = $_SESSION['username'] ?? '';
$currentUserEmail = $_SESSION['email'] ?? '';

$summaryStatus = 'completed';
$summaryRenderStyle = 'standard_paragraph';
$plainSummaryText = '';
$canUseSummaryActions = false;
$canUseAudio = $userId !== null || $guestToken !== null;
$summaryOverview = [];
$summaryKeywords = [];
$summaryKeyPoints = [];
$summaryImportantTerms = [];
$summaryImportantTermMeanings = [];
$summaryConclusion = '';
$overallSummaryBullets = [];
$paragraphSummaries = [];
$readabilityStats = [];
$sourceMetadata = [];
$documentAnalysis = [];
$synthesisMetadata = [];
$factValidationMetadata = [];
$retrievalMetadata = [];
$evidenceItems = [];
$coverageItems = [];
$excludedSections = [];
$summaryFormatLabel = '';
$summaryLengthLabel = '';
$summaryDepthLabel = '';
$analysisModeLabel = '';
$summaryOutputHeading = '';
$structuredSummary = [];
$validationPassed = true;
$validationNotes = [];

if (!isset($error)) {
    $existingRating = FeedbackHandler::getFeedbackForViewer($id, $userId, $guestToken);
    $existingFeedback = FeedbackHandler::getFullFeedbackForViewer($id, $userId, $guestToken);

    if ($existingFeedback) {
        $currentUserName = $existingFeedback['name'] ?? $currentUserName;
        $currentUserEmail = $existingFeedback['email'] ?? $currentUserEmail;
    }

    $ratingStats = FeedbackHandler::getSummaryRatingStats($id);
    $summaryComments = FeedbackHandler::getCommentsForSummary($id);
    $summaryStatus = is_array($summaryData) ? ($summaryData['status'] ?? 'completed') : 'completed';

    $storedSummaryStyle = $summaryData['summary_style'] ?? 'standard_paragraph';
    if (
        is_string($storedSummaryStyle)
        && in_array(
            $storedSummaryStyle,
            ['standard_paragraph', 'bullet_points', 'hybrid', 'executive_summary', 'academic_summary', 'simple_summary', 'technical_summary', 'news_summary'],
            true
        )
    ) {
        $summaryRenderStyle = $storedSummaryStyle;
    }
    $summaryFormatMap = [
        'standard_paragraph' => 'Paragraph Summary',
        'bullet_points'      => 'Bullet Points',
        'hybrid'             => 'Hybrid',
        'executive_summary'  => 'Executive Summary',
        'academic_summary'   => 'Academic Summary',
        'simple_summary'     => 'Simple Summary',
        'technical_summary'  => 'Technical Summary',
        'news_summary'       => 'News / Events Summary',
    ];
    $summaryFormatLabel = $summaryFormatMap[$summaryRenderStyle] ?? 'Paragraph Summary';
    $storedOutputFormat = strtolower((string)($summaryData['output_format'] ?? ''));
    $storedSummary = (string)($summaryData['generated_summary'] ?? '');
    $plainSummaryText = Formatter::toOverallSummaryTextFromStoredSummary($storedSummary);
    $summaryOverview = Formatter::extractOverviewFromStoredSummary($storedSummary);
    $profileData = Formatter::extractProfileDataFromStoredSummary($storedSummary);
    $storedOutputFormat = strtolower((string)($profileData['output_format'] ?? $storedOutputFormat));
    $summaryFormatLabel = [
        'paragraph' => 'Paragraph Summary',
        'bullets' => 'Bullet Points',
        'hybrid' => 'Hybrid',
        'structured' => 'Structured Sections',
    ][$storedOutputFormat] ?? $summaryFormatLabel;
    if (in_array($storedOutputFormat, ['paragraph', 'bullets', 'hybrid', 'structured'], true)) {
        $summaryRenderStyle = [
            'paragraph' => 'standard_paragraph',
            'bullets' => 'bullet_points',
            'hybrid' => 'hybrid',
            'structured' => 'structured',
        ][$storedOutputFormat];
    }
    $summaryOutputHeading = $summaryRenderStyle === 'structured'
        ? 'Structured Summary'
        : ($summaryRenderStyle === 'executive_summary'
        ? 'Executive Summary'
        : ($summaryRenderStyle === 'bullet_points' ? 'Key Points Summary' : 'Overall Summary'));
    $profileLabel = $profileData['profile_label'] ?? '';
    $analysisModeLabels = [
        'general' => 'General',
        'academic' => 'Academic / Research',
        'executive' => 'Executive',
        'study' => 'Study / Learning',
        'technical' => 'Technical',
        'news' => 'News / Events',
    ];
    $analysisModeLabel = $analysisModeLabels[strtolower((string)($profileData['analysis_mode'] ?? ''))] ?? $profileLabel;
    $validationPassed = $profileData['validation_passed'] ?? true;
    $validationNotes = is_array($profileData['validation_notes'] ?? null) ? $profileData['validation_notes'] : [];
    $structuredSummary = $profileData['structured_summary'] ?? [];
    $articleType = $profileData['article_type'] ?? ($summaryData['article_category'] ?? '');
    $summaryKeywords = Formatter::extractKeywordsFromStoredSummary($storedSummary);
    $summaryKeyPoints = Formatter::extractKeyPointsFromStoredSummary($storedSummary);
    $summaryImportantTerms = Formatter::extractImportantTermsFromStoredSummary($storedSummary);
    foreach ($summaryImportantTerms as $termEntry) {
        $meaning = trim((string)($termEntry['meaning'] ?? ''));
        if ($meaning !== '' && !in_array($meaning, $summaryImportantTermMeanings, true)) {
            $summaryImportantTermMeanings[] = $meaning;
        }
    }
    $summaryConclusion = Formatter::extractConclusionFromStoredSummary($storedSummary);
    $overallSummaryBullets = Formatter::extractOverallSummaryBulletsFromStoredSummary($storedSummary);
    $paragraphSummaries = Formatter::extractParagraphSummariesFromStoredSummary($storedSummary);
    $readabilityStats = Formatter::extractReadabilityFromStoredSummary($storedSummary);
    $sourceMetadata = Formatter::extractSourceMetadataFromStoredSummary($storedSummary);
    $documentAnalysis = is_array($sourceMetadata['document_analysis'] ?? null)
        ? $sourceMetadata['document_analysis']
        : [];
    $synthesisMetadata = is_array($sourceMetadata['synthesis'] ?? null) ? $sourceMetadata['synthesis'] : [];
    $factValidationMetadata = is_array($sourceMetadata['fact_validation'] ?? null) ? $sourceMetadata['fact_validation'] : [];
    if (($factValidationMetadata['status'] ?? '') === 'checked_with_warnings') {
        $factIssueCount = count($factValidationMetadata['issues'] ?? []);
        $flaggedSentenceNumbers = array_values(array_unique(array_map(
            static fn(array $issue): int => (int)($issue['summary_sentence_id'] ?? -1) + 1,
            array_filter($factValidationMetadata['issues'] ?? [], static fn($issue): bool => is_array($issue) && isset($issue['summary_sentence_id'])
        ))));
        $sentenceNote = $flaggedSentenceNumbers !== []
            ? ' Summary sentence(s): ' . implode(', ', $flaggedSentenceNumbers) . '.'
            : '';
        $validationNotes[] = 'Literal fact-marker checks raised ' . $factIssueCount . ' heuristic warning(s).' . $sentenceNote . ' These checks do not measure factual accuracy.';
    }
$validValidationNotes = array_values(array_filter(
    $validationNotes,
    static fn($note): bool => is_string($note) && trim($note) !== ''
));
$visibleValidationNotes = array_values(array_filter(
    $validValidationNotes,
    static fn(string $note): bool => stripos($note, 'Faithfulness warning: sentence start not found in source:') !== 0
));
// Hide the enclosing warning panel too when that noisy prefix check was its only content.
$showValidationNotice = $visibleValidationNotes !== [] || (!$validationPassed && $validValidationNotes === []);
    $retrievalMetadata = Formatter::extractRetrievalFromStoredSummary($storedSummary);
    $evidenceItems = Formatter::extractEvidenceFromStoredSummary($storedSummary);
    $coverageItems = Formatter::extractCoverageFromStoredSummary($storedSummary);
    $storedSummaryLength = $summaryData['summary_depth']
        ?? ($sourceMetadata['summary_depth'] ?? ($summaryData['summary_length'] ?? ($sourceMetadata['summary_length'] ?? '')));
    $summaryLengthLabels = [
        'brief'         => 'Brief',
        'short'         => 'Short',
        'balanced'      => 'Balanced',
        'detailed'      => 'Detailed',
        'comprehensive' => 'Comprehensive',
    ];
    $summaryLengthLabel = $summaryLengthLabels[strtolower((string)$storedSummaryLength)] ?? '';
    $summaryDepthLabel = $summaryLengthLabel;
    $excludedSections = Formatter::extractStringListFieldPublic($storedSummary, 'excluded_sections');
    $userFeedbackHistory = FeedbackHandler::getFeedbackHistoryForViewer($userId, $guestToken, 5);
    $canUseSummaryActions = !in_array($summaryStatus, ['pending', 'processing', 'failed'], true)
        && $plainSummaryText !== '';

    // Fix slot mapping: ensure Purpose doesn't mirror Conclusion, and Conclusion isn't a Key Takeaway
    if ($structuredSummary !== []) {
        $correctedStructured = [];
        foreach ($structuredSummary as $block) {
            $bLabel = trim((string)($block['label'] ?? ''));
            $bText  = trim((string)($block['text'] ?? ''));
            if ($bLabel === '' || $bText === '') continue;

            if (strcasecmp($bLabel, 'Purpose') === 0 && $summaryConclusion !== '' && $bText === $summaryConclusion) {
                $candidatePurpose = !empty($summaryOverview[0]) ? $summaryOverview[0] : (!empty($paragraphSummaries[0]['summary']) ? $paragraphSummaries[0]['summary'] : '');
                if ($candidatePurpose !== '' && $candidatePurpose !== $summaryConclusion) {
                    $bText = $candidatePurpose;
                }
            }

            if (strcasecmp($bLabel, 'Conclusion') === 0 && $summaryConclusion !== '' && $bText !== $summaryConclusion) {
                $bText = $summaryConclusion;
            }

            $correctedStructured[] = [
                'label' => $bLabel,
                'text'  => $bText,
            ];
        }
        $structuredSummary = $correctedStructured;
    }
}

$displayArticleTitle = trim((string)($summaryData['article_title'] ?? 'Document Summary'));
$sourceHeading = trim((string)($sourceMetadata['title'] ?? ''));
if (
    $displayArticleTitle !== ''
    && $sourceHeading !== ''
    && mb_strlen($sourceHeading, 'UTF-8') > mb_strlen($displayArticleTitle, 'UTF-8')
    && mb_stripos($sourceHeading, $displayArticleTitle, 0, 'UTF-8') === 0
) {
    $remainingHeading = mb_substr($sourceHeading, mb_strlen($displayArticleTitle, 'UTF-8'), null, 'UTF-8');
    $endsAtCutWord = preg_match('/\b(?:of|and|the|for|in|to|with|by|on|at|from|about)$/iu', $displayArticleTitle) === 1;
    if ($endsAtCutWord && preg_match('/^\s/u', $remainingHeading) === 1) {
        // Older summaries stored a 12-word title fragment while retaining the full heading here.
        $displayArticleTitle = $sourceHeading;
    }
}
$documentTitle = isset($error) ? 'Error' : htmlspecialchars($displayArticleTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$hasAnalysis = (($structuredSummary !== [] && $summaryRenderStyle !== 'structured') || $paragraphSummaries !== []);
$hasTerms = ($summaryImportantTerms !== [] || $summaryKeywords !== []);
$hasSource = !isset($error) && ($summaryData['original_text'] ?? '') !== '';
$hasReadability = isset($readabilityStats['compression_percent']) || isset($readabilityStats['estimated_reading_time_minutes']);
$hasEvidence = $evidenceItems !== [];
$hasCoverage = $coverageItems !== [];
$takeaways = $summaryKeyPoints !== [] ? $summaryKeyPoints : ($overallSummaryBullets !== [] && $summaryRenderStyle !== 'bullet_points' ? $overallSummaryBullets : []);
$hasContext = $takeaways !== [] || $summaryConclusion !== '' || $hasAnalysis || $summaryKeywords !== [];
$hasSourceDetails = $hasSource || $hasEvidence || $hasCoverage;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $documentTitle ?> - Summary</title>
    <meta name="description" content="AI-generated document analysis and summary.">
    <link rel="preload" href="assets/fonts/satoshi-900.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/satoshi-700.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Outfit:wght@600;700;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/result.css?v=<?= filemtime(__DIR__ . '/assets/css/result.css') ?>">
    <link rel="stylesheet" href="assets/css/global-button-effects.css?v=4">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
</head>
<body class="result-page-body">

<div class="result-shell">

    <!-- ═══════════════════════════════════════
         RETURN TO WORKSPACE
    ═══════════════════════════════════════ -->
    <div class="result-sticky-header">
        <header class="result-topbar" role="banner">
            <div class="result-topbar__left">
                <a href="summarizer.php" class="result-back-btn global-button-effect" aria-label="Back to summarizer">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                    </svg>
                    <span>New summary</span>
                </a>
                <span class="result-topbar__sep" aria-hidden="true"></span>
                <span class="result-topbar__label">Summary</span>
            </div>
        </header>


    </div>

    <!-- ═══════════════════════════════════════
         MAIN CONTENT - SINGLE CONTINUOUS PAGE
    ═══════════════════════════════════════ -->
    <main class="result-content"
          id="summary-page"
          data-summary-id="<?= (int)$id ?>"
          data-summary-text="<?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          data-current-rating="<?= $existingRating !== null ? (int)$existingRating : '' ?>"
          data-share-token="<?= htmlspecialchars((string)($summaryData['share_token'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <?php if (isset($error)): ?>
        <!-- ── Error state ─────────────────────────────── -->
        <div class="result-error-state">
            <div class="result-error-box">
                <p class="result-error-eyebrow">Unable to load</p>
                <h1 class="result-error-title">Something went wrong</h1>
                <p class="result-error-msg"><?= htmlspecialchars($error) ?></p>
                <a href="index.php" class="result-error-link">Return to home</a>
            </div>
        </div>

        <?php else: ?>

        <!-- ── Document Header ────────────────────────── -->
        <section class="result-doc-header" aria-labelledby="doc-title">
            <p class="result-doc-eyebrow">Your summary</p>
            <h1 id="doc-title" class="result-doc-title">
                <?= htmlspecialchars($displayArticleTitle !== '' ? $displayArticleTitle : 'Document Summary', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </h1>
            <ul class="result-summary-meta" aria-label="Summary at a glance">
                <?php if ($summaryDepthLabel !== ''): ?><li><?= htmlspecialchars($summaryDepthLabel) ?></li><?php endif; ?>
                <li><?= htmlspecialchars($summaryFormatLabel) ?></li>
                <?php if (isset($readabilityStats['estimated_reading_time_minutes']) && $canUseSummaryActions): ?>
                <li><?= max(1, (int)$readabilityStats['estimated_reading_time_minutes']) ?> min read</li>
                <?php endif; ?>
                <?php if ($summaryStatus === 'completed'): ?><li><a href="#result-details">Details</a></li><?php endif; ?>
            </ul>
        </section>

        <?php if ($summaryStatus === 'pending' || $summaryStatus === 'processing'): ?>
        <!-- Processing state -->
        <div class="result-processing" data-poll-status="1">
            <div class="result-processing__spinner" aria-hidden="true"></div>
            <p class="result-processing__title">Analyzing document…</p>
            <p class="result-processing__sub">This can take longer when evidence-controlled AI synthesis is selected.</p>
        </div>

        <?php elseif ($summaryStatus === 'failed'): ?>
        <div class="result-failed-state">
            <p>Analysis failed. Please try re-submitting the document.</p>
        </div>

        <?php else: ?>

        <!-- ══════════════════════════════════════════════
             OVERVIEW SECTION
        ══════════════════════════════════════════════ -->
        <div class="result-reading">
        <?php if ($showValidationNotice): ?>
        <aside class="result-validation-notice" role="note" aria-label="Summary validation notes">
            <strong><?= $validationPassed ? 'Review notes' : 'Validation warning' ?></strong>
            <?php if ($visibleValidationNotes !== []): ?>
            <ul><?php foreach ($visibleValidationNotes as $note): ?><li><?= htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endforeach; ?></ul>
            <?php else: ?>
            <p>The summary did not pass all automated validation checks. Review it against the source before relying on it.</p>
            <?php endif; ?>
        </aside>
        <?php endif; ?>
        <?php if (($documentAnalysis['research_stage'] ?? '') === 'proposal' && empty($documentAnalysis['results_available'])): ?>
        <aside class="result-validation-notice" role="note" aria-label="Research stage">
            <strong>Proposal stage detected</strong>
            <p>The document describes proposed research and does not provide empirical findings in its source text.</p>
        </aside>
        <?php endif; ?>

        <section class="result-section result-section--overview" aria-labelledby="section-overview-heading">
            <div class="result-section__header">
                <h2 class="result-section__heading" id="section-overview-heading">
                    <?= htmlspecialchars($summaryOutputHeading) ?>
                </h2>
                <?php if ($hasSourceDetails): ?>
                <a href="#result-sources" class="result-source-link">Check source</a>
                <?php endif; ?>
            </div>

            <?php if ($canUseSummaryActions): ?>
            <div class="result-summary-actions" role="group" aria-label="Summary actions">
                <button id="copy-summary-btn" class="result-tool-btn result-tool-btn--primary global-button-effect" type="button" aria-label="Copy summary to clipboard">
                            <span class="result-tool-btn__icon" aria-hidden="true">
                                <svg class="result-tool-btn__icon-copy" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <svg class="result-tool-btn__icon-check" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                            <span class="result-tool-btn__text">Copy Summary</span>
                        </button>
                <button id="export-pdf-btn" class="result-tool-btn result-tool-btn--secondary global-button-effect" type="button" aria-label="Export summary as PDF">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            <span>Save PDF</span>
                        </button>
                <?php if ($canUseAudio): ?>
                <button id="play-audio-btn" class="result-tool-btn global-button-effect" type="button" aria-controls="audio-panel" aria-expanded="false">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        <span class="result-tool-btn__text">Listen</span>
                    </button>
                <?php endif; ?>
                <button id="translate-btn" class="result-tool-btn global-button-effect" type="button" aria-controls="translation-section" aria-expanded="false">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 8l6 6"/><path d="M4 14l6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/></svg>
                        <span class="result-tool-btn__text">Translate to Filipino</span>
                    </button>
            </div>
            <?php endif; ?>
            <p id="summary-status" class="result-status-line" role="status" aria-live="polite"></p>
            <div id="audio-panel" class="result-audio-panel hidden">
                <audio id="summary-audio-player" controls preload="none" class="result-audio-player" aria-label="Summary audio player"></audio>
            </div>
            <div class="result-prose" id="original-summary">
                <?php
                $isBulletStyle = $summaryRenderStyle === 'bullet_points';
                $isHybridStyle = in_array($summaryRenderStyle, ['hybrid', 'executive_summary'], true);
                $isStructuredStyle = $summaryRenderStyle === 'structured';
                $hasBullets    = $overallSummaryBullets !== [];
                $hasPara       = $plainSummaryText !== '';
                $hasOverview   = $summaryOverview !== [];
                ?>
                <?php if ($isStructuredStyle && $structuredSummary !== []): ?>
                    <div class="result-accordion">
                        <?php foreach ($structuredSummary as $index => $block):
                            $blockLabel = trim((string)($block['label'] ?? ''));
                            $blockText = trim((string)($block['text'] ?? ''));
                            if ($blockLabel === '' || $blockText === '') continue;
                        ?>
                        <article class="result-accordion__item">
                            <div class="result-accordion__summary">
                                <span class="result-accordion__index" aria-hidden="true"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="result-accordion__label"><?= htmlspecialchars($blockLabel) ?></span>
                            </div>
                            <div class="result-accordion__body">
                                <p><?= htmlspecialchars($blockText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($isBulletStyle && $hasBullets): ?>
                    <ul class="result-prose__bullets">
                        <?php foreach ($overallSummaryBullets as $bullet): ?>
                            <li><?= htmlspecialchars($bullet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($isHybridStyle && ($hasPara || $hasBullets)): ?>
                    <?php if ($hasPara): ?>
                        <p><?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if ($hasBullets): ?>
                        <p class="result-prose__label"><?= $summaryRenderStyle === 'executive_summary' ? 'Key decisions and supporting points:' : 'Key supporting points:' ?></p>
                        <ul class="result-prose__bullets">
                            <?php foreach ($overallSummaryBullets as $bullet): ?>
                                <li><?= htmlspecialchars($bullet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php elseif ($hasPara): ?>
                    <p><?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php elseif ($hasOverview): ?>
                    <?php foreach ($summaryOverview as $sentence): ?>
                        <p><?= htmlspecialchars($sentence) ?></p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php
                    $jsonSummary = json_decode($summaryData['generated_summary'], true);
                    if (is_array($jsonSummary)) {
                        echo Formatter::toHtml($jsonSummary, $summaryRenderStyle);
                    } else {
                        echo nl2br(htmlspecialchars($summaryData['generated_summary']));
                    }
                    ?>
                <?php endif; ?>
            </div>
            <div id="translation-section" class="result-translation is-hidden" aria-live="polite">
                <h3 class="result-translation__label">Filipino translation</h3>
                <div id="translated-text" class="result-translation__body"></div>
            </div>
        </section>
        </div>

        <div class="result-explore" aria-label="More about this summary">
        <?php if ($hasContext): ?>
        <details class="result-disclosure" id="result-context">
            <summary class="result-disclosure__toggle">
                <span><span class="result-disclosure__title">Key points &amp; context</span><span class="result-disclosure__hint">Takeaways, section breakdown, and keywords</span></span>
                <svg class="result-disclosure__icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
            </summary>
            <div class="result-disclosure__body">
        <?php if ($takeaways !== []): ?>
        <section class="result-section" aria-labelledby="section-takeaways-heading">
            <div class="result-section__header">
                <h2 class="result-section__heading" id="section-takeaways-heading">Key Takeaways</h2>
            </div>
            <ol class="result-takeaways" aria-label="Key takeaways">
                <?php foreach ($takeaways as $index => $point): ?>
                <li class="result-takeaway">
                    <span class="result-takeaway__num" aria-hidden="true"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></span>
                    <p class="result-takeaway__text"><?= htmlspecialchars($point) ?></p>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php endif; ?>

        <?php if ($summaryConclusion !== ''): ?>
        <section class="result-section result-section--conclusion" aria-labelledby="section-conclusion-heading">
            <div class="result-section__header">
                <h2 class="result-section__heading" id="section-conclusion-heading">Conclusion</h2>
            </div>
            <div class="result-conclusion">
                <p><?= htmlspecialchars($summaryConclusion) ?></p>
            </div>
        </section>
        <?php endif; ?>
        <?php if ($hasAnalysis || $hasTerms): ?>

        <!-- Section Breakdown -->
        <?php if ($hasAnalysis): ?>
        <section class="result-section" aria-labelledby="section-breakdown-heading">
            <div class="result-section__header">
                <h2 class="result-section__heading" id="section-breakdown-heading">Section Analysis</h2>
            </div>

            <?php if ($structuredSummary !== [] && $summaryRenderStyle !== 'structured'): ?>
                <div class="result-accordion">
                    <?php foreach ($structuredSummary as $index => $block):
                        $blockLabel = trim((string)($block['label'] ?? ''));
                        $blockText  = trim((string)($block['text'] ?? ''));
                        if ($blockLabel === '' || $blockText === '') continue;
                    ?>
                    <article class="result-accordion__item">
                        <div class="result-accordion__summary">
                            <span class="result-accordion__index" aria-hidden="true"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="result-accordion__label"><?= htmlspecialchars($blockLabel) ?></span>
                        </div>
                        <div class="result-accordion__body">
                            <p><?= htmlspecialchars($blockText) ?></p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($paragraphSummaries !== []): ?>
                <div class="result-accordion">
                    <?php foreach ($paragraphSummaries as $index => $paraSummary): ?>
                    <article class="result-accordion__item">
                        <div class="result-accordion__summary">
                            <span class="result-accordion__index" aria-hidden="true"><?= str_pad((int)$paraSummary['paragraph_number'], 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="result-accordion__label"><?= htmlspecialchars($paraSummary['purpose']) ?></span>
                        </div>
                        <div class="result-accordion__body">
                            <p><?= htmlspecialchars($paraSummary['summary']) ?></p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="result-empty-note">No structured breakdown is available for this document.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- Keywords / Tags -->
        <?php if ($summaryKeywords !== []): ?>
        <section class="result-section" aria-labelledby="section-keywords-heading">
            <div class="result-section__header">
                <h2 class="result-section__heading" id="section-keywords-heading">Keywords</h2>
            </div>
            <div class="result-tags" role="list" aria-label="Document keywords">
                <?php foreach ($summaryKeywords as $keyword): ?>
                <span class="result-tag" role="listitem"><?= htmlspecialchars($keyword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php endif; ?>


            </div>
        </details>
        <?php endif; ?>
        <?php if ($hasSourceDetails): ?>
        <details class="result-disclosure" id="result-sources">
            <summary class="result-disclosure__toggle">
                <span><span class="result-disclosure__title">Source &amp; supporting passages</span><span class="result-disclosure__hint">Review the original text and supporting material</span></span>
                <svg class="result-disclosure__icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
            </summary>
            <div class="result-disclosure__body">
        <?php if ($hasEvidence): ?>
        <section class="result-section result-section--evidence" aria-labelledby="section-evidence-heading">
            <div class="result-section__header">
                <h2 class="result-section__heading" id="section-evidence-heading">Supporting passages</h2>
            </div>
            <p class="result-section__desc">Supporting passages from the source document for the summary above.</p>
            <div class="result-evidence-list">
                <?php foreach ($evidenceItems as $index => $evidence):
                    $section = trim((string)($evidence['section'] ?? 'Source'));
                    $sectionLabel = strcasecmp($section, 'body') === 0 ? 'Source document' : $section;
                    $page = trim((string)($evidence['page'] ?? ''));
                    $excerpt = trim((string)($evidence['excerpt'] ?? ''));
                    $supports = trim((string)($evidence['supports'] ?? $evidence['summary_sentence'] ?? ''));
                    $title = $supports !== '' ? $supports : trim((string)($evidence['source_sentence'] ?? ''));
                    if ($section === '' || $excerpt === '') continue;
                    $evidenceId = 'result-evidence-' . ($index + 1);
                ?>
                <details class="result-evidence-item">
                    <summary class="result-evidence-item__summary">
                        <span class="result-evidence-item__index" aria-hidden="true"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></span>
                        <span class="result-evidence-item__source">
                            <span class="result-evidence-item__title"><?= htmlspecialchars($title !== '' ? $title : $sectionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span class="result-evidence-item__context">
                                <?= htmlspecialchars($sectionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                <?php if ($page !== ''): ?>
                                    <span class="result-evidence-item__page"> · Page <?= htmlspecialchars($page, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="result-evidence-item__action">View supporting passage</span>
                    </summary>
                    <div class="result-evidence-item__body" id="<?= htmlspecialchars($evidenceId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?php if ($supports !== ''): ?>
                        <p class="result-evidence-item__label">Supports</p>
                        <p class="result-evidence-item__supports"><?= htmlspecialchars($supports, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <p class="result-evidence-item__label">Supporting passage</p>
                        <blockquote><?= htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></blockquote>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($hasCoverage): ?>
        <section class="result-section result-section--coverage" aria-labelledby="section-coverage-heading">
            <div class="result-section__header">
                <h2 class="result-section__heading" id="section-coverage-heading">Document Coverage</h2>
            </div>
            <p class="result-section__desc">Document areas represented in the source-grounded summary.</p>
            <ul class="result-coverage-list">
                <?php foreach ($coverageItems as $coverage):
                    $coverageSection = trim((string)($coverage['section'] ?? ''));
                    if ($coverageSection === '') continue;
                ?>
                <li class="result-coverage-item">
                    <span class="result-coverage-item__mark" aria-hidden="true">—</span>
                    <span><?= htmlspecialchars($coverageSection, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php if (isset($coverage['evidence_count']) && is_numeric($coverage['evidence_count'])): ?>
                    <span class="result-coverage-item__count"><?= (int)$coverage['evidence_count'] ?> passage<?= (int)$coverage['evidence_count'] === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if ($hasSource): ?>
        <section class="result-section" aria-labelledby="section-source-heading">
            <div class="result-section__header">
                <h2 class="result-section__heading" id="section-source-heading">Original Source Text</h2>
            </div>
            <p class="result-section__desc">The original content provided for analysis. Use this to verify summary accuracy.</p>


            <details class="result-source-disclosure">
                <summary class="result-source-toggle global-button-effect">
                    <span class="result-source-toggle__text">Show full source text</span>
                    <svg class="result-source-toggle__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </summary>
                <div class="result-source-viewer result-source-viewer--expanded">
                    <?= nl2br(htmlspecialchars($summaryData['original_text'] ?? '')) ?>
                </div>
            </details>
        </section>
        <?php endif; ?>


            </div>
        </details>
        <?php endif; ?>
        <details class="result-disclosure" id="result-details">
            <summary class="result-disclosure__toggle">
                <span><span class="result-disclosure__title">Summary details</span><span class="result-disclosure__hint">Format, settings, and document information</span></span>
                <svg class="result-disclosure__icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
            </summary>
            <div class="result-disclosure__body">
            <dl class="result-meta-strip">
                <div class="result-meta-item">
                    <dt>Processed</dt>
                    <dd><?= date('M d, Y', strtotime($summaryData['created_at'])) ?></dd>
                </div>
                <div class="result-meta-item">
                    <dt>Document type</dt>
                    <dd><?= htmlspecialchars($articleType !== '' ? $articleType : 'Document') ?></dd>
                </div>
                <div class="result-meta-item">
                    <dt>Format</dt>
                    <dd><?= htmlspecialchars($summaryFormatLabel) ?></dd>
                </div>
                <?php if (isset($readabilityStats['estimated_reading_time_minutes'])): ?>
                <div class="result-meta-item">
                    <dt>Read time</dt>
                    <dd><?= (int)$readabilityStats['estimated_reading_time_minutes'] ?> min</dd>
                </div>
                <?php endif; ?>
                <?php if (isset($readabilityStats['compression_percent'])): ?>
                <div class="result-meta-item">
                    <dt>Shorter than source</dt>
                    <dd>
                        <?= htmlspecialchars((string)$readabilityStats['compression_percent']) ?>%
                    </dd>
                </div>
                <?php endif; ?>
                <?php if (!empty($sourceMetadata['source_type'])): ?>
                <div class="result-meta-item">
                    <dt>Source</dt>
                    <dd><?= htmlspecialchars((string)$sourceMetadata['source_type']) ?></dd>
                </div>
                <?php endif; ?>
                <?php if (isset($sourceMetadata['paragraph_count'])): ?>
                <div class="result-meta-item">
                    <dt>Source paragraphs</dt>
                    <dd><?= number_format((int)$sourceMetadata['paragraph_count']) ?></dd>
                </div>
                <?php endif; ?>
                <?php if ($summaryLengthLabel !== ''): ?>
                <div class="result-meta-item">
                    <dt>Depth</dt>
                    <dd><?= htmlspecialchars($summaryDepthLabel) ?></dd>
                </div>
                <?php endif; ?>
                <?php if ($analysisModeLabel !== ''): ?>
                <div class="result-meta-item">
                    <dt>Analysis mode</dt>
                    <dd><?= htmlspecialchars($analysisModeLabel) ?></dd>
                </div>
                <?php endif; ?>
            </dl>
            <?php if (($synthesisMetadata['status'] ?? '') === 'accepted_heuristic_checks'): ?>
            <p class="result-grounding-status result-synthesis-status" role="status">
                <span class="result-grounding-status__dot" aria-hidden="true"></span>
                AI-assisted summary
                <span class="result-grounding-status__detail">
                    Reworded from selected source passages. Automated checks do not establish factual accuracy.
                </span>
            </p>
            <?php elseif (!empty($synthesisMetadata['requested'])): ?>
            <p class="result-grounding-status result-synthesis-status" role="status">
                <span class="result-grounding-status__dot" aria-hidden="true"></span>
                Selected source sentences
                <span class="result-grounding-status__detail">AI rewriting was unavailable or did not pass the automated checks, so source sentences were used.</span>
            </p>
            <?php endif; ?>

            </div>
        </details>
        </div>

        <?php require __DIR__ . '/partials/rating-widget.php'; ?>

        <?php endif; /* end completed status */ ?>


        <?php endif; /* end !isset($error) */ ?>
    </main>

</div><!-- /result-shell -->

<script src="assets/js/result.js?v=<?= filemtime(__DIR__ . '/assets/js/result.js') ?>"></script>
<?php require __DIR__ . '/partials/nex-footer.php'; ?>
</body>
</html>
