<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — LIGHT</title>
    <meta name="description" content="Create your LIGHT account to summarize documents, save history, and view analytics.">
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
        <section class="auth-panel" aria-label="Registration form">
            <div class="auth-wrapper">
                <div class="auth-card">

                    <!-- Mode Switcher -->
                    <nav class="auth-segmented-switch" aria-label="Account access options">
                        <a href="login.php" class="auth-switch-tab">Sign In</a>
                        <span class="auth-switch-tab auth-switch-tab--active" aria-current="page">Create Account</span>
                    </nav>

                    <!-- Card Header -->
                    <div class="auth-card-header">
                        <h1 class="auth-card-title">Create Account</h1>
                        <p class="auth-card-desc">Sign up to unlock custom summarization profiles, history, and document export.</p>
                    </div>
                    
                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="message message-error" role="alert">
                            <svg class="message-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
                            <?php unset($_SESSION['error']); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="auth.php?action=register" id="register-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        
                        <div class="form-group">
                            <label for="username">Preferred Username</label>
                            <div class="auth-input-group">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                </span>
                                <input type="text" id="username" name="username" class="auth-input" required placeholder="At least 3 characters" autocomplete="username" autofocus>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <div class="auth-input-group">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                </span>
                                <input type="email" id="email" name="email" class="auth-input" required placeholder="name@example.com" autocomplete="email">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="auth-input-group pw-input-wrap">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                </span>
                                <input type="password" id="password" name="password" class="auth-input" required placeholder="8+ chars, incl. numbers" autocomplete="new-password">
                                <button type="button" class="pw-toggle" id="toggle-password" aria-label="Toggle password visibility" tabindex="-1">
                                    <svg class="pw-icon pw-icon--show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="pw-icon pw-icon--hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                            <div class="pw-strength" id="pw-strength-container" aria-hidden="true">
                                <div class="pw-strength__bar">
                                    <div class="pw-strength__fill" id="pw-strength-fill"></div>
                                </div>
                                <span class="pw-strength__label" id="pw-strength-label"></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="auth-input-group pw-input-wrap">
                                <span class="auth-input-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                </span>
                                <input type="password" id="confirm_password" name="confirm_password" class="auth-input" required placeholder="Retype password" autocomplete="new-password">
                                <button type="button" class="pw-toggle" id="toggle-confirm" aria-label="Toggle password visibility" tabindex="-1">
                                    <svg class="pw-icon pw-icon--show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="pw-icon pw-icon--hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>

                        <label class="consent-box consent-box-standalone">
                            <input type="checkbox" id="terms_accept" name="terms_accept" value="1" required>
                            <span class="consent-copy">
                                I have read and agree to the
                                <a href="terms.php" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>.
                            </span>
                        </label>

                        <button type="submit" class="btn-auth btn-auth-enhanced" id="btn-submit-register">
                            <span class="btn-auth__text">Create Account</span>
                            <span class="btn-auth__icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </span>
                        </button>
                    </form>

                    <div class="auth-nav-links">
                        <span>Already have an account? <a href="login.php">Sign In</a></span>
                    </div>

                </div>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const setupToggle = (btnId, inputId) => {
                const btn = document.getElementById(btnId);
                const input = document.getElementById(inputId);
                if (btn && input) {
                    btn.addEventListener('click', () => {
                        const isPassword = input.getAttribute('type') === 'password';
                        input.setAttribute('type', isPassword ? 'text' : 'password');
                        btn.classList.toggle('pw-visible', isPassword);
                        btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                    });
                }
            };

            setupToggle('toggle-password', 'password');
            setupToggle('toggle-confirm', 'confirm_password');

            const pwInput = document.getElementById('password');
            const pwFill = document.getElementById('pw-strength-fill');
            const pwLabel = document.getElementById('pw-strength-label');
            const pwContainer = document.getElementById('pw-strength-container');

            if (pwInput && pwFill && pwLabel && pwContainer) {
                pwInput.addEventListener('input', (e) => {
                    const val = e.target.value;
                    if (val.length === 0) {
                        pwContainer.classList.remove('is-active');
                        return;
                    }
                    
                    pwContainer.classList.add('is-active');
                    let score = 0;
                    
                    if (val.length >= 8) {
                        const hasNumber = /[0-9]/.test(val);
                        const hasUpper = /[A-Z]/.test(val);
                        const hasSpecial = /[^a-zA-Z0-9]/.test(val);

                        if (hasNumber) score += 1;
                        if (hasUpper) score += 1;
                        if (hasSpecial) score += 1;
                    }

                    pwFill.className = 'pw-strength__fill';
                    
                    if (val.length < 8 || score < 2) {
                        pwFill.classList.add('pw-strength__fill--weak');
                        pwLabel.textContent = 'Weak';
                    } else if (score === 2) {
                        pwFill.classList.add('pw-strength__fill--medium');
                        pwLabel.textContent = 'Medium';
                    } else if (score >= 3) {
                        pwFill.classList.add('pw-strength__fill--strong');
                        pwLabel.textContent = 'Strong';
                    }
                });
            }

            const regForm = document.getElementById('register-form');
            const regSubmit = document.getElementById('btn-submit-register');
            if (regForm && regSubmit) {
                regForm.addEventListener('submit', (e) => {
                    const val = pwInput ? pwInput.value : '';
                    const hasNumber = /[0-9]/.test(val);
                    const hasUpper = /[A-Z]/.test(val);
                    const hasSpecial = /[^a-zA-Z0-9]/.test(val);
                    
                    if (val.length < 8 || !hasNumber || (!hasUpper && !hasSpecial)) {
                        e.preventDefault();
                        alert('Password is too weak. Please ensure it is at least 8 characters and includes numbers and uppercase letters or symbols.');
                        return;
                    }

                    const terms = document.getElementById('terms_accept');
                    if (terms && !terms.checked) {
                        e.preventDefault();
                        alert('Please accept the Terms and Conditions to proceed.');
                        return;
                    }

                    regSubmit.classList.add('is-submitting');
                    const textSpan = regSubmit.querySelector('.btn-auth__text');
                    if (textSpan) {
                        textSpan.textContent = 'Creating account...';
                    }
                });
            }
        });
    </script>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
