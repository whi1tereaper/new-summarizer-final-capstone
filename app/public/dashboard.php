<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';
require_once __DIR__ . '/../src/Repositories/HistoryRepository.php';

use App\Src\Controllers\AnalyticsController;
use App\Src\Repositories\HistoryRepository;

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php?redirect=dashboard.php');
    exit;
}
if (($_SESSION['role'] ?? 'user') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

$ac = new AnalyticsController();
$preferences = $ac->getUserPreferredSettings($userId);
$recent = (new HistoryRepository())->findSummaryHistory($userId, null, 5, 0);
$labels = [
    'analysis_mode' => [
        'general' => 'General',
        'academic' => 'Academic',
        'executive' => 'Executive',
        'technical' => 'Technical',
        'study' => 'Study',
        'news' => 'News',
        'legal' => 'Legal & Policy',
        'scientific' => 'Scientific',
    ],
    'output_format' => [
        'paragraph' => 'Paragraph',
        'bullets' => 'Bullet Points',
        'hybrid' => 'Hybrid',
        'structured' => 'Structured Sections',
        'key_takeaways' => 'Key Takeaways',
    ],
    'summary_depth' => [
        'very_brief' => 'Very Brief',
        'brief' => 'Brief',
        'short' => 'Short',
        'balanced' => 'Balanced',
        'detailed' => 'Detailed',
        'comprehensive' => 'Comprehensive',
    ],
];
$preferredHref = $preferences ? 'summarizer.php?' . http_build_query([
    'analysis_mode' => $preferences['analysis_mode'],
    'output_format' => $preferences['output_format'],
    'summary_depth' => $preferences['summary_depth'],
]) : 'summarizer.php';

$guestToken = $_SESSION['guest_token'] ?? null;
$analyticsData = null;
try {
    $analyticsData = $ac->getDashboardData($userId, false, '1970-01-01', date('Y-m-d'), $guestToken);
} catch (\Throwable $e) {
    error_log('[dashboard analytics] ' . $e->getMessage());
}
$isAdmin = false;
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Your Dashboard — LIGHT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/site-nav.css">
    <link rel="stylesheet" href="assets/css/global-button-effects.css">
    <link rel="stylesheet" href="assets/css/dashboard-charts.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard-charts.css') ?>">
</head>
<body class="nex-landing">
    <div><?php require __DIR__ . '/partials/site-nav.php'; ?></div>
    <main style="max-width:1100px;margin:120px auto 80px;padding:0 24px">
        <p class="nex-section-label">Your workspace</p>
        <h1 style="font-size:clamp(2.5rem,6vw,5rem);margin:8px 0 48px">Welcome back, <?= htmlspecialchars((string)($_SESSION['username'] ?? 'there')) ?>.</h1>

        <section style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px" aria-label="Your preferred settings">
            <article style="border:1px solid var(--color-border, #ddd);border-radius:18px;padding:24px;background:var(--color-surface);color:var(--color-text)">
                <p class="nex-section-label">Most used settings</p>
                <?php if ($preferences): ?>
                    <h2 style="margin:10px 0 8px;font-size:1.1rem"><?= htmlspecialchars($labels['analysis_mode'][$preferences['analysis_mode']] ?? ucfirst($preferences['analysis_mode'])) ?> · <?= htmlspecialchars($labels['output_format'][$preferences['output_format']] ?? ucfirst($preferences['output_format'])) ?> · <?= htmlspecialchars($labels['summary_depth'][$preferences['summary_depth']] ?? ucfirst($preferences['summary_depth'])) ?></h2>
                    <p style="color:var(--color-text-muted);font-size:0.875rem"><?= (int)$preferences['count'] ?> completed <?= (int)$preferences['count'] === 1 ? 'summary' : 'summaries' ?> with this combination.</p>
                    <a class="global-button-effect" style="margin-top:12px;display:inline-block" href="<?= htmlspecialchars($preferredHref) ?>">Use my preferred settings →</a>
                <?php else: ?>
                    <h2 style="margin:10px 0 8px;font-size:1.1rem">No preference yet</h2>
                    <p style="color:var(--color-text-muted);font-size:0.875rem">Complete a summary and your preferred settings will appear here.</p>
                    <a class="global-button-effect" style="margin-top:12px;display:inline-block" href="summarizer.php">Create a summary →</a>
                <?php endif; ?>
            </article>

            <article style="border:1px solid var(--color-border, #ddd);border-radius:18px;padding:24px;background:var(--color-surface);color:var(--color-text)">
                <p class="nex-section-label">Recent summaries</p>
                <?php if (!$recent): ?>
                    <h2 style="margin:10px 0 8px;font-size:1.1rem">No summaries yet</h2>
                    <p style="color:var(--color-text-muted);font-size:0.875rem">Your completed documents will appear here.</p>
                <?php else: ?>
                    <ul style="padding:0;list-style:none;margin:12px 0 0">
                        <?php foreach ($recent as $item): ?>
                            <li style="padding:10px 0;border-bottom:1px solid #eee">
                                <a href="result.php?token=<?= urlencode((string)$item['share_token']) ?>" style="font-weight:600;color:var(--color-text)"><?= htmlspecialchars((string)$item['article_title']) ?></a><br>
                                <small style="color:var(--color-text-muted)"><?= htmlspecialchars((string)$item['created_at']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin-top:12px"><a href="history.php" style="font-size:0.875rem;font-weight:600">View full history →</a></p>
                <?php endif; ?>
            </article>
        </section>

        <?php require __DIR__ . '/partials/analytics-graphs-card.php'; ?>
    </main>

    <?php require __DIR__ . '/partials/nex-footer.php'; ?>

    <script src="assets/js/vendor/chart.umd.min.js"></script>
    <script src="assets/js/dashboard-charts.js?v=<?= filemtime(__DIR__ . '/assets/js/dashboard-charts.js') ?>"></script>
</body>
</html>
