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
require_once __DIR__ . '/../src/Services/CampusAccessService.php';

$trialService = new \App\Src\Services\CampusAccessService();
$trialToken = $trialService->visitorToken();
$trialStatus = null;
$trialError = '';
try {
    $trialStatus = $trialService->status($userId !== null ? (int)$userId : null, $trialToken);
} catch (\Throwable $e) {
    $trialError = 'Summarization access is unavailable until the access-control setup is complete.';
}
$trialExhausted = $trialStatus !== null && !$trialStatus['continued'] && $trialStatus['remaining'] === 0;
$totalAllowed = \App\Src\Services\CampusAccessService::FREE_LIMIT;
$remainingCount = $trialStatus !== null && isset($trialStatus['remaining']) ? (int)$trialStatus['remaining'] : 0;
$usedCount = max(0, min($totalAllowed, $totalAllowed - $remainingCount));

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require_once __DIR__ . '/partials/design-system-head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $trialExhausted ? 'Workspace Access — DEPENDED' : 'DEPENDED Workspace — DEPENDED' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Shared theme tokens; workspace styles are isolated from other pages. -->
    <link rel="stylesheet" href="assets/css/summarizer-workspace.css?v=<?= filemtime(__DIR__ . '/assets/css/summarizer-workspace.css') ?>">
</head>

<body class="light-workspace dee-workspace depended-workspace nex-workspace-page<?= $isAdmin ? ' page-summary-layout--admin' : '' ?><?= $trialExhausted ? ' trial-exhausted-active' : '' ?>">
    <a class="workspace-skip-link" href="#main-content">Skip to workspace</a>

    <!-- ================================================================
         GLOBAL SITE HEADER
    ================================================================ -->
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <!-- ================================================================
         SUMMARIZER WORKSPACE
    ================================================================ -->
    <main class="nex-summarize-page<?= $trialExhausted ? ' nex-summarize-page--trial-exhausted' : '' ?>" id="main-content">
        <div class="nex-summarize-page__inner">

            <div class="nex-summarize-page__main">
                <div class="nex-section-header">
                    <span class="nex-section-num">01</span>
                    <span class="nex-section-label" id="workspace-header-label"><?= $trialExhausted ? 'WORKSPACE ACCESS' : 'DEPENDED Workspace' ?></span>
                </div>

                <h1 class="nex-summarize__headline" id="workspace-headline"><?= $trialExhausted ? 'Your DEPENDED workspace' : 'DEPENDED Workspace' ?></h1>
                <p class="workspace-subtitle" id="workspace-subtitle"><?= $trialExhausted ? 'Your free trial is complete. Choose how you’d like to continue using DEPENDED.' : 'Transform documents, articles, and text into clear and meaningful summaries.' ?></p>

                <div class="nex-form-shell" id="nex-form-wrapper">
                    <?php require __DIR__ . '/partials/summarizer-form.php'; ?>
                </div>
            </div>

        </div>
    </main>

    <?php require __DIR__ . '/partials/site-footer.php'; ?>

    <?php require __DIR__ . '/partials/summarizer-scripts.php'; ?>
    <script src="assets/js/summarizer-workspace.js?v=<?= filemtime(__DIR__ . '/assets/js/summarizer-workspace.js') ?>" defer></script>
</body>
</html>
