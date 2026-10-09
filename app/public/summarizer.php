<?php
require_once __DIR__ . '/../src/whitereaper.php';

// Build the page state first so the template can branch cleanly for guests, users, and admins.
$userId = $_SESSION['user_id'] ?? null;
$guestToken = isset($_SESSION['guest_token']) && is_string($_SESSION['guest_token'])
    ? trim($_SESSION['guest_token'])
    : null;
$username = $_SESSION['username'] ?? 'GUEST';
$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
$canUseRegisteredFeatures = $userId !== null && (int)$userId > 0;

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

if ($canUseRegisteredFeatures) {
    try {
        $ac = new AnalyticsController();
        if ($isAdmin) {
            $sidebarData = $ac->getSidebarSnapshot();
            $sidebarMode = 'admin';
        } else {
            $sidebarData = $ac->getUserSidebarStats((int)($userId ?? 0), $guestToken);
            $sidebarMode = 'user';
        }
        $analyticsData = $ac->getDashboardData(
            $userId ? (int)$userId : null,
            $isAdmin,
            '1970-01-01',
            date('Y-m-d'),
            $guestToken
        );
    } catch (\Throwable $e) {
        error_log('[summarizer sidebar] ' . $e->getMessage());
        // Non-fatal: sidebar stays null, layout stays single-column.
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Summarizer — LIGHT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@600;700;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">

    <!-- Design tokens and stylesheets -->
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
    <link rel="stylesheet" href="assets/css/nex-landing.css?v=<?= filemtime(__DIR__ . '/assets/css/nex-landing.css') ?>">
    <link rel="stylesheet" href="assets/css/global-button-effects.css?v=3">
</head>

<body class="nex-landing nex-workspace-page<?= $isAdmin ? ' page-summary-layout--admin' : '' ?><?= $sidebarData ? ' page-summary-layout--has-sidebar' : '' ?>">

    <!-- ================================================================
         GLOBAL SITE HEADER
    ================================================================ -->
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <!-- ================================================================
         SUMMARIZER WORKSPACE
    ================================================================ -->
    <main class="nex-summarize-page" id="main-content">
        <div class="nex-summarize-page__inner<?= $sidebarData ? ' nex-summarize-page__inner--split' : '' ?>">

            <div class="nex-summarize-page__main">
                <div class="nex-section-header">
                    <span class="nex-section-num">01</span>
                    <span class="nex-section-label">Summarize</span>
                </div>

                <h1 class="nex-summarize__headline">
                    DROP YOUR<br>DOCUMENT.
                </h1>

                <div class="nex-form-shell" id="nex-form-wrapper">
                    <?php require __DIR__ . '/partials/summarizer-form.php'; ?>
                </div>
            </div>

            <?php if ($sidebarData): ?>
            <aside class="workspace-sidebar analytics-sidebar nex-sidebar-light" aria-label="Analytics and graphs">
                <div class="analytics-sidebar__header">
                    <div>
                        <span class="sidebar-header__eyebrow"><?= $isAdmin ? 'System Overview' : 'Your Activity' ?></span>
                        <h2 class="sidebar-header__title">Analytics &amp; Graphs</h2>
                    </div>
                    <a href="analytics.php" class="analytics-sidebar__open" aria-label="Open full analytics dashboard" title="Open full analytics dashboard">↗</a>
                </div>
                <?php if ($analyticsData): ?>
                <div class="analytics-sidebar__filters" role="group" aria-label="Analytics range">
                    <button type="button" class="analytics-range global-button-effect is-active" data-range="30">30d</button>
                    <button type="button" class="analytics-range global-button-effect" data-range="7">7d</button>
                    <button type="button" class="analytics-range global-button-effect" data-range="90">90d</button>
                    <button type="button" class="analytics-range global-button-effect" data-range="all">All</button>
                </div>
                <div class="analytics-sidebar__metrics" aria-live="polite">
                    <div><strong data-analytics="articles">0</strong><span>Total Articles</span></div>
                    <div><strong data-analytics="summaries">0</strong><span>Summaries</span></div>
                    <div><strong data-analytics="original_avg">N/A</strong><span>Avg Original Words</span></div>
                    <div><strong data-analytics="summary_avg">N/A</strong><span>Avg Summary Words</span></div>
                    <div><strong data-analytics="reduction">N/A</strong><span>Compression Ratio</span></div>
                    <div><strong data-analytics="method">N/A</strong><span>Top Method</span></div>
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

        </div>
    </main>

    <!-- Editorial Footer (Matches landing page) -->
    <?php require __DIR__ . '/partials/nex-footer.php'; ?>

    <script src="assets/js/vendor/chart.umd.min.js"></script>
    <?php require __DIR__ . '/partials/summarizer-scripts.php'; ?>
    <script src="assets/js/nex-landing.js?v=<?= filemtime(__DIR__ . '/assets/js/nex-landing.js') ?>" defer></script>
</body>
</html>
