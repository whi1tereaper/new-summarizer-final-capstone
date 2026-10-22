<?php
/**
 * Shared Analytics & Graphs Dashboard Component
 *
 * Expected variables:
 * - $analyticsData: array of dashboard data (kpis, trend, styles, words_comparison, categories, etc.)
 * - $isAdmin: bool
 */
if (empty($analyticsData)) {
    return;
}
$analyticsActiveRange = !empty($isAdmin) ? (string)($adminStatsRange ?? 'all') : '30';
?>
<section class="dashboard-analytics-section" aria-label="Analytics and graphs">
    <div class="analytics-section-card">
        <div class="analytics-sidebar__header">
            <div>
                <span class="sidebar-header__eyebrow"><?= !empty($isAdmin) ? 'System Overview' : 'Your Activity' ?></span>
                <h2 class="sidebar-header__title">Analytics &amp; Graphs</h2>
            </div>
            <a href="analytics.php" class="analytics-sidebar__open" aria-label="Open full analytics dashboard" title="Open full analytics dashboard">↗</a>
        </div>

        <div class="analytics-sidebar__filters" role="group" aria-label="Analytics range">
            <button type="button" class="analytics-range global-button-effect <?= $analyticsActiveRange === '30' ? 'is-active' : '' ?>" data-range="30" aria-pressed="<?= $analyticsActiveRange === '30' ? 'true' : 'false' ?>">30d</button>
            <button type="button" class="analytics-range global-button-effect <?= $analyticsActiveRange === '7' ? 'is-active' : '' ?>" data-range="7" aria-pressed="<?= $analyticsActiveRange === '7' ? 'true' : 'false' ?>">7d</button>
            <button type="button" class="analytics-range global-button-effect <?= $analyticsActiveRange === '90' ? 'is-active' : '' ?>" data-range="90" aria-pressed="<?= $analyticsActiveRange === '90' ? 'true' : 'false' ?>">90d</button>
            <button type="button" class="analytics-range global-button-effect <?= $analyticsActiveRange === 'all' ? 'is-active' : '' ?>" data-range="all" aria-pressed="<?= $analyticsActiveRange === 'all' ? 'true' : 'false' ?>">All</button>
        </div>

        <div class="analytics-sidebar__metrics" aria-live="polite">
            <div><strong data-analytics="articles">0</strong><span>Total Articles</span></div>
            <div><strong data-analytics="summaries">0</strong><span>Summaries</span></div>
            <div><strong data-analytics="original_avg">N/A</strong><span>Avg Original Words</span></div>
            <div><strong data-analytics="summary_avg">N/A</strong><span>Avg Summary Words</span></div>
            <div><strong data-analytics="reduction">N/A</strong><span>Compression Ratio</span></div>
            <div><strong data-analytics="method">N/A</strong><span>Top Method</span></div>
        </div>

        <div class="dashboard-charts-grid">
            <div class="analytics-sidebar__chart">
                <h3>Summaries Over Time</h3>
                <div><canvas id="sidebarActivityChart"></canvas></div>
            </div>
            <div class="analytics-sidebar__chart">
                <h3>Original vs Summary Length</h3>
                <div><canvas id="sidebarWordsChart"></canvas></div>
            </div>
            <div class="analytics-sidebar__chart">
                <h3>Articles by Category</h3>
                <div><canvas id="sidebarCategoryChart"></canvas></div>
            </div>
            <div class="analytics-sidebar__chart">
                <h3>Method Usage</h3>
                <div><canvas id="sidebarMethodChart"></canvas></div>
            </div>
        </div>

        <p class="analytics-sidebar__empty" hidden>No analytics yet. Summarize your first article to start tracking your activity.</p>
    </div>
</section>
<script>window.sidebarAnalyticsData = <?= json_encode($analyticsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
