<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once '../src/Controllers/HistoryHandler.php';
require_once '../src/Utils/Formatter.php';

use App\Src\Controllers\HistoryHandler;
use App\Src\Utils\Formatter;

$userId       = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$guestToken   = $_SESSION['guest_token'] ?? null;
$isGuestViewer = $userId === null;
$isAdmin      = ($_SESSION['role'] ?? 'user') === 'admin';

if ($userId === null || $userId < 1) {
    $_SESSION['error'] = 'Create an account to access your history.';
    header('Location: register.php');
    exit;
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$history = HistoryHandler::getSummaryHistory($userId, $guestToken, $page);
$nutshellHistory = HistoryHandler::getNutshellHistory($userId, $guestToken);

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isGuestViewer ? 'Guest Summary History' : 'History — LIGHT' ?></title>
    <link rel="preload" href="assets/fonts/satoshi-900.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/satoshi-700.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css?v=navbar-3">
    <link rel="stylesheet" href="assets/css/site-nav.css?v=3">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
    <link rel="stylesheet" href="assets/css/global-button-effects.css?v=2">
    <style>
        body { 
            background: var(--color-bg, #EEEDEA); 
            color: var(--color-text, #080808); 
            font-family: var(--font-body, 'Inter', system-ui, sans-serif); 
            -webkit-font-smoothing: antialiased;
        }
        main.editorial-layout { max-width: 960px; margin: 48px auto; padding: 0 clamp(20px, 4vw, 48px); }
        h1 { 
            font-size: clamp(2.2rem, 5vw, 3.2rem); 
            margin: 0 0 40px 0; 
            border-bottom: 1px solid var(--color-border, rgba(8, 8, 8, 0.14));
            padding-bottom: 18px;
            font-family: var(--font-editorial, 'Satoshi', 'Outfit', 'Inter', sans-serif);
            font-weight: 900;
            letter-spacing: -0.04em;
            line-height: 1;
            color: var(--color-text, #080808);
        }

        .history-list { display: flex; flex-direction: column; gap: 48px; }
        .history-item { border-bottom: 1px solid var(--color-border, rgba(8, 8, 8, 0.12)); padding-bottom: 36px; }
        .history-item:last-child { border-bottom: none; }

        .article-title { 
            font-family: var(--font-editorial, 'Satoshi', 'Outfit', 'Inter', system-ui, sans-serif); 
            font-size: clamp(1.4rem, 2.5vw, 1.85rem); 
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0 0 10px 0; 
            line-height: 1.18;
            color: var(--color-text, #080808);
        }
        .article-title a { text-decoration: none; color: var(--color-text, #080808); transition: color 200ms ease; }
        .article-title a:hover { color: var(--color-accent, #7F00FF); text-decoration: none; }

        .meta-line { 
            font-size: 0.72rem; 
            color: var(--color-text-muted, rgba(8, 8, 8, 0.48)); 
            text-transform: uppercase; 
            letter-spacing: 0.08em; 
            margin-bottom: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-family: var(--font-mono, 'JetBrains Mono', monospace);
            font-weight: 500;
        }
        .meta-line span:first-child { color: var(--color-accent, #7F00FF); font-weight: 700; }
        .meta-line strong { color: var(--color-text, #080808); }

        .summary-preview { 
            font-family: var(--font-body, 'Inter', system-ui, sans-serif);
            font-size: 1.02rem; 
            color: var(--color-text-secondary, rgba(8, 8, 8, 0.72)); 
            margin-top: 14px;
            line-height: 1.7;
            letter-spacing: -0.005em;
            max-width: 78ch;
        }

        .btn-view {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 18px;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--color-accent, #7F00FF);
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: var(--font-display, 'Satoshi', 'Outfit', sans-serif);
            transition: color 200ms ease, transform 200ms ease;
        }

        .btn-view:hover { color: var(--color-accent-hover, #6a00d8); transform: translateX(3px); }

        .empty-state { text-align: left; margin: 60px 0; color: var(--color-text-muted); font-style: italic; }

        /* ─── History Responsive ─── */
        @media (max-width: 768px) {
            nav { gap: 16px; padding-bottom: 14px; }

            h1 {
                font-size: 1.8em;
                margin-bottom: 30px;
            }

            .article-title {
                font-size: 1.4em;
            }

            .history-list {
                gap: 40px;
            }

            .history-item {
                padding-bottom: 28px;
            }

            .summary-preview {
                font-size: 1em;
            }
        }

        @media (max-width: 576px) {
            nav { gap: 12px; padding-bottom: 12px; margin-bottom: 30px; }

            h1 {
                font-size: 1.4em;
                margin-bottom: 24px;
                padding-bottom: 10px;
            }

            .article-title {
                font-size: 1.2em;
            }

            .meta-line {
                gap: 10px;
                font-size: 0.7em;
            }

            .history-list {
                gap: 30px;
            }

            .history-item {
                padding-bottom: 20px;
            }

            .summary-preview {
                font-size: 0.92em;
            }
        }
        /* ── Analytics section (admin-only) ───────────────────────────── */
        .an-divider {
            margin: 70px 0 0;
            border: none;
            border-top: 2px solid var(--color-accent);
        }

        .an-section-eyebrow {
            font-family: 'Inter', sans-serif;
            font-size: 0.68em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--color-accent);
            margin: 28px 0 6px;
        }

        .an-section-title {
            font-family: 'Outfit', 'Inter', sans-serif;
            font-size: 1.6em;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1;
            color: var(--color-text);
            margin: 0 0 32px;
            border-bottom: none;
            padding-bottom: 0;
        }

        .an-section-label {
            font-family: 'Inter', sans-serif;
            font-size: 0.65em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--color-text-muted);
            margin: 36px 0 14px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .an-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--color-border);
        }

        /* KPI cards */
        .an-kpi-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
        }

        @media (max-width: 900px) { .an-kpi-row { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 540px) { .an-kpi-row { grid-template-columns: repeat(2, 1fr); } }

        .an-kpi {
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            padding: 18px 16px 14px;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }

        .an-kpi::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 3px; height: 100%;
            background: var(--color-accent);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .an-kpi:hover::before { opacity: 1; }

        .an-kpi__label {
            font-family: 'Inter', sans-serif;
            font-size: 0.6em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--color-text-muted);
            margin-bottom: 8px;
        }

        .an-kpi__val {
            font-family: var(--font-editorial, 'Satoshi', 'Outfit', 'Inter', sans-serif);
            font-size: 1.8em;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1;
            color: var(--color-text);
        }

        .an-kpi__val.kpi-good { color: var(--color-success); }
        .an-kpi__val.kpi-warn { color: var(--color-warning); }
        .an-kpi__val.kpi-bad  { color: var(--color-danger); }

        .an-kpi__sub {
            font-family: 'Inter', sans-serif;
            font-size: 0.65em;
            color: var(--color-text-muted);
            margin-top: 5px;
        }

        /* Two-col panels */
        .an-two-col {
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            gap: 18px;
            margin-bottom: 18px;
        }

        @media (max-width: 680px) { .an-two-col { grid-template-columns: 1fr; } }

        .an-panel {
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            padding: 20px 22px;
            box-shadow: var(--shadow-sm);
        }

        .an-panel__title {
            font-family: 'Inter', sans-serif;
            font-size: 0.62em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--color-text-muted);
            margin: 0 0 16px;
        }

        /* Device bars */
        .dv-row { display: grid; grid-template-columns: 58px 1fr 36px; align-items: center; gap: 8px; margin-bottom: 12px; }
        .dv-row:last-child { margin-bottom: 0; }
        .dv-label { font-family: 'Inter', sans-serif; font-size: 0.7em; font-weight: 600; color: var(--color-text); text-transform: uppercase; letter-spacing: 0.5px; }
        .dv-track { background: var(--color-bg-tertiary); height: 7px; overflow: hidden; }
        .dv-fill  { height: 100%; background: var(--color-accent); transition: width 0.5s ease; }
        .dv-pct   { font-family: 'Inter', sans-serif; font-size: 0.7em; font-weight: 600; color: var(--color-accent); text-align: right; }

        /* Funnel */
        .fn-grid { display: flex; align-items: flex-end; gap: 10px; height: 110px; }
        .fn-col   { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; justify-content: flex-end; }
        .fn-track { width: 100%; background: var(--color-bg-tertiary); height: 70px; display: flex; align-items: flex-end; overflow: hidden; }
        .fn-bar   { width: 100%; background: var(--color-accent); min-height: 3px; transition: height 0.5s ease; }
        .fn-pct   { font-family: var(--font-editorial, 'Satoshi', 'Outfit', 'Inter', sans-serif); font-size: 0.78em; font-weight: 700; color: var(--color-accent); margin-top: 6px; }
        .fn-lbl   { font-family: 'Inter', sans-serif; font-size: 0.56em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin-top: 3px; text-align: center; }

        /* Sparkline */
        .sp-wrap  { background: var(--color-bg-secondary); border: 1px solid var(--color-border); padding: 20px 22px; box-shadow: var(--shadow-sm); margin-bottom: 18px; }
        .sp-hdr   { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; }
        .sp-peak  { font-family: 'Inter', sans-serif; font-size: 0.7em; color: var(--color-text-muted); }
        .sp-svg   { width: 100%; height: 80px; display: block; overflow: visible; }
        .sp-axis  { display: flex; justify-content: space-between; font-family: 'Inter', sans-serif; font-size: 0.65em; color: var(--color-text-muted); margin-top: 5px; }

        /* Referrer table */
        .ref-tbl  { width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; font-size: 0.78em; }
        .ref-tbl th { font-size: 0.65em; text-transform: uppercase; letter-spacing: 1px; color: var(--color-text-muted); font-weight: 600; border-bottom: 1px solid var(--color-border); padding: 0 6px 7px; text-align: left; }
        .ref-tbl td { padding: 8px 6px; border-bottom: 1px solid var(--color-border); color: var(--color-text); }
        .ref-tbl tr:last-child td { border-bottom: none; }
        .ref-tbl tr:hover td { background: var(--color-bg-tertiary); }
        .ref-domain { font-family: var(--font-mono); font-size: 0.88em; }
        .an-empty { font-family: 'Inter', sans-serif; font-size: 0.8em; color: var(--color-text-muted); text-align: center; padding: 16px 0; }
    </style>
</head>
<body class="history-page">

<?php $showLightBrand = true; ?>
<?php require __DIR__ . '/partials/site-nav.php'; ?>
<main class="editorial-layout">
    <h1><?= $isGuestViewer ? 'Guest Summary History' : 'History' ?></h1>

    <?php if (isset($history['error'])): ?>
        <p style="color: var(--color-danger); border-left: 4px solid var(--color-danger); margin: 30px 0; font-size: 0.9em; padding-left: 20px;">
            <strong>NOTIFICATION:</strong> <?php echo htmlspecialchars($history['error']); ?>
        </p>
    <?php elseif (empty($history)): ?>
        <div class="empty-state">
            <p>History remains vacant. Initiate a <a href="index.php" style="color: var(--color-primary-dark); font-weight: bold;">new document analysis</a> to begin.</p>
        </div>
    <?php else: ?>
        <div class="history-list">
            <?php foreach ($history as $item): ?>
                <div class="history-item">
                    <h2 class="article-title">
                        <a href="result.php?id=<?php echo $item['id']; ?>&amp;share=<?php echo urlencode((string)($item['share_token'] ?? '')); ?>">
                            <?php echo htmlspecialchars($item['article_title']); ?>
                        </a>
                    </h2>
                    <div class="meta-line">
                        <span>#<?php echo $item['id']; ?></span>
                        <span><?php echo strtoupper(htmlspecialchars($item['input_type'])); ?></span>
                        <span><?php echo date('F d, Y', strtotime($item['created_at'])); ?></span>
                    </div>
                    <div class="summary-preview">
                    <?php
                        $previewText = Formatter::toPlainTextFromStoredSummary((string)($item['generated_summary'] ?? ''));
                        if (mb_strlen($previewText, 'UTF-8') > 280) {
                            $previewText = mb_substr($previewText, 0, 280, 'UTF-8') . '...';
                        }
                        echo htmlspecialchars($previewText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    ?>
                    </div>
                    <a href="result.php?id=<?php echo $item['id']; ?>&amp;share=<?php echo urlencode((string)($item['share_token'] ?? '')); ?>" class="btn-view">Access Full Report</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!isset($nutshellHistory['error']) && !empty($nutshellHistory)): ?>
        <h2 style="margin-top: 70px; border-bottom: 2px solid var(--color-primary-dark); padding-bottom: 15px;">Nutshell History</h2>
        <div class="history-list">
            <?php foreach ($nutshellHistory as $nutshell): ?>
                <div class="history-item">
                    <h2 class="article-title">
                        <?php if (!empty($nutshell['summary_id'])): ?>
                            <a href="result.php?id=<?php echo (int)$nutshell['summary_id']; ?>&amp;share=<?php echo urlencode((string)($nutshell['share_token'] ?? '')); ?>">
                                <?php echo htmlspecialchars((string)($nutshell['article_title'] ?? 'Document')); ?>
                            </a>
                        <?php else: ?>
                            <?php echo htmlspecialchars((string)($nutshell['input_type'] ?? 'Document')); ?>
                        <?php endif; ?>
                    </h2>
                    <div class="meta-line">
                        <span>NUTSHELL</span>
                        <span><?php echo date('F d, Y', strtotime($nutshell['created_at'])); ?></span>
                        <span><?php echo (int)$nutshell['word_count']; ?> WORDS</span>
                    </div>
                    <?php if (!empty($nutshell['nutshell_text'])): ?>
                        <div class="summary-preview"><?php echo htmlspecialchars((string)$nutshell['nutshell_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
