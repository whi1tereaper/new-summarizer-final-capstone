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
$summaryLengthLabel = '';
$summaryOutputHeading = '';

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
            ['standard_paragraph', 'bullet_points', 'hybrid', 'executive_summary', 'academic_summary', 'simple_summary', 'technical_summary', 'news_summary'],
            true
        )
    ) {
        $summaryRenderStyle = $storedSummaryStyle;
    }
    $summaryFormatMap = [
        'standard_paragraph' => 'Paragraph Summary',
        'bullet_points' => 'Bullet Points',
        'hybrid' => 'Hybrid',
        'executive_summary' => 'Executive Summary',
        'academic_summary' => 'Academic Summary',
        'simple_summary' => 'Simple Summary',
        'technical_summary' => 'Technical Summary',
        'news_summary' => 'News / Events Summary',
    ];
    $summaryFormatLabel = $summaryFormatMap[$summaryRenderStyle] ?? 'Paragraph Summary';
    $summaryOutputHeading = $summaryRenderStyle === 'executive_summary'
        ? 'Executive Summary'
        : ($summaryRenderStyle === 'bullet_points' ? 'Key Points Summary' : 'Overall Summary');
    $storedSummary = (string)($summaryData['generated_summary'] ?? '');
    $plainSummaryText = Formatter::toOverallSummaryTextFromStoredSummary($storedSummary);
    $summaryOverview = Formatter::extractOverviewFromStoredSummary($storedSummary);
    $profileData = Formatter::extractProfileDataFromStoredSummary($storedSummary);
    $profileLabel = $profileData['profile_label'] ?? '';
    $validationPassed = $profileData['validation_passed'] ?? true;
    $validationNotes = $profileData['validation_notes'] ?? [];
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
    $storedSummaryLength = $summaryData['summary_length'] ?? ($sourceMetadata['summary_length'] ?? '');
    $summaryLengthLabels = [
        'brief' => 'Brief',
        'short' => 'Short',
        'balanced' => 'Balanced',
        'detailed' => 'Detailed',
        'comprehensive' => 'Comprehensive',
    ];
    $summaryLengthLabel = $summaryLengthLabels[strtolower((string)$storedSummaryLength)] ?? '';
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
    <title><?= htmlspecialchars(is_array($summaryData) ? ($summaryData['article_title'] ?? 'Document Summary') : 'Document Summary'); ?></title>
    <!-- Tailwind via Play CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased" x-data="{ tab: 'executive' }">

<div class="min-h-screen flex flex-col">
    <!-- Top Header & Action Bar -->
    <header class="sticky top-0 z-50 bg-white border-b border-slate-200 shadow-sm px-6 py-4 flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <a href="summarizer.php" class="text-slate-500 hover:text-slate-800 font-semibold transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                </svg>
                BACK
            </a>
            <div class="h-6 w-px bg-slate-300"></div>
            <h1 class="text-lg font-bold text-slate-900 line-clamp-1">
                <?= isset($error) ? 'Error' : htmlspecialchars($summaryData['article_title'] ?? 'Document Summary'); ?>
            </h1>
        </div>
        
        <?php if (!isset($error) && $canUseSummaryActions): ?>
        <div class="flex items-center space-x-3">
            <button id="copy-summary-btn" class="px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 font-semibold text-sm transition-colors shadow-sm flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 012 2v2z" /></svg>
                Copy
            </button>
            <button id="export-pdf-btn" class="px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 font-semibold text-sm transition-colors shadow-sm flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                PDF
            </button>
            <?php if ($canUseAudio): ?>
            <button id="play-audio-btn" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-semibold text-sm transition-colors shadow-sm flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" /></svg>
                Listen
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </header>

    <main class="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full"
          id="summary-page"
          data-summary-id="<?= (int)$id ?>"
          data-summary-text="<?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          data-current-rating="<?= $existingRating !== null ? (int)$existingRating : '' ?>"
          data-share-token="<?= htmlspecialchars((string)($summaryData['share_token'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
    >
        <?php if (isset($error)): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-6 shadow-sm max-w-2xl mx-auto mt-12 text-center">
                <h2 class="text-xl font-bold mb-4">Notification</h2>
                <p class="mb-6"><?= htmlspecialchars($error); ?></p>
                <a href="index.php" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold transition-colors">Return Home</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-12 gap-8">
                
                <!-- LEFT PANEL (Main Content) -->
                <div class="col-span-12 lg:col-span-8 space-y-6">
                    
                    <!-- Tabs Navigation -->
                    <div class="border-b border-slate-200">
                        <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                            <button @click="tab = 'executive'" 
                                    :class="tab === 'executive' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'"
                                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                                Executive View
                            </button>
                            <button @click="tab = 'structured'" 
                                    :class="tab === 'structured' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'"
                                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                                Structured Breakdown
                            </button>
                            <button @click="tab = 'narrative'" 
                                    :class="tab === 'narrative' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'"
                                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                                Full Narrative
                            </button>
                        </nav>
                    </div>

                    <!-- Tab Content: Executive View -->
                    <div x-show="tab === 'executive'" x-cloak class="space-y-6">
                        <!-- Overview -->
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                                <h3 class="text-lg font-semibold text-slate-800">Executive Overview</h3>
                            </div>
                            <div class="p-6 prose prose-slate max-w-none">
                                <?php if ($summaryOverview !== []): ?>
                                    <?php foreach ($summaryOverview as $overviewSentence): ?>
                                        <p><?= htmlspecialchars($overviewSentence); ?></p>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p><?= htmlspecialchars($plainSummaryText); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Key Findings -->
                        <?php if ($summaryKeyPoints !== [] || $overallSummaryBullets !== []): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                                <h3 class="text-lg font-semibold text-slate-800">Key Findings</h3>
                            </div>
                            <div class="p-6">
                                <ul class="space-y-3">
                                    <?php 
                                    $bulletsToRender = $summaryKeyPoints !== [] ? $summaryKeyPoints : $overallSummaryBullets;
                                    foreach ($bulletsToRender as $point): 
                                    ?>
                                        <li class="flex items-start">
                                            <svg class="h-6 w-6 text-indigo-500 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                            <span class="text-slate-700"><?= htmlspecialchars($point); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Conclusion -->
                        <?php if ($summaryConclusion !== ''): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                                <h3 class="text-lg font-semibold text-slate-800">Conclusion</h3>
                            </div>
                            <div class="p-6 prose prose-slate max-w-none">
                                <p><?= htmlspecialchars($summaryConclusion); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab Content: Structured Breakdown -->
                    <div x-show="tab === 'structured'" x-cloak class="space-y-4">
                        <?php if ($structuredSummary !== []): ?>
                            <?php foreach ($structuredSummary as $index => $block): ?>
                                <?php
                                    $blockLabel = trim((string)($block['label'] ?? ''));
                                    $blockText = trim((string)($block['text'] ?? ''));
                                    if ($blockLabel === '' || $blockText === '') continue;
                                ?>
                                <details class="group bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden" <?= $index === 0 ? 'open' : '' ?>>
                                    <summary class="flex justify-between items-center font-medium cursor-pointer list-none p-5 bg-slate-50 hover:bg-slate-100 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <span class="px-2.5 py-1 rounded-md bg-indigo-100 text-indigo-700 text-xs font-bold uppercase tracking-wider">
                                                Section <?= $index + 1 ?>
                                            </span>
                                            <span class="text-slate-800 text-lg font-semibold"><?= htmlspecialchars($blockLabel) ?></span>
                                        </div>
                                        <span class="transition group-open:rotate-180">
                                            <svg fill="none" height="24" shape-rendering="geometricPrecision" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" viewBox="0 0 24 24" width="24"><path d="M6 9l6 6 6-6"></path></svg>
                                        </span>
                                    </summary>
                                    <div class="text-slate-700 p-6 border-t border-slate-100 bg-white">
                                        <p class="leading-relaxed"><?= htmlspecialchars($blockText) ?></p>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        <?php elseif ($paragraphSummaries !== []): ?>
                            <?php foreach ($paragraphSummaries as $index => $paraSummary): ?>
                                <details class="group bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden" <?= $index === 0 ? 'open' : '' ?>>
                                    <summary class="flex justify-between items-center font-medium cursor-pointer list-none p-5 bg-slate-50 hover:bg-slate-100 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <span class="px-2.5 py-1 rounded-md bg-indigo-100 text-indigo-700 text-xs font-bold uppercase tracking-wider">
                                                Para <?= (int)$paraSummary['paragraph_number'] ?>
                                            </span>
                                            <span class="text-slate-800 font-semibold"><?= htmlspecialchars($paraSummary['purpose']) ?></span>
                                        </div>
                                        <span class="transition group-open:rotate-180">
                                            <svg fill="none" height="24" shape-rendering="geometricPrecision" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" viewBox="0 0 24 24" width="24"><path d="M6 9l6 6 6-6"></path></svg>
                                        </span>
                                    </summary>
                                    <div class="text-slate-700 p-6 border-t border-slate-100 bg-white">
                                        <p class="leading-relaxed"><?= htmlspecialchars($paraSummary['summary']) ?></p>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-8 text-center bg-white rounded-xl shadow-sm border border-slate-200 text-slate-500">
                                No structured breakdown available for this document.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab Content: Full Narrative -->
                    <div x-show="tab === 'narrative'" x-cloak class="space-y-6">
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                                <h3 class="text-lg font-semibold text-slate-800"><?= htmlspecialchars($summaryOutputHeading) ?></h3>
                            </div>
                            <div class="p-8 prose prose-slate prose-lg max-w-none text-slate-800 leading-relaxed" id="original-summary">
                                <?php
                                $isBulletStyle  = $summaryRenderStyle === 'bullet_points';
                                $isHybridStyle  = in_array($summaryRenderStyle, ['hybrid', 'executive_summary'], true);
                                $hasBullets = $overallSummaryBullets !== [];
                                $hasPara    = $plainSummaryText !== '';
                                ?>
                                <?php if ($isBulletStyle && $hasBullets): ?>
                                    <ul class="list-disc pl-5 space-y-2">
                                        <?php foreach ($overallSummaryBullets as $bullet): ?>
                                            <li><?= htmlspecialchars($bullet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif ($isHybridStyle && ($hasPara || $hasBullets)): ?>
                                    <?php if ($hasPara): ?>
                                        <p class="mb-6"><?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                                    <?php endif; ?>
                                    <?php if ($hasBullets): ?>
                                        <p class="font-semibold mb-3"><?= $summaryRenderStyle === 'executive_summary' ? 'Key decisions and supporting points:' : 'Key supporting points:' ?></p>
                                        <ul class="list-disc pl-5 space-y-2">
                                            <?php foreach ($overallSummaryBullets as $bullet): ?>
                                                <li><?= htmlspecialchars($bullet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                <?php elseif ($hasPara): ?>
                                    <p><?= htmlspecialchars($plainSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
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
                        </div>
                        
                        <div class="bg-slate-100 rounded-xl p-6 border border-slate-200">
                            <h4 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-4">Original Source Text</h4>
                            <div class="prose prose-sm max-w-none text-slate-600 max-h-64 overflow-y-auto pr-4">
                                <?= nl2br(htmlspecialchars($summaryData['original_text'] ?? '')); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Audio Panel -->
                    <div id="audio-panel" class="hidden bg-white rounded-xl shadow-sm border border-slate-200 p-6 mt-6">
                        <h3 class="text-lg font-semibold text-slate-800 mb-4">Summary Audio</h3>
                        <audio id="summary-audio-player" controls preload="none" class="w-full"></audio>
                    </div>

                    <!-- Feedback Widget -->
                    <div id="feedback-widget" class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 mt-12">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-xl font-bold text-slate-800">Rate This Summary</h3>
                            <?php if ($ratingStats['count'] > 0): ?>
                                <span class="text-sm font-medium text-slate-500 bg-slate-100 px-3 py-1 rounded-full">
                                    <?= htmlspecialchars((string)$ratingStats['avg']) ?> avg &middot; <?= (int)$ratingStats['count'] ?> rating<?= $ratingStats['count'] !== 1 ? 's' : '' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div id="star-row" class="flex gap-2 text-3xl text-slate-300">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <button type="button" 
                                        class="hover:text-yellow-400 transition-colors focus:outline-none <?= ($existingRating !== null && $s <= $existingRating) ? 'text-yellow-400' : '' ?>"
                                        data-value="<?= $s ?>"
                                        aria-label="Rate <?= $s ?> star<?= $s > 1 ? 's' : '' ?>"
                                >&#9733;</button>
                            <?php endfor; ?>
                        </div>
                        <div id="feedback-msg" class="mt-4 text-sm font-medium hidden"></div>
                    </div>
                </div>

                <!-- RIGHT PANEL (Sticky Sidebar) -->
                <div class="col-span-12 lg:col-span-4 relative">
                    <div class="sticky top-24 space-y-6">
                        
                        <!-- Document Details Card -->
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                            <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-4 pb-2 border-b border-slate-100">Document Details</h3>
                            <dl class="space-y-4 text-sm">
                                <div>
                                    <dt class="text-slate-500 mb-1">Date Processed</dt>
                                    <dd class="font-medium text-slate-900"><?= date('F d, Y', strtotime($summaryData['created_at'])) ?></dd>
                                </div>
                                <?php if ($articleType !== ''): ?>
                                <div>
                                    <dt class="text-slate-500 mb-1">Document Type</dt>
                                    <dd class="font-medium text-slate-900 capitalize"><?= htmlspecialchars($articleType) ?></dd>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($readabilityStats['compression_percent'])): ?>
                                <div>
                                    <dt class="text-slate-500 mb-1">Compression Ratio</dt>
                                    <dd class="font-medium text-slate-900">
                                        <div class="flex items-center gap-2">
                                            <div class="w-full bg-slate-200 rounded-full h-2">
                                                <div class="bg-indigo-600 h-2 rounded-full" style="width: <?= 100 - (float)$readabilityStats['compression_percent'] ?>%"></div>
                                            </div>
                                            <span><?= htmlspecialchars((string)$readabilityStats['compression_percent']) ?>%</span>
                                        </div>
                                    </dd>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($readabilityStats['estimated_reading_time_minutes'])): ?>
                                <div>
                                    <dt class="text-slate-500 mb-1">Est. Reading Time</dt>
                                    <dd class="font-medium text-slate-900"><?= (int)$readabilityStats['estimated_reading_time_minutes'] ?> min</dd>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($sourceMetadata['paragraph_count'])): ?>
                                <div>
                                    <dt class="text-slate-500 mb-1">Original Size</dt>
                                    <dd class="font-medium text-slate-900"><?= number_format((int)$sourceMetadata['paragraph_count']) ?> paragraphs</dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                        </div>

                        <!-- Key Terms & Concepts Card -->
                        <?php if ($summaryImportantTerms !== []): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                            <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-4 pb-2 border-b border-slate-100">Key Terms & Concepts</h3>
                            <ul class="space-y-4">
                                <?php foreach ($summaryImportantTerms as $termEntry): 
                                    $term = htmlspecialchars(trim((string)($termEntry['term'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                    $meaning = htmlspecialchars(trim((string)($termEntry['meaning'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                    if ($term === '') continue;
                                ?>
                                    <li class="group relative">
                                        <div class="font-semibold text-slate-800 text-sm mb-1"><?= $term ?></div>
                                        <?php if ($meaning !== ''): ?>
                                            <div class="text-xs text-slate-500 leading-relaxed"><?= $meaning ?></div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            
                            <?php if ($summaryKeywords !== []): ?>
                            <div class="mt-6 pt-4 border-t border-slate-100">
                                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Tags</h4>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($summaryKeywords as $keyword): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700 border border-slate-200">
                                            <?= htmlspecialchars($keyword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Ensure logic for tool actions works with legacy JS by keeping IDs but styling them down if needed -->
<script src="assets/js/result.js"></script>
</body>
</html>