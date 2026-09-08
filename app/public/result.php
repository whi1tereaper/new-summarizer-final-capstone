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
$excludedSections = [];
$summaryFormatLabel = '';

if (!isset($error)) {
    $existingRating = FeedbackHandler::getFeedbackForViewer($id, $userId, $guestToken);
    $existingFeedback = FeedbackHandler::getFullFeedbackForViewer($id, $userId, $guestToken);
    
    if ($existingFeedback) {
        $currentUserName = $existingFeedback['name'] ?? $currentUserName;
        $currentUserEmail = $existingFeedback['email'] ?? $currentUserEmail;
    }
    
    $ratingStats = FeedbackHandler::getSummaryRatingStats($id);
    $summaryStatus = is_array($summaryData) ? ($summaryData['status'] ?? 'completed') : 'completed';
    
    $storedSummaryStyle = $summaryData['summary_style'] ?? 'standard_paragraph';
    if (
        is_string($storedSummaryStyle)
        && in_array(
            $storedSummaryStyle,
            ['standard_paragraph', 'bullet_points', 'hybrid', 'academic_summary', 'simple_summary'],
            true
        )
    ) {
        $summaryRenderStyle = $storedSummaryStyle;
    }
    $summaryFormatMap = [
        'standard_paragraph' => 'Paragraph Summary',
        'bullet_points' => 'Bullet Points',
        'hybrid' => 'Hybrid',
        'academic_summary' => 'Academic Summary',
        'simple_summary' => 'Simple Summary',
    ];
    $summaryFormatLabel = $summaryFormatMap[$summaryRenderStyle] ?? 'Paragraph Summary';
    $storedSummary = (string)($summaryData['generated_summary'] ?? '');
    $plainSummaryText = Formatter::toOverallSummaryTextFromStoredSummary($storedSummary);
    $summaryOverview = Formatter::extractOverviewFromStoredSummary($storedSummary);
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
    $excludedSections = Formatter::extractStringListFieldPublic($storedSummary, 'excluded_sections');
    $userFeedbackHistory = FeedbackHandler::getFeedbackHistoryForViewer($userId, $guestToken, 5);
    $canUseSummaryActions = !in_array($summaryStatus, ['pending', 'processing', 'failed'], true)
        && $plainSummaryText !== '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(is_array($summaryData) ? ($summaryData['article_title'] ?? 'Document Summary') : 'Document Summary'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/result.css">
    <style>
        .feedback-section {
            margin-top: 60px;
            padding: 20px 0;
            font-family: 'Inter', sans-serif;
        }
        .feedback-card {
            max-width: 800px;
            margin: 0 auto;
            padding: 0;
        }
        .feedback-title {
            font-family: 'Merriweather', serif;
            font-size: 1.8em;
            color: var(--color-text);
            margin-bottom: 30px;
            border-bottom: 2px solid #8b5cf6;
            padding-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .feedback-form {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        .feedback-field {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .feedback-label {
            font-weight: 700;
            font-size: 0.85em;
            color: var(--color-text);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .feedback-label span {
            color: #d946ef;
        }
        .feedback-input, .feedback-textarea {
            padding: 15px;
            background: #faf5fa;
            border: none;
            border-bottom: 1px solid transparent;
            border-radius: 0;
            font-size: 1em;
            font-family: inherit;
            transition: all 0.2s;
        }
        .feedback-input:focus, .feedback-textarea:focus {
            outline: none;
            background: #fff;
            border-bottom: 1px solid #8b5cf6;
        }
        .feedback-fieldset {
            border: none;
            padding: 0;
            margin: 0;
        }
        .feedback-options {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 5px;
        }
        .feedback-option {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.85em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: #faf5fa;
            padding: 12px 20px;
            border: 1px solid #e5d8f0;
            border-radius: 0;
            transition: all 0.2s;
        }
        .feedback-option:hover {
            background: #f3e8ff;
        }
        .feedback-option input {
            cursor: pointer;
        }
        .feedback-actions {
            margin-top: 15px;
        }
        .feedback-submit {
            background: #8b5cf6;
            color: #fff;
            border: none;
            padding: 15px 35px;
            font-size: 0.9em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            border-radius: 0;
            transition: opacity 0.2s;
        }
        .feedback-submit:hover {
            opacity: 0.9;
        }
        .flash-msg {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 0;
            font-size: 0.95em;
        }
        .flash-msg--success {
            background: #e6ffed;
            color: #1a7f37;
            border: 1px solid #acf2bd;
        }
        .flash-msg--error {
            background: #ffebe9;
            color: #cf222e;
            border: 1px solid #ff818266;
        }

        @media (max-width: 600px) {
            .feedback-options {
                flex-direction: column;
                gap: 10px;
            }
            .feedback-option {
                width: 100%;
            }
        }

        .feedback-history {
            margin-top: 40px;
            border-top: 1px solid #e5d8f0;
            padding-top: 30px;
        }
        .feedback-history-title {
            font-size: 1.1em;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .history-item {
            background: #fff;
            border-left: 3px solid #8b5cf6;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .history-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 0.85em;
        }
        .history-item-title {
            font-weight: 700;
            color: #4b5563;
        }
        .history-item-rating {
            color: #f59e0b;
            font-weight: 700;
        }
        .history-item-comment {
            font-size: 0.95em;
            color: #6b7280;
            line-height: 1.5;
            font-style: italic;
        }
        .history-item-date {
            display: block;
            margin-top: 10px;
            font-size: 0.75em;
            color: #9ca3af;
        }
    </style>
</head>
<body>

<main
    id="summary-page"
    class="editorial-layout"
    data-summary-id="<?= (int)$id ?>"
    data-summary-text="<?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
    data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
    data-current-rating="<?= $existingRating !== null ? (int)$existingRating : '' ?>"
    data-share-token="<?= htmlspecialchars((string)($summaryData['share_token'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
>
    <nav class="result-nav">
        <a href="summarizer.php" class="result-nav-link">&larr; BACK </a>
    </nav>

    <?php if (isset($error)): ?>
        <div class="error">
            <div class="error-content">
                <h2>NOTIFICATION</h2>
                <p><?php echo htmlspecialchars($error); ?></p>
                <a href="index.php" class="btn btn-secondary">RETURN</a>
            </div>
        </div>
    <?php else: ?>
        <header>
            <h1><?php echo htmlspecialchars($summaryData['article_title']); ?></h1>
            <div class="meta-grid">
                <div class="meta-item">
                    <strong>ARTICLE TITLE / TOPIC</strong>
                    <?php echo htmlspecialchars($summaryData['article_title']); ?>
                </div>
                <div class="meta-item">
                    <strong>DATE</strong>
                    <?php echo date('F d, Y', strtotime($summaryData['created_at'])); ?>
                </div>
                <div class="meta-item">
                    <strong>SOURCE</strong>
                    <?php echo strtoupper(htmlspecialchars($summaryData['input_type'])); ?>
                </div>
                <?php if ($summaryFormatLabel !== ''): ?>
                <div class="meta-item">
                    <strong>SUMMARY FORMAT</strong>
                    <?php echo strtoupper(htmlspecialchars($summaryFormatLabel)); ?>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!in_array($summaryStatus, ['pending', 'processing', 'failed'], true)): ?>

            <div class="section-header">Overview</div>
            <div class="detail-card">
                <?php if ($summaryOverview !== []): ?>
                    <?php foreach ($summaryOverview as $overviewSentence): ?>
                        <p><?php echo htmlspecialchars($overviewSentence); ?></p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?php echo htmlspecialchars($plainSummaryText); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (
            $summaryRenderStyle !== 'bullet_points'
            && $paragraphSummaries !== []
            && !in_array($summaryStatus, ['pending', 'processing', 'failed'], true)
        ): ?>
            <div class="section-header">Paragraph-Based Summary</div>
            <div class="paragraph-summaries-container">
                <?php foreach ($paragraphSummaries as $paraSummary): ?>
                    <div class="paragraph-summary-card">
                        <div class="paragraph-summary-header">
                            <span class="paragraph-number">Paragraph <?= (int)$paraSummary['paragraph_number'] ?></span>
                            <span class="paragraph-purpose-badge"><?= htmlspecialchars($paraSummary['purpose']) ?></span>
                        </div>
                        <div class="paragraph-summary-body">
                            <p><?= htmlspecialchars($paraSummary['summary']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($excludedSections !== []): ?>
                <div class="excluded-sections-note">
                    Excluded from summarization: <?= htmlspecialchars(implode(', ', $excludedSections)) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="section-header">Overall Summary</div>
        <div class="summary-block" id="original-summary">
            <?php if (in_array($summaryStatus, ['pending', 'processing'], true)): ?>
                <div id="processing-state" class="processing-state" data-poll-status="1">
                    <div class="processing-title">Processing Document...</div>
                    <div class="processing-copy">
                        The AI engine is currently analyzing and condensing the source text.
                        This page will refresh automatically.
                    </div>
                </div>
            <?php elseif ($summaryStatus === 'failed'): ?>
                <div class="task-failed">
                    <strong>Task Failed:</strong> <?php echo htmlspecialchars($summaryData['error_message'] ?? 'Unknown processing error.'); ?>
                </div>
            <?php else: ?>
                <?php
                // Render Overall Summary according to the user-selected style.
                // Each branch produces visibly different content and structure.
                // All text output is htmlspecialchars-escaped — no raw NLP content enters the DOM.
                $isBulletStyle  = $summaryRenderStyle === 'bullet_points';
                $isHybridStyle  = $summaryRenderStyle === 'hybrid';

                // Determine what content is available.
                $hasBullets = $overallSummaryBullets !== [];
                $hasPara    = $plainSummaryText !== '';
                ?>

                <?php if ($isBulletStyle && $hasBullets): ?>
                    <ul class="overall-summary-bullets">
                        <?php foreach ($overallSummaryBullets as $bullet): ?>
                            <li><?= htmlspecialchars($bullet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>

                <?php elseif ($isHybridStyle && ($hasPara || $hasBullets)): ?>
                    <?php if ($hasPara): ?>
                        <p class="overall-summary-intro"><?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if ($hasBullets): ?>
                        <p class="overall-summary-supporting-label">Key supporting points:</p>
                        <ul class="overall-summary-bullets">
                            <?php foreach ($overallSummaryBullets as $bullet): ?>
                                <li><?= htmlspecialchars($bullet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                <?php elseif ($hasPara): ?>
                    <p><?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>

                <?php else: ?>
                    <?php
                    // Fallback: older summary stored before this format was introduced.
                    // Use Formatter::toHtml() which escapes content internally.
                    $jsonSummary = json_decode($summaryData['generated_summary'], true);
                    if (is_array($jsonSummary)) {
                        // Pre-escaped HTML — do NOT re-escape.
                        echo Formatter::toHtml($jsonSummary, $summaryRenderStyle);
                    } else {
                        echo nl2br(htmlspecialchars($summaryData['generated_summary']));
                    }
                    ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($summaryKeyPoints !== [] && !in_array($summaryStatus, ['pending', 'processing', 'failed'], true)): ?>
            <div class="section-header">Key Points</div>
            <div class="detail-card">
                <ol>
                    <?php foreach ($summaryKeyPoints as $point): ?>
                        <li><?php echo htmlspecialchars($point); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <?php if ($summaryKeywords !== [] && !in_array($summaryStatus, ['pending', 'processing', 'failed'], true)): ?>
            <div class="section-header">Important Terms</div>
            <div class="detail-card">
                <?php if ($summaryImportantTerms !== []): ?>
                    <dl class="important-terms-list">
                        <?php foreach ($summaryImportantTerms as $termEntry): ?>
                            <?php
                                $term    = htmlspecialchars(trim((string)($termEntry['term'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                $meaning = htmlspecialchars(trim((string)($termEntry['meaning'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                if ($term === '') continue;
                            ?>
                            <div class="important-term-entry">
                                <dt class="important-term-label"><?php echo $term; ?></dt>
                                <?php if ($meaning !== ''): ?>
                                    <dd class="important-term-context"><?php echo $meaning; ?></dd>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
                <?php if ($summaryKeywords !== []): ?>
                    <div class="keywords-section">
                        <p class="keywords-label">Keywords</p>
                        <div class="keywords-tags">
                            <?php foreach ($summaryKeywords as $keyword): ?>
                                <span class="keyword-tag"><?php echo htmlspecialchars($keyword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($summaryConclusion !== '' && !in_array($summaryStatus, ['pending', 'processing', 'failed'], true)): ?>
            <div class="section-header">Conclusion</div>
            <div class="detail-card">
                <p><?php echo htmlspecialchars($summaryConclusion); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($readabilityStats !== [] && !in_array($summaryStatus, ['pending', 'processing', 'failed'], true)): ?>
            <div class="section-header">Readability</div>
            <div class="detail-card readability-stats">
                <div class="readability-grid">
                    <?php if (isset($readabilityStats['original_word_count'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Original Word Count</span>
                            <span class="readability-value"><?= number_format((int)$readabilityStats['original_word_count']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($readabilityStats['summary_word_count'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Summary Word Count</span>
                            <span class="readability-value"><?= number_format((int)$readabilityStats['summary_word_count']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($readabilityStats['compression_percent'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Compression</span>
                            <span class="readability-value"><?= htmlspecialchars((string)$readabilityStats['compression_percent']) ?>%</span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($readabilityStats['estimated_reading_time_minutes'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Reading Time (Summary)</span>
                            <span class="readability-value"><?= (int)$readabilityStats['estimated_reading_time_minutes'] ?> min</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($sourceMetadata !== [] && !in_array($summaryStatus, ['pending', 'processing', 'failed'], true)): ?>
            <div class="section-header">Source Metadata</div>
            <div class="detail-card readability-stats">
                <div class="readability-grid">
                    <?php if (isset($sourceMetadata['source_type'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Source Type</span>
                            <span class="readability-value"><?= strtoupper(htmlspecialchars((string)$sourceMetadata['source_type'])) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($sourceMetadata['article_type'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Detected Type</span>
                            <span class="readability-value"><?= htmlspecialchars((string)$sourceMetadata['article_type']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($sourceMetadata['paragraph_count'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Paragraphs</span>
                            <span class="readability-value"><?= number_format((int)$sourceMetadata['paragraph_count']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($sourceMetadata['candidate_sentence_count'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Candidate Sentences</span>
                            <span class="readability-value"><?= number_format((int)$sourceMetadata['candidate_sentence_count']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($sourceMetadata['scoring_strategy'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Scoring</span>
                            <span class="readability-value"><?= htmlspecialchars(str_replace('_', ' ', (string)$sourceMetadata['scoring_strategy'])) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($sourceMetadata['fallback_used'])): ?>
                        <div class="readability-item">
                            <span class="readability-label">Fallback Used</span>
                            <span class="readability-value"><?= $sourceMetadata['fallback_used'] ? 'Yes' : 'No' ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canUseSummaryActions): ?>
            <div class="summary-tools">
                <?php if ($canUseAudio): ?>
                    <button id="play-audio-btn" type="button" class="inline-action-btn">
                        Play Audio
                    </button>
                <?php else: ?>
                    <a href="login.php" class="inline-action-btn">
                        Login or Create Account
                    </a>
                <?php endif; ?>
                <button id="translate-btn" type="button" class="inline-action-btn">
                    Translate to Filipino
                </button>
            </div>
            <div id="summary-status" class="summary-status" aria-live="polite"></div>

            <div id="audio-panel" class="audio-panel">
                <div class="section-header section-header-compact">Summary Audio</div>
                <audio id="summary-audio-player" controls preload="none"></audio>
            </div>

            <div id="translation-section" class="translation-section is-hidden">
                <div class="section-header">Filipino Translation</div>
                <div class="summary-block translated-summary" id="translated-text"></div>
            </div>
        <?php endif; ?>

        <div id="feedback-widget" class="feedback-widget">
            <div class="feedback-title">
                Rate This Summary
                <?php if ($ratingStats['count'] > 0): ?>
                    <span class="feedback-stats">
                        <?= htmlspecialchars((string)$ratingStats['avg']) ?> avg &middot; <?= (int)$ratingStats['count'] ?> rating<?= $ratingStats['count'] !== 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
            </div>
            <div id="star-row" class="star-row">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <button
                        type="button"
                        class="star-btn<?= ($existingRating !== null && $s <= $existingRating) ? ' is-active' : '' ?>"
                        data-value="<?= $s ?>"
                        aria-label="Rate <?= $s ?> star<?= $s > 1 ? 's' : '' ?>"
                    >&#9733;</button>
                <?php endfor; ?>
            </div>
            <div id="feedback-msg" class="feedback-message"></div>
        </div>

        <div class="section-header">Source Reference</div>
        <div class="source-reference">
            <div class="ref-content"><?php echo htmlspecialchars($summaryData['original_text']); ?></div>
        </div>

        <div class="actions">
            <a href="index.php" class="btn btn-primary">BACK</a>
            <?php if ($userId): ?>
                <a href="history.php" class="btn btn-secondary">HISTORY</a>
            <?php endif; ?>
        </div>

        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="flash-msg flash-msg--success" style="max-width:800px; margin: 20px auto;">
                <?= htmlspecialchars($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="flash-msg flash-msg--error" style="max-width:800px; margin: 20px auto;">
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <section class="feedback-section">
          <div class="feedback-card">
            <h2 class="feedback-title">Feedback</h2>

            <form class="feedback-form" method="POST" action="feedback_submit.php">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="summary_id" value="<?= htmlspecialchars($id ?? '', ENT_QUOTES, 'UTF-8') ?>">

              <div class="feedback-field">
                <label class="feedback-label" for="feedback_name">Name</label>
                <input
                  id="feedback_name"
                  name="name"
                  type="text"
                  class="feedback-input"
                  placeholder="Enter your full name"
                  maxlength="100"
                  value="<?= htmlspecialchars($currentUserName ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
              </div>

              <div class="feedback-field">
                <label class="feedback-label" for="feedback_email">Email <span>*</span></label>
                <input
                  id="feedback_email"
                  name="email"
                  type="email"
                  class="feedback-input"
                  placeholder="Enter your email"
                  maxlength="150"
                  required
                  value="<?= htmlspecialchars($currentUserEmail ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
              </div>

              <fieldset class="feedback-field feedback-fieldset">
                <legend class="feedback-label">What is your overall impression? <span>*</span></legend>

                <div class="feedback-options">
                  <?php 
                  $impressions = [
                      'very_satisfied' => 'Very Satisfied',
                      'satisfied' => 'Satisfied',
                      'unsatisfied' => 'Unsatisfied',
                      'very_unsatisfied' => 'Very Unsatisfied'
                  ];
                  foreach ($impressions as $value => $label): 
                      $checked = ($existingFeedback && ($existingFeedback['impression'] ?? '') === $value) ? 'checked' : '';
                  ?>
                  <label class="feedback-option">
                    <input type="radio" name="impression" value="<?= $value ?>" required <?= $checked ?>>
                    <span><?= $label ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <div class="feedback-field">
                <label class="feedback-label" for="feedback_comment">Comments or suggestions</label>
                <textarea
                  id="feedback_comment"
                  name="comment"
                  class="feedback-textarea"
                  rows="5"
                  maxlength="1000"
                  placeholder="Feel free to add any comments or suggestions"
                ><?= htmlspecialchars($existingFeedback['comment'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
              </div>

              <div class="feedback-actions">
                <button class="feedback-submit" type="submit">Send Feedback</button>
              </div>
            </form>
            <?php if (!empty($userFeedbackHistory)): ?>
            <div class="feedback-history">
              <h3 class="feedback-history-title">Your Previous Feedback</h3>
              <?php foreach ($userFeedbackHistory as $history): ?>
                <?php if ($history['summary_id'] == $id) continue; // Skip current summary ?>
                <div class="history-item">
                  <div class="history-item-header">
                    <span class="history-item-title"><?= htmlspecialchars($history['article_title']) ?></span>
                    <span class="history-item-rating">
                      <?php for($i=1; $i<=5; $i++) echo $i <= $history['rating'] ? '&#9733;' : '&#9734;'; ?>
                    </span>
                  </div>
                  <?php if (!empty($history['comment'])): ?>
                    <p class="history-item-comment">"<?= htmlspecialchars($history['comment']) ?>"</p>
                  <?php else: ?>
                    <p class="history-item-comment" style="color: #9ca3af;">(No comment provided)</p>
                  <?php endif; ?>
                  <span class="history-item-date"><?= date('M d, Y', strtotime($history['created_at'])) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </section>
    <?php endif; ?>
</main>

<script src="assets/js/result.js"></script>

</body>
</html>
