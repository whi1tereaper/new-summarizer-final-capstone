<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';

$csrf_token = generateCsrfToken();
$isAdminLoginContext = (string)($_GET['context'] ?? '') === 'admin';
$navGuestToken = $_SESSION['guest_token'] ?? null;
$navUserId = $_SESSION['user_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — LIGHT</title>
    <meta name="description" content="Sign in to LIGHT document summarizer to access your AI summaries, custom profiles, and history.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
</head>
<body class="page-auth-layout">
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <main class="auth-page-shell">
        <section class="auth-panel" aria-label="Sign in form">
            <div class="auth-wrapper">
                <div class="auth-card">

                    <!-- Mode Switcher -->
                    <nav class="auth-segmented-switch" aria-label="Account access options">
                        <span class="auth-switch-tab auth-switch-tab--active" aria-current="page">Sign In</span>
                        <a href="register.php" class="auth-switch-tab">Create Account</a>
                    </nav>

                    <!-- Card Header -->
                    <div class="auth-card-header">
                        <h1 class="auth-card-title"><?= $isAdminLoginContext ? 'Admin Sign In' : 'Sign in to LIGHT' ?></h1>
                        <p class="auth-card-desc"><?= $isAdminLoginContext ? 'Enter administrator credentials to access system management.' : 'Enter your credentials to access your document workspace.' ?></p>
                    </div>

                    <?php if ($isAdminLoginContext): ?>
                        <div class="auth-admin-banner" role="status">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            <span>Sign in with your administrator account.</span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="message message-error" role="alert">
                            <svg class="message-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
                            <?php unset($_SESSION['error']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['success'])): ?>
                        <div class="message message-success" role="status">
                            <svg class="message-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span><?= htmlspecialchars($_SESSION['success']) ?></span>
                            <?php unset($_SESSION['success']); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="auth.php?action=login" id="login-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <?php if ($isAdminLoginContext): ?>
                            <input type="hidden" name="login_context" value="admin">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="username">Username or Email</label>
                            <div class="auth-input-group">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                </span>
                                <input type="text" id="username" name="username" required placeholder="Enter username or email" autocomplete="username" autofocus>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="pw-label-row">
                                <label for="password">Password</label>
                                <a href="forgot_password.php" class="pw-forgot-link">Forgot password?</a>
                            </div>
                            <div class="auth-input-group pw-input-wrap">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                </span>
                                <input type="password" id="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
                                <button type="button" class="pw-toggle" id="toggle-password" aria-label="Toggle password visibility" tabindex="-1">
                                    <svg class="pw-icon pw-icon--show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="pw-icon pw-icon--hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>

                        <div class="auth-meta-row">
                            <label class="consent-box consent-box-standalone remember-me-box">
                                <input type="checkbox" id="remember_me" name="remember_me" value="1">
                                <span class="consent-copy">Keep me signed in for 30 days</span>
                            </label>
                        </div>

                        <button type="submit" class="btn-auth btn-auth-enhanced" id="btn-submit-auth">
                            <span class="btn-auth__text">Sign In</span>
                            <span class="btn-auth__icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </span>
                        </button>
                    </form>

                    <div class="auth-nav-links">
                        <span>Don't have an account? <a href="register.php">Create Account</a></span>
                        <a href="forgot_password.php">Reset password</a>
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
                    const isPassword = passwordInput.getAttribute('type') === 'password';
                    passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                    toggleBtn.classList.toggle('pw-visible', isPassword);
                    toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                });
            }

            const loginForm = document.getElementById('login-form');
            const submitBtn = document.getElementById('btn-submit-auth');
            if (loginForm && submitBtn) {
                loginForm.addEventListener('submit', (e) => {
                    const username = document.getElementById('username').value.trim();
                    const password = document.getElementById('password').value;
                    if (!username || !password) {
                        return; // Let browser HTML5 validation or server handle empty check
                    }
                    submitBtn.classList.add('is-submitting');
                    const textSpan = submitBtn.querySelector('.btn-auth__text');
                    if (textSpan) {
                        textSpan.textContent = 'Signing in...';
                    }
                });
            }
        });
    </script>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
