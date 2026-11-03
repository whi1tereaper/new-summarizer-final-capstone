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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require_once __DIR__ . '/partials/design-system-head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DEPENDED — Beyond reading: summarized, translated, and heard.</title>
    <meta name="description" content="Transform lengthy documents and complex information into meaningful summaries through intelligent natural language processing.">
    <meta property="og:title" content="DEPENDED — Beyond reading: summarized, translated, and heard.">
    <meta property="og:description" content="Transform lengthy documents and complex information into meaningful summaries through intelligent natural language processing.">
    <meta property="og:site_name" content="DEPENDED Summarizer">
    <meta property="og:type" content="website">

    <!-- Preload local display font -->
    <link rel="preload" href="assets/fonts/satoshi-900.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/satoshi-700.woff2" as="font" type="font/woff2" crossorigin>

    <!-- Google Fonts fallback (Inter/Outfit for UI) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Design tokens and global base -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <!-- Landing-specific editorial stylesheet -->
    <link rel="stylesheet" href="assets/css/nex-landing.css?v=<?= filemtime(__DIR__ . '/assets/css/nex-landing.css') ?>">

</head>

<body class="nex-landing">

    <!-- ================================================================
         GLOBAL SITE HEADER — Brand LEFT | All navigation RIGHT
    ================================================================ -->
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <main id="landing-main">

        <!-- ============================================================
             SECTION 00 — HERO STAGE
             Original SMART SUMMARY EXPERIENCE hero with 3D knot artwork.
             CSS for .nex-hero-stage / .nex-hero-display / .nex-hero-art-img
             lives in nex-landing.css — do not remove.
        ============================================================ -->
        <section class="nex-hero-stage" id="hero" data-nav-theme="light" aria-label="Hero">

            <!-- Three-line display headline composited with the 3D knot artwork image -->
            <h1 class="nex-hero-display" id="hero-title">
                <span class="visually-hidden">Smart Summary Experience</span>
                <picture>
                    <source srcset="assets/images/light-hero-art.webp" type="image/webp">
                    <img
                        src="assets/images/light-hero-art.png"
                        alt="Smart Summary Experience — SMART / SUMMARY / EXPERIENCE with a glossy 3D intertwined sculpture"
                        class="nex-hero-art-img"
                        width="1024"
                        height="513"
                        fetchpriority="high"
                        decoding="async"
                    >
                </picture>
            </h1>

            <span class="nex-hero-bl" aria-hidden="true">DEPENDED</span>
            <span class="nex-hero-br" aria-hidden="true">SCROLL TO EXPLORE</span>

        </section>

        <!-- ============================================================
             SECTION 02 — START SUMMARIZING  (original teal CTA section)
             Restored immediately after hero — original section order.
             data-nav-theme="accent"
        ============================================================ -->
        <section class="nex-cta" id="start" data-nav-theme="accent" aria-labelledby="cta-heading">

            <!-- Giant background decoration numeral -->
            <span class="nex-cta__bg-num" aria-hidden="true">02</span>

            <div class="nex-section-header">
                <span class="nex-section-num">02</span>
                <span class="nex-section-label">Start Summarizing</span>
            </div>

            <p class="nex-cta__eyebrow">Beyond reading: summarized, translated, and heard.</p>

            <h2 id="cta-heading" class="nex-cta__statement nex-reveal">
                READY TO TURN<br>
                LONG DOCUMENTS<br>
                <span class="nex-cta__line--outline">INTO CLARITY.</span>
            </h2>

            <!-- Full-width pill CTA — routes through existing summarizer access control -->
            <a href="summarizer.php" class="nex-capsule" aria-label="Go to the DEPENDED summarizer workspace">
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

        <!-- ============================================================
             SECTION 03 — ABOUT DEPENDED
             data-nav-theme="light"
        ============================================================ -->
        <span id="about-light" aria-hidden="true"></span>
        <span id="about-dee" aria-hidden="true"></span>
        <span id="about-depended" aria-hidden="true"></span>
        <section class="nex-about" id="about-depended-section" data-nav-theme="light" aria-labelledby="about-title">

            <div class="nex-section-header">
                <span class="nex-section-num">03</span>
                <span class="nex-section-label">About DEPENDED</span>
            </div>

            <div class="nex-about__statement nex-reveal">
                <h2 id="about-title">Beyond reading: summarized, translated, and heard.</h2>
                <p class="nex-about__secondary">Transform lengthy documents and complex information into meaningful summaries through intelligent natural language processing.</p>
            </div>
        </section>

    </main>

    <!-- Editorial Footer (Matches reference aesthetic) -->
    <?php require __DIR__ . '/partials/nex-footer.php'; ?>

    <!-- Landing-specific JS (IntersectionObserver, parallax, smooth-scroll) -->
    <script src="assets/js/nex-landing.js?v=<?= filemtime(__DIR__ . '/assets/js/nex-landing.js') ?>" defer></script>

    <!-- Landing analytics (async, non-blocking) -->
    <script src="assets/js/landing-analytics.js" async></script>

</body>
</html>
