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


$csrf_token = generateCsrfToken();
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
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
    <link rel="stylesheet" href="assets/css/nex-landing.css?v=<?= filemtime(__DIR__ . '/assets/css/nex-landing.css') ?>">
    <link rel="stylesheet" href="assets/css/global-button-effects.css?v=3">
</head>

<body class="nex-landing nex-workspace-page<?= $isAdmin ? ' page-summary-layout--admin' : '' ?>">

    <!-- ================================================================
         GLOBAL SITE HEADER
    ================================================================ -->
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <!-- ================================================================
         SUMMARIZER WORKSPACE
    ================================================================ -->
    <main class="nex-summarize-page" id="main-content">
        <div class="nex-summarize-page__inner">

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

        </div>
    </main>

    <!-- Editorial Footer (Matches landing page) -->
    <?php require __DIR__ . '/partials/nex-footer.php'; ?>

    <?php require __DIR__ . '/partials/summarizer-scripts.php'; ?>
    <script src="assets/js/nex-landing.js?v=<?= filemtime(__DIR__ . '/assets/js/nex-landing.js') ?>" defer></script>
</body>
</html>
