<?php
require_once __DIR__ . '/../src/whitereaper.php';

$showLightBrand = true;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Learn how LIGHT helps you turn long documents and articles into focused, useful summaries.">
    <title>About LIGHT — Article Summarizer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/site-nav.css?v=2">
    <link rel="stylesheet" href="assets/css/about.css">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
</head>
<body class="about-page">
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <main>
        <section class="about-hero" aria-labelledby="about-title">
            <div class="about-hero__content">
                <p class="about-eyebrow">ABOUT LIGHT</p>
                <h1 id="about-title">Make room for <span>what matters.</span></h1>
                <p class="about-hero__lead">
                    LIGHT is an article summarizer built to help you move through dense reading
                    with more focus and less friction.
                </p>
                <a href="summarizer.php" class="about-button">Start summarizing</a>
            </div>
            <div class="about-hero__mark" aria-hidden="true">
                <span>LIGHT</span>
                <i></i>
            </div>
        </section>

        <section class="about-section about-section--split" aria-labelledby="mission-title">
            <div class="about-section__heading">
                <h2 id="mission-title">Clarity should be part of the process.</h2>
            </div>
            <div class="about-section__copy">
                <p>
                    Research, reports, and articles often contain the insight you need, but
                    finding it can take time. LIGHT gives you a focused starting point by
                    turning source material into summaries that are easier to scan, understand,
                    and use.
                </p>
                <p>
                    We designed the experience around the reader: bring your own document or
                    article, choose the format that fits your task, and keep the important
                    ideas close at hand.
                </p>
            </div>
        </section>

        <section class="about-section about-section--process" aria-labelledby="process-title">
            <div class="about-section__heading">
                <p class="about-eyebrow">HOW IT WORKS</p>
                <h2 id="process-title">From source to signal.</h2>
            </div>
            <div class="about-process-grid">
                <article class="about-process-card">
                    <span class="about-process-card__number">01</span>
                    <h3>Bring your source</h3>
                    <p>Upload a supported file or paste article text into the summarizer.</p>
                </article>
                <article class="about-process-card">
                    <span class="about-process-card__number">02</span>
                    <h3>Set your direction</h3>
                    <p>Choose the summary style and length that fit the way you work.</p>
                </article>
                <article class="about-process-card">
                    <span class="about-process-card__number">03</span>
                    <h3>Keep your focus</h3>
                    <p>Review the result, refine your understanding, and move forward with confidence.</p>
                </article>
            </div>
        </section>

        <section class="about-section about-section--principles" aria-labelledby="principles-title">
            <div class="about-section__heading">
                <h2 id="principles-title">Useful by design.</h2>
            </div>
            <div class="about-principles">
                <div class="about-principle">
                    <h3>Focus over noise</h3>
                    <p>Every part of the experience is shaped to help you find the central ideas sooner.</p>
                </div>
                <div class="about-principle">
                    <h3>Control for the reader</h3>
                    <p>Your source and your workflow come first. LIGHT helps you choose how to work through a text.</p>
                </div>
                <div class="about-principle">
                    <h3>Progress you can use</h3>
                    <p>A summary is a starting point for thinking, studying, discussing, and deciding, not a replacement for reading critically.</p>
                </div>
            </div>
        </section>

    </main>

    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
