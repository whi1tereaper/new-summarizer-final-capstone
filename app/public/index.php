<?php
require_once __DIR__ . '/../src/whitereaper.php';

// Admin fast-path: redirect to summarizer
$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
if ($isAdmin) {
    header('Location: summarizer.php');
    exit;
}

// Nav session vars expected by site-nav.php partial
$showLightBrand      = true;
$showLandingSections = false; // We're replacing nav with our own .nex-header
$navUserId     = $_SESSION['user_id'] ?? null;
$navGuestToken = $_SESSION['guest_token'] ?? null;
$navUsername   = $_SESSION['username'] ?? 'GUEST';
$navIsAdmin    = ($_SESSION['role'] ?? 'user') === 'admin';
$navCurrentPage = 'index.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LIGHT — AI Document Summarizer</title>
    <meta name="description" content="LIGHT turns complex documents into clear, useful knowledge. Explore the workspace to create a focused summary of your document.">

    <!-- Preload local display font -->
    <link rel="preload" href="assets/fonts/satoshi-900.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/satoshi-700.woff2" as="font" type="font/woff2" crossorigin>

    <!-- Google Fonts fallback (Inter/Outfit for UI) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;900&display=swap" rel="stylesheet">

    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">

    <!-- Design tokens and global base -->
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">

    <!-- Landing-specific editorial stylesheet (new, scoped to .nex-landing) -->
    <link rel="stylesheet" href="assets/css/nex-landing.css?v=18">

</head>

<body class="nex-landing">

    <!-- ================================================================
         GLOBAL SITE HEADER — Brand LEFT | All navigation RIGHT
    ================================================================ -->
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <main id="landing-main">

        <!-- ============================================================
             SECTION 00 — HERO STAGE
             User-supplied hero artwork (SMART SUMMARY EXPERIENCE with 3D knot)
        ============================================================ -->
        <section class="nex-hero-stage" id="hero" data-nav-theme="light" aria-label="Hero">

            <!-- Three-line display headline with composited 3D knot artwork -->
            <h1 class="nex-hero-display" id="hero-title">
                <span class="visually-hidden">Smart Summary Experience</span>
                <picture>
                    <source srcset="assets/images/light-hero-art.webp" type="image/webp">
                    <img
                        src="assets/images/light-hero-art.png"
                        alt="Smart Summary Experience"
                        class="nex-hero-art-img"
                        width="1024"
                        height="513"
                        fetchpriority="high"
                        decoding="async"
                    >
                </picture>
            </h1>

            <span class="nex-hero-bl" aria-hidden="true">LIGHT</span>
            <span class="nex-hero-br" aria-hidden="true">SCROLL TO EXPLORE</span>

        </section>

        <!-- ============================================================
             SECTION 01 — ABOUT LIGHT
             data-nav-theme="light"
        ============================================================ -->
        <section class="nex-about" id="about-light" data-nav-theme="light" aria-labelledby="about-title">

            <div class="nex-section-header">
                <span class="nex-section-num">01</span>
                <span class="nex-section-label">About LIGHT</span>
            </div>

            <div class="nex-about__statement nex-reveal">
                <h2 id="about-title">LIGHT turns complex
documents into clear,
useful knowledge.</h2>
                <p class="nex-about__secondary">Understand more
without reading
everything.</p>
            </div>

            <div class="nex-about__foot">
                <a href="#start" class="nex-circle-btn" aria-label="Scroll to start summarizing">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M10 4v12M4 10l6 6 6-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>

        </section>

        <!-- ============================================================
             SECTION 02 — ACCENT CTA
             data-nav-theme="accent"
        ============================================================ -->
        <section class="nex-cta" id="start" data-nav-theme="accent" aria-labelledby="cta-heading">

            <!-- Giant background decoration number -->
            <span class="nex-cta__bg-num" aria-hidden="true">02</span>

            <div class="nex-section-header">
                <span class="nex-section-num">02</span>
                <span class="nex-section-label">Start Summarizing</span>
            </div>

            <p class="nex-cta__eyebrow">Ready to read less, understand more?</p>

            <h2 id="cta-heading" class="nex-cta__statement nex-reveal">
                READY TO TURN<br>
                LONG DOCUMENTS<br>
                <span class="nex-cta__line--outline">INTO CLARITY.</span>
            </h2>

            <!-- Large capsule CTA — opens the dedicated summarizer workspace -->
            <a href="summarizer.php" class="nex-capsule" aria-label="Go to the summarizer">
                <div class="nex-capsule__left">
                    <span class="nex-capsule__eyebrow">Start Now</span>
                    <span class="nex-capsule__text">Start Summarizing</span>
                </div>
                <span class="nex-capsule__arrow" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M7 17L17 7M17 7H7M17 7V17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
            </a>

        </section>

    </main>

    <!-- Editorial Footer (Matches reference aesthetic) -->
    <?php require __DIR__ . '/partials/nex-footer.php'; ?>

    <!-- Landing-specific JS (IntersectionObserver, parallax, smooth-scroll) -->
    <script src="assets/js/nex-landing.js" defer></script>

    <!-- Landing analytics (async, non-blocking) -->
    <script src="assets/js/landing-analytics.js" async></script>

</body>
</html>
