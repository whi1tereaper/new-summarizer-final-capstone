<?php
$navUserId = $_SESSION['user_id'] ?? null;
$navGuestToken = $_SESSION['guest_token'] ?? null;
$navUsername = $_SESSION['username'] ?? 'GUEST';
$navIsAdmin = !empty($navUserId) && ($_SESSION['role'] ?? 'user') === 'admin';
$navCurrentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$isLanding = ($navCurrentPage === 'index.php' || $navCurrentPage === '');
$aboutHref = $isLanding ? '#about-light' : 'index.php#about-light';
?>
<link rel="stylesheet" href="assets/css/site-nav.css?v=nex-10">
<header class="nex-header site-header" id="nex-site-header" data-active-theme="light" aria-label="LIGHT navigation">
    <a href="index.php" class="nex-brand" aria-label="LIGHT home">LIGHT</a>

    <!-- Mobile Navigation Toggle -->
    <button type="button" class="nex-menu-toggle" id="nex-menu-toggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="nex-nav-menu">
        <span class="nex-menu-toggle__bar"></span>
        <span class="nex-menu-toggle__bar"></span>
    </button>

    <nav class="nex-header-right site-actions" id="nex-nav-menu" role="navigation" aria-label="Site navigation">
        <?php if (!$navIsAdmin): ?>
            <a href="<?= $aboutHref ?>" class="nex-nav__link<?= $navCurrentPage === 'about.php' ? ' nex-nav__link--active' : '' ?>">About</a>
        <?php endif; ?>
        <a href="summarizer.php" class="nex-nav__link<?= $navCurrentPage === 'summarizer.php' ? ' nex-nav__link--active' : '' ?>">Summarize</a>

        <?php if ($navUserId): ?>
            <?php if ($navIsAdmin): ?>
                <a href="admin_dashboard.php" class="nex-nav__link<?= ($navCurrentPage === 'admin_dashboard.php' || $navCurrentPage === 'admin_audit_logs.php') ? ' nex-nav__link--active' : '' ?>">Admin</a>
                <a href="evaluation.php" class="nex-nav__link<?= in_array($navCurrentPage, ['evaluation.php', 'evaluation_export.php'], true) ? ' nex-nav__link--active' : '' ?>">Evaluation</a>
            <?php endif; ?>
            <a href="analytics.php" class="nex-nav__link<?= $navCurrentPage === 'analytics.php' ? ' nex-nav__link--active' : '' ?>">Analytics</a>
            <a href="history.php" class="nex-nav__link<?= $navCurrentPage === 'history.php' ? ' nex-nav__link--active' : '' ?>">History</a>
            <a href="auth.php?action=logout" class="nex-nav__link js-logout-link">Logout</a>
        <?php else: ?>
            <a href="login.php" class="nex-nav__link<?= $navCurrentPage === 'login.php' ? ' nex-nav__link--active' : '' ?>">Login</a>
            <a href="register.php" class="nex-nav__link<?= $navCurrentPage === 'register.php' ? ' nex-nav__link--active' : '' ?>">Register</a>
        <?php endif; ?>
    </nav>
</header>
<script>
(function() {
    var toggle = document.getElementById('nex-menu-toggle');
    var header = document.getElementById('nex-site-header');
    if (!toggle || !header) return;

    toggle.addEventListener('click', function() {
        var isOpen = header.classList.toggle('is-menu-open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        document.body.classList.toggle('nex-menu-locked', isOpen);
    });

    var links = header.querySelectorAll('.nex-nav__link');
    for (var i = 0; i < links.length; i++) {
        links[i].addEventListener('click', function(e) {
            var href = this.getAttribute('href');
            if (header.classList.contains('is-menu-open')) {
                header.classList.remove('is-menu-open');
                toggle.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('nex-menu-locked');
            }
            if (href && href.startsWith('#')) {
                var target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    setTimeout(function() {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 60);
                }
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && header.classList.contains('is-menu-open')) {
            header.classList.remove('is-menu-open');
            toggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('nex-menu-locked');
        }
    });
})();
</script>
