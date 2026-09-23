<?php
require_once __DIR__ . '/../src/whitereaper.php';

// Build the page state first so the template can branch cleanly for guests, users, and admins.
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'GUEST';
$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';


require_once '../src/Utils/validation.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';

use App\Src\Controllers\AnalyticsController;

$csrf_token = generateCsrfToken();

// ── Sidebar data ──────────────────────────────────────────────────────────────
// Load analytics data based on role. Always graceful: any DB failure → null.
$sidebarData   = null;
$sidebarMode   = null; // 'admin' | 'user'
$analyticsData = null;

if ($userId) {
    try {
        $ac = new AnalyticsController();
        if ($isAdmin) {
            $sidebarData = $ac->getSidebarSnapshot();
            $sidebarMode = 'admin';
        } else {
            $sidebarData = $ac->getUserSidebarStats((int)$userId);
            $sidebarMode = 'user';
        }
        $analyticsData = $ac->getDashboardData(
            (int)$userId,
            $isAdmin,
            '1970-01-01',
            date('Y-m-d')
        );
    } catch (\Throwable $e) {
        error_log('[summarizer sidebar] ' . $e->getMessage());
        // Non-fatal: sidebar stays null, layout stays single-column.
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARTICLE SUMMARIZER</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=Outfit:wght@600;700&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index.css?v=<?= time() ?>">
</head>

<body class="page-summary-layout<?= $isAdmin ? ' page-summary-layout--admin' : '' ?><?= $sidebarData ? ' page-summary-layout--has-sidebar' : '' ?>">
    <header class="site-header">
        <div class="site-header__inner">



            <div class="site-actions">
                <span class="site-actions__welcome">Welcome, <strong><?= htmlspecialchars($username) ?></strong></span>
                <?php if ($userId): ?>
                    <?php if ($isAdmin): ?>
                        <a href="admin_dashboard.php" class="site-actions__link">Admin</a>
                    <?php endif; ?>
                    <a href="analytics.php" class="site-actions__link">Analytics</a>
                    <a href="history.php" class="site-actions__link">History</a>
                    <a href="auth.php?action=logout" class="site-actions__button js-logout-link">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="site-actions__link">Login</a>
                    <a href="register.php" class="site-actions__button site-actions__button--register">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="workspace-page">
        <section class="workspace-page__content" aria-labelledby="workspace-title">


            <form id="summarize-form" action="summarize.php" method="POST" enctype="multipart/form-data" class="summary-workspace workspace-page__form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <?php 
                    // Show the latest validation or processing failure once, then clear it from the session.
                    $errorMessage = $_SESSION['flash_error'] ?? $_GET['error'] ?? null;
                    if ($errorMessage): 
                ?>
                    <div class="flash-error">
                        <?php
                            echo htmlspecialchars($errorMessage);
                            unset($_SESSION['flash_error']);
                        ?>
                    </div>
                <?php endif; ?>

                <div class="summary-panel summary-panel--page">
                    <div class="summary-panel__header">
                        <div>
                            <p class="summary-panel__eyebrow"></p>
                            <h2>Generate Article Summary</h2>
                        </div>
                    </div>

                    <div class="summary-grid">
                        <section class="summary-card summary-card--full" id="document-title-card">
                            <label for="document_title">Document Title <span class="label-hint">(Optional — guides topic focus &amp; key section identification)</span></label>
                            <input type="text" id="document_title" name="document_title" placeholder="e.g., Impact of Quantum Computing on Modern Cryptography" value="<?= htmlspecialchars($_POST['document_title'] ?? '') ?>" autocomplete="off">
                        </section>

                        <section class="summary-card" id="source-file">
                            <label for="pdf">Source File</label>
                            <input type="file" id="pdf" name="pdf_file" accept=".pdf,.docx" aria-describedby="source-selection-note">
                        </section>

                        <section class="summary-card" id="output-style">
                            <label for="summary_style">Output Style &amp; Intelligence Mode</label>
                            <select id="summary_style" name="summary_style">
                                <option value="standard_paragraph" <?= ($_POST['summary_style'] ?? '') === 'standard_paragraph' ? 'selected' : '' ?>>Paragraph Summary</option>
                                <option value="bullet_points" <?= ($_POST['summary_style'] ?? '') === 'bullet_points' ? 'selected' : '' ?>>Bullet Points</option>
                                <option value="hybrid" <?= ($_POST['summary_style'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid (Overview + Bullets)</option>
                                <option value="executive_summary" <?= ($_POST['summary_style'] ?? '') === 'executive_summary' ? 'selected' : '' ?>>Executive Summary</option>
                                <option value="academic_summary" <?= ($_POST['summary_style'] ?? '') === 'academic_summary' ? 'selected' : '' ?>>Academic / Research Summary</option>
                                <option value="simple_summary" <?= ($_POST['summary_style'] ?? '') === 'simple_summary' ? 'selected' : '' ?>>Study / Conceptual Summary</option>
                                <option value="technical_summary" <?= ($_POST['summary_style'] ?? '') === 'technical_summary' ? 'selected' : '' ?>>Technical / Architecture Summary</option>
                                <option value="news_summary" <?= ($_POST['summary_style'] ?? '') === 'news_summary' ? 'selected' : '' ?>>News / Events Summary</option>
                            </select>
                            <p class="style-description" id="style_description" aria-live="polite"></p>
                        </section>

                        <section class="summary-card summary-card--full" id="summary-length">
                            <label>Summary Length</label>
                            <?php
                                $lengthMap = [
                                    'brief'         => 3,
                                    'short'         => 5,
                                    'balanced'      => 8,
                                    'detailed'      => 11,
                                    'comprehensive' => 15,
                                ];
                                // Support both direct summary_length posted or reverse-mapping sentence_count
                                $postedLength  = strtolower(trim((string)($_POST['summary_length'] ?? '')));
                                if (array_key_exists($postedLength, $lengthMap)) {
                                    $activeLabel = $postedLength;
                                } else {
                                    $postedCount   = (int)($_POST['sentence_count'] ?? 8);
                                    $activeLabel   = 'balanced';
                                    $minDiff       = PHP_INT_MAX;
                                    foreach ($lengthMap as $lbl => $cnt) {
                                        $diff = abs($cnt - $postedCount);
                                        if ($diff < $minDiff) { $minDiff = $diff; $activeLabel = $lbl; }
                                    }
                                }
                            ?>
                            <div class="length-segmented" role="group" aria-label="Summary length">
                                <?php foreach ($lengthMap as $label => $count): ?>
                                <button
                                    type="button"
                                    class="length-seg-btn<?= $label === $activeLabel ? ' length-seg-btn--active' : '' ?>"
                                    data-length="<?= $label ?>"
                                    data-count="<?= $count ?>"
                                    aria-pressed="<?= $label === $activeLabel ? 'true' : 'false' ?>"
                                ><?= ucfirst($label) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" id="summary_length" name="summary_length" value="<?= htmlspecialchars($activeLabel) ?>">
                            <input type="hidden" id="sentence_count" name="sentence_count" value="<?= $lengthMap[$activeLabel] ?>">
                        </section>

                        <section class="summary-card summary-card--full" id="source-text">
                            <label for="text">Source</label>
                            <textarea id="text" name="original_text" placeholder="Paste article text/url here..." aria-describedby="source-selection-note"></textarea>
                            <p class="privacy-note" id="source-selection-note" aria-live="polite">
                                Choose one source: a file or pasted text/URL. Selecting a file uses that file for the summary.
                            </p>
                            <p class="privacy-note">
                                Please do not submit private, sensitive, or confidential information unless you are allowed to do so.
                                Your submitted content will be processed to generate a summary.
                            </p>
                        </section>
                    </div>

                    <div class="summary-panel__footer">

                        <div class="summary-actions-group">
                            <button type="submit" class="summary-submit-button">
                                SUMMARIZE
                            </button>
                            <button type="button" id="btn-nutshell-action" class="summary-nutshell-button">
                                NUTSHELL
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Dedicated Nutshell Result Card -->
            <section class="nutshell-panel" id="nutshell-panel" style="display: none;" aria-live="polite">
                <div class="nutshell-panel__header">
                    <div class="nutshell-badge">
                        <svg class="nutshell-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        <span>IN A NUTSHELL</span>
                    </div>
                    <button type="button" class="nutshell-copy-btn" id="nutshell-copy-btn" title="Copy to clipboard">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                        <span class="copy-label">Copy</span>
                    </button>
                </div>
                <div class="nutshell-body" id="nutshell-body">
                    <div class="nutshell-loading" id="nutshell-loading" style="display: none;">
                        <div class="nutshell-spinner"></div>
                        <span>Distilling central message...</span>
                    </div>
                    <div class="nutshell-error" id="nutshell-error" style="display: none;" role="alert">
                        <div class="nutshell-error__icon">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </div>
                        <div class="nutshell-error__content">
                            <strong class="nutshell-error__title">Unable to Generate Nutshell</strong>
                            <p class="nutshell-error__message" id="nutshell-error-message"></p>
                        </div>
                        <button type="button" class="nutshell-error__retry-btn" id="nutshell-retry-btn">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 4v6h6"></path>
                                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                            </svg>
                            <span>Retry</span>
                        </button>
                    </div>
                    <p class="nutshell-text" id="nutshell-text"></p>
                </div>
            </section>

        <?php if ($sidebarData): ?>
        <aside class="workspace-sidebar analytics-sidebar" aria-label="Analytics and graphs">
            <div class="analytics-sidebar__header">
                <div><span class="sidebar-header__eyebrow"><?= $isAdmin ? 'System Overview' : 'Your Activity' ?></span><h2 class="sidebar-header__title">Analytics &amp; Graphs</h2></div>
                <a href="analytics.php" class="analytics-sidebar__open" aria-label="Open full analytics dashboard" title="Open full analytics dashboard">↗</a>
            </div>
            <?php if ($analyticsData): ?>
            <div class="analytics-sidebar__filters" role="group" aria-label="Analytics range">
                <button type="button" class="analytics-range is-active" data-range="30">30d</button><button type="button" class="analytics-range" data-range="7">7d</button><button type="button" class="analytics-range" data-range="90">90d</button><button type="button" class="analytics-range" data-range="all">All</button>
            </div>
            <div class="analytics-sidebar__metrics" aria-live="polite">
                <div><strong data-analytics="articles">0</strong><span>Total Articles</span></div><div><strong data-analytics="summaries">0</strong><span>Summaries</span></div><div><strong data-analytics="original_avg">N/A</strong><span>Avg Original Words</span></div><div><strong data-analytics="summary_avg">N/A</strong><span>Avg Summary Words</span></div><div><strong data-analytics="reduction">N/A</strong><span>Compression Ratio</span></div><div><strong data-analytics="method">N/A</strong><span>Top Method</span></div>
            </div>
            <div class="analytics-sidebar__chart"><h3>Summaries Over Time</h3><div><canvas id="sidebarActivityChart"></canvas></div></div>
            <div class="analytics-sidebar__chart"><h3>Original vs Summary Length</h3><div><canvas id="sidebarWordsChart"></canvas></div></div>
            <div class="analytics-sidebar__chart"><h3>Articles by Category</h3><div><canvas id="sidebarCategoryChart"></canvas></div></div>
            <div class="analytics-sidebar__chart"><h3>Method Usage</h3><div><canvas id="sidebarMethodChart"></canvas></div></div>
            <p class="analytics-sidebar__empty" hidden>No analytics yet. Summarize your first article to start tracking your activity.</p>
            <script>window.sidebarAnalyticsData = <?= json_encode($analyticsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
            <?php endif; ?>
        </aside>
        <?php endif; ?>

        </section>
    </main>

    <?php require __DIR__ . '/partials/site-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="assets/js/index.js"></script>
<script>
(function() {
    var styleDescriptions = {
        'standard_paragraph': 'Balanced narrative summary capturing key thematic points in fluent paragraph form.',
        'bullet_points': 'High-impact key takeaways formatted as clean, standalone bullet points.',
        'hybrid': 'Executive narrative overview paired with structured bullet points for quick scanning.',
        'executive_summary': 'Tailored for leadership: prioritizes strategic decisions, core findings, metrics, and recommendations.',
        'academic_summary': 'Tailored for research: prioritizes methodology, experimental setup, numerical findings, and conclusions.',
        'simple_summary': 'Tailored for study & learning: highlights definitions, fundamental concepts, and explanatory analogies.',
        'technical_summary': 'Tailored for engineers: highlights architecture, components, constraints, dependencies, and operational details.',
        'news_summary': 'Tailored for journalism: focuses on who, what, when, where, breaking facts, and reported outcomes.'
    };

    var styleSelect = document.getElementById('summary_style');
    var styleDesc = document.getElementById('style_description');

    function updateStyleDescription() {
        if (!styleSelect || !styleDesc) return;
        var val = styleSelect.value;
        styleDesc.textContent = styleDescriptions[val] || '';
    }

    if (styleSelect) {
        styleSelect.addEventListener('change', updateStyleDescription);
        updateStyleDescription();
    }
})();
</script>

</body>
</html>
