<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';

$csrf_token = generateCsrfToken();
$isAdminLoginContext = (string)($_GET['context'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AI Summarizer</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page-auth-layout">
    <header class="auth-site-header">
        <div class="auth-site-header__inner">
            <?php if ($isAdminLoginContext): ?>
                <span class="auth-site-header__label"></span>
            <?php else: ?>
                <a href="index.php" class="auth-site-header__label"></a>
            <?php endif; ?>

            <?php if ($isAdminLoginContext): ?>
                <div class="auth-site-header__brand" aria-hidden="true">
                    <img
                        src="assets/images/poc-neust-logo.png"
                        alt="Off-Campus POC N.E.U.S.T. logo"
                        class="auth-site-header__brand-logo"
                    >
                </div>
            <?php else: ?>
                <a href="index.php" class="auth-site-header__brand" aria-label="Article Summarizer home">
                    <img
                        src="assets/images/poc-neust-logo.png"
                        alt="Off-Campus POC N.E.U.S.T. logo"
                        class="auth-site-header__brand-logo"
                    >
                </a>
            <?php endif; ?>

            <nav class="auth-site-header__nav" aria-label="Authentication navigation">
                <?php if (!$isAdminLoginContext): ?>
                    <a href="index.php">Home</a>
                <?php endif; ?>
                <a href="register.php">Register</a>
            </nav>
        </div>
    </header>

    <main class="auth-page-shell">


        <section class="auth-panel">
            <div class="auth-wrapper">
                    <div class="auth-card auth-card--space">
                        <div class="auth-brand auth-brand--space">
                            <div class="auth-brand-copy">
                                <span class="auth-brand-copy__eyebrow"></span>
                                <h2>Login</h2>
                        </div>
                    </div>

                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="message message-error">
                            <?= htmlspecialchars($_SESSION['error']) ?>
                            <?php unset($_SESSION['error']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['success'])): ?>
                        <div class="message message-success">
                            <?= htmlspecialchars($_SESSION['success']) ?>
                            <?php unset($_SESSION['success']); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="auth.php?action=login" id="login-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <?php if ($isAdminLoginContext): ?>
                            <input type="hidden" name="login_context" value="admin">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="username">Username or Email</label>
                            <input type="text" id="username" name="username" required placeholder="Enter your username or email" autocomplete="username">
                        </div>

                        <div class="form-group">
                            <div class="pw-label-row">
                                <label for="password">Password</label>
                                <a href="forgot_password.php" class="pw-forgot-link"></a>
                            </div>
                            <div class="pw-input-wrap">
                                <input type="password" id="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
                                <button type="button" class="pw-toggle" id="toggle-password" aria-label="Show password" tabindex="-1">
                                    <svg class="pw-icon pw-icon--show" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="pw-icon pw-icon--hide" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>

                        <label class="consent-box consent-box-standalone remember-me-box">
                            <input type="checkbox" id="remember_me" name="remember_me" value="1">
                            <span class="consent-copy">Keep me signed in for 30 days</span>
                        </label>

                        <button type="submit" class="btn-auth">Login</button>
                    </form>

                    <div class="auth-nav-links">
                        <a href="register.php">Create Account</a>
                        <a href="forgot_password.php">Recover Password</a>
                    </div>
                </div>
            </div>
        </section>
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleBtn = document.getElementById('toggle-password');
            const passwordInput = document.getElementById('password');
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', () => {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    toggleBtn.classList.toggle('pw-visible');
                });
            }
        });
    </script>
</body>
</html>
