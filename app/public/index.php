<?php
require_once __DIR__ . '/../src/whitereaper.php';

$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
if ($isAdmin) {
    header('Location: summarizer.php');
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'GUEST';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARTICLE SUMMARIZER</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=Outfit:wght@600;700&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index.css">
</head>

<body class="page-home-layout">
    <header class="site-header">
        <div class="site-header__inner">



            <div class="site-actions">
                <span class="site-actions__welcome">Welcome, <strong><?= htmlspecialchars($username) ?></strong></span>
                <?php if ($userId): ?>
                    <?php if ($isAdmin): ?>
                        <a href="admin_dashboard.php" class="site-actions__link">Admin</a>
                    <?php endif; ?>
                    <a href="history.php" class="site-actions__link">History</a>
                    <a href="auth.php?action=logout" class="site-actions__button js-logout-link">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="site-actions__link">Login</a>
                    <a href="register.php" class="site-actions__button site-actions__button--register">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="landing-shell">
        <section class="hero-panel" aria-labelledby="hero-title">
            <div class="hero-panel__content">
                <div class="hero-copy">

                    <h1 class="font-brand-heading" id="hero-title">Research in Focus<br>Clarity in<br>Every Summary</h1>
                    <p class="hero-copy__lead font-ui-body">
                        Upload a source file or paste article text, then generate a summary with the structure
                        and length that fits your workflow.
                    </p>
                    <a href="summarizer.php" class="btn-glitch-fill">
                        <span class="text">Get started</span>
                    </a>
                </div>

                <div class="hero-art" aria-hidden="true">
                    <div class="hero-art__frame">
                        <div class="hero-art__shape hero-art__shape--back"></div>
                        <div class="hero-art__shape hero-art__shape--front"></div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php require __DIR__ . '/partials/site-footer.php'; ?>

<script src="assets/js/landing-analytics.js" async></script>
<script src="assets/js/index.js"></script>

</body>
</html>
