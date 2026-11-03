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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require_once __DIR__ . '/partials/design-system-head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isGuestViewer ? 'Guest Summary History — DEPENDED' : 'History — DEPENDED' ?></title>
    <link rel="preload" href="assets/fonts/satoshi-900.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/satoshi-700.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css?v=navbar-3-<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/site-nav.css?v=<?= filemtime(__DIR__ . '/assets/css/site-nav.css') ?>">
    <link rel="stylesheet" href="assets/css/history.css?v=<?= filemtime(__DIR__ . '/assets/css/history.css') ?>">
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
                    <a href="result.php?id=<?php echo $item['id']; ?>&amp;share=<?php echo urlencode((string)($item['share_token'] ?? '')); ?>" class="btn btn--secondary btn-view">Access Full Report</a>
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
