<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';

use App\Src\Controllers\AnalyticsController;

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
if ($userId === null || $userId < 1) {
    header('Location: login.php');
    exit;
}

$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
$analyticsController = new AnalyticsController();
if (($_GET['action'] ?? '') === 'data') {
    $range = $_GET['range'] ?? '30';
    $to = date('Y-m-d');
    $from = $range === 'all' ? '1970-01-01' : date('Y-m-d', strtotime('-' . max(1, min(90, (int)$range) - 1) . ' days'));
    try {
        header('Content-Type: application/json');
        echo json_encode($analyticsController->getDashboardData($userId, $isAdmin, $from, $to));
    } catch (Throwable $exception) {
        error_log('[analytics api] ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Analytics data could not be loaded.']);
    }
    exit;
}
$today = new DateTimeImmutable('today');
$period = $_GET['period'] ?? '30d';
$presetDays = ['7d' => 7, '30d' => 30, '3m' => 90];
$fromInput = trim((string)($_GET['from'] ?? ''));
$toInput = trim((string)($_GET['to'] ?? ''));

if (isset($presetDays[$period])) {
    $toDate = $today;
    $fromDate = $today->modify('-' . ($presetDays[$period] - 1) . ' days');
} elseif ($period === 'year') {
    $fromDate = $today->setDate((int)$today->format('Y'), 1, 1);
    $toDate = $today;
} else {
    $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $fromInput) ?: $today->modify('-29 days');
    $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $toInput) ?: $today;
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
    $period = 'custom';
}

$data = null;
$loadError = false;
try {
    $data = (new AnalyticsController())->getDashboardData(
        $userId,
        $isAdmin,
        $fromDate->format('Y-m-d'),
        $toDate->format('Y-m-d')
    );
} catch (Throwable $exception) {
    error_log('[analytics] ' . $exception->getMessage());
    $loadError = true;
}

$data = $data ?? ['kpis' => ['articles' => 0, 'summaries' => 0, 'words' => 0, 'reduction' => null], 'trend' => [], 'styles' => [], 'lengths' => [], 'words_comparison' => [], 'types' => [], 'recent' => [], 'performance' => []];
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$styleLabels = ['standard_paragraph' => 'Paragraph Summary', 'bullet_points' => 'Bullet Points', 'hybrid' => 'Hybrid', 'academic_summary' => 'Academic Summary', 'simple_summary' => 'Simple Summary', 'executive_summary' => 'Executive Summary'];
$typeLabels = ['text' => 'Text', 'pdf' => 'PDF', 'docx' => 'DOCX', 'url' => 'URL'];
$kpis = $data['kpis'];
$hasData = (int)$kpis['summaries'] > 0;
$formatSeconds = static fn(mixed $value): string => $value === null ? 'N/A' : number_format((float)$value, 2) . ' s';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics | Article Summarizer</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@700&family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/analytics.css">
</head>
<body class="analytics-page">
<header class="site-header analytics-header"><div class="site-header__inner">
    <a class="analytics-brand font-brand-heading" href="summarizer.php">ARTICLE SUMMARIZER</a>
    <nav class="analytics-nav" aria-label="Primary navigation">
        <a href="summarizer.php">Summarizer</a><a class="is-active" href="analytics.php">Analytics</a><a href="history.php">History</a>
        <?php if ($isAdmin): ?><a href="admin_dashboard.php">Admin</a><?php endif; ?>
        <a href="auth.php?action=logout">Logout</a>
    </nav>
</div></header>
<main class="analytics-shell">
    <div class="analytics-heading"><div><p class="eyebrow font-ui-label">Activity report<?= $isAdmin ? ' · System wide' : ' · Personal' ?></p><h1 class="font-brand-heading">Analytics</h1><p class="font-ui-body">Overview of article summarization activity</p></div>
        <form class="date-filter" method="get" aria-label="Analytics date filter">
            <div class="filter-presets">
                <?php foreach (['7d' => 'Last 7 days', '30d' => 'Last 30 days', '3m' => 'Last 3 months', 'year' => 'This year'] as $key => $label): ?>
                    <button type="submit" name="period" value="<?= $key ?>" class="<?= $period === $key ? 'is-active' : '' ?>"><?= $label ?></button>
                <?php endforeach; ?>
            </div>
            <div class="custom-filter"><label>From <input type="date" name="from" value="<?= $h($fromDate->format('Y-m-d')) ?>"></label><label>To <input type="date" name="to" value="<?= $h($toDate->format('Y-m-d')) ?>"></label><button type="submit" name="period" value="custom">Apply filter</button></div>
        </form>
    </div>
    <?php if ($loadError): ?><div class="analytics-alert">Analytics data could not be loaded. Please try again.</div><?php endif; ?>
    <section class="metric-grid" aria-label="Summary statistics">
        <?php if ($isAdmin): ?><article class="metric-card"><span>Total Users</span><strong><?= number_format((int)($data['total_users'] ?? 0)) ?></strong><small>Registered users</small></article><?php endif; ?>
        <?php foreach ([['Total Articles', $kpis['articles'], 'Processed articles'], ['Summaries Generated', $kpis['summaries'], 'Successful summaries'], ['Words Processed', number_format((int)$kpis['words']), 'Original words'], ['Average Reduction', $kpis['reduction'] === null ? 'N/A' : number_format((float)$kpis['reduction'], 1) . '%', 'Original to summary'] ] as [$label, $value, $note]): ?>
            <article class="metric-card"><span><?= $label ?></span><strong><?= $h($value) ?></strong><small><?= $note ?></small></article>
        <?php endforeach; ?>
    </section>
    <?php if (!$hasData && !$loadError): ?><div class="empty-state"><h2>No summarization data available yet.</h2><p>Generate your first article summary to see analytics here.</p><a href="summarizer.php">Generate a summary</a></div><?php endif; ?>
    <?php if ($hasData): ?>
    <section class="chart-grid" aria-label="Summary charts">
        <article class="chart-card chart-card--wide"><div class="chart-title"><h2>Summaries Generated Over Time</h2><span><?= $h($fromDate->format('M j, Y')) ?> to <?= $h($toDate->format('M j, Y')) ?></span></div><div class="chart-wrap"><canvas id="activityChart"></canvas></div></article>
        <article class="chart-card chart-card--wide"><div class="chart-title"><h2>Nutshell Usage Over Time</h2><span><?= (int)($data['nutshell']['stored'] ?? 0) ?> stored · avg <?= $data['nutshell']['average_words'] !== null ? round((float)$data['nutshell']['average_words']) : 'N/A' ?> words</span></div><div class="chart-wrap"><canvas id="nutshellChart"></canvas></div></article>
        <article class="chart-card"><h2>Output Style Distribution</h2><div class="chart-wrap chart-wrap--donut"><canvas id="styleChart"></canvas></div></article>
        <article class="chart-card"><h2>Summary Length Usage</h2><div class="chart-wrap"><canvas id="lengthChart"></canvas></div></article>
        <article class="chart-card"><h2>Original vs Summary Words</h2><div class="chart-wrap"><canvas id="wordsChart"></canvas></div></article>
        <article class="chart-card"><h2>Uploaded File Types</h2><div class="chart-wrap"><canvas id="typeChart"></canvas></div></article>
    </section>
    <section class="performance-section"><div><p class="eyebrow">Measured from summary execution</p><h2>Processing Performance</h2></div><div class="performance-grid"><span>Average <b><?= $formatSeconds($data['performance']['average_time'] ?? null) ?></b></span><span>Fastest <b><?= $formatSeconds($data['performance']['fastest_time'] ?? null) ?></b></span><span>Slowest <b><?= $formatSeconds($data['performance']['slowest_time'] ?? null) ?></b></span><span>Successful <b><?= (int)($data['performance']['successful'] ?? 0) ?></b></span><span>Failed <b><?= (int)($data['performance']['failed'] ?? 0) ?></b></span></div></section>
    <section class="recent-section"><div class="chart-title"><h2>Recent Activity</h2><span>Metadata only</span></div><div class="activity-list">
        <?php foreach ($data['recent'] as $item): ?><div class="activity-item"><div><strong><?= $h($item['article_title']) ?></strong><span><?= $h($styleLabels[$item['summary_style']] ?? 'Other') ?> · <?= $h(ucfirst((string)($item['summary_length'] ?? 'N/A'))) ?></span></div><time datetime="<?= $h($item['created_at']) ?>"><?= $h(date('M j, Y · g:i A', strtotime($item['created_at']))) ?></time></div><?php endforeach; ?>
    </div></section>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>window.analyticsData = <?= json_encode(['trend' => $data['trend'], 'nutshellTrend' => $data['nutshell_trend'] ?? [], 'styles' => $data['styles'], 'lengths' => $data['lengths'], 'words' => $data['words_comparison'], 'types' => $data['types'], 'styleLabels' => $styleLabels, 'typeLabels' => $typeLabels], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="assets/js/analytics.js"></script>
    <?php endif; ?>
</main>
</body></html>
