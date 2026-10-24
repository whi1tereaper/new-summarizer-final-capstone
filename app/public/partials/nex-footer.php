<?php
// Shared editorial footer matching NexStudio aesthetic specification
$footerPrivacyContact=(string)config('student_access.privacy_contact','');
?>
<link rel="stylesheet" href="assets/css/site-footer.css?v=<?= filemtime(__DIR__ . '/../assets/css/site-footer.css') ?>">

<footer class="nex-footer" data-nav-theme="light" role="contentinfo">
    <div class="nex-footer__inner">

        <!-- 3-Column Top Grid -->
        <div class="nex-footer__grid">

            <!-- Col 1: EMAIL & MISSION -->
            <div class="nex-footer__col nex-footer__col--main">
                <div class="nex-footer__col-head">
                    <span class="nex-footer__col-title">SUPPORT &amp; PRIVACY</span>
                </div>
                <div class="nex-footer__col-body">
                    <?php if(filter_var($footerPrivacyContact,FILTER_VALIDATE_EMAIL)): ?>
                    <a href="mailto:<?= htmlspecialchars($footerPrivacyContact,ENT_QUOTES,'UTF-8') ?>" class="nex-footer__email"><?= htmlspecialchars($footerPrivacyContact,ENT_QUOTES,'UTF-8') ?></a>
                    <?php else: ?><a href="terms.php#student-privacy" class="nex-footer__email">Privacy contact not configured</a><?php endif; ?>
                    <p class="nex-footer__tagline"></p>
                    <p class="nex-footer__tagline">
                        Giving documents, summaries, and <strong>articles</strong> the room to speak for themselves.
                    </p>
                </div>
            </div>

            <!-- Col 2: SOCIALS -->
            <div class="nex-footer__col">
                <div class="nex-footer__col-head">
                    <span class="nex-footer__col-title">SOCIALS</span>
                </div>
                <ul class="nex-footer__links">
                    <li><a href="https://facebook.com" target="_blank" rel="noopener noreferrer" class="nex-footer__link">Facebook</a></li>
                    <li><a href="https://instagram.com" target="_blank" rel="noopener noreferrer" class="nex-footer__link">Instagram</a></li>
                    <li><a href="https://tiktok.com" target="_blank" rel="noopener noreferrer" class="nex-footer__link">TikTok</a></li>
                </ul>
            </div>

            <!-- Col 3: INFORMATION -->
            <div class="nex-footer__col">
                <div class="nex-footer__col-head">
                    <span class="nex-footer__col-title">INFORMATION</span>
                </div>
                <ul class="nex-footer__links">
                    <li><a href="accept_terms.php" class="nex-footer__link">Terms and Conditions</a></li>
                </ul>
            </div>

        </div>

        <!-- Giant Brand Title -->
        <div class="nex-footer__brand-wrap">
            <div class="nex-footer__giant-brand" id="footer-light-brand" aria-label="LIGHT">
                LIGHT
            </div>
        </div>

        <!-- Bottom Copyright Rule -->
        <div class="nex-footer__bottom">
            <p class="nex-footer__copyright">
                &copy; <?= date('Y') ?> Light Summarizer &mdash; All rights reserved.
            </p>
            <a href="#top" class="nex-footer__back js-back-top" aria-label="Back to top" onclick="window.scrollTo({top: 0, behavior: 'smooth'}); return false;">
                BACK TO TOP &uarr;
            </a>
        </div>

    </div>
</footer>
