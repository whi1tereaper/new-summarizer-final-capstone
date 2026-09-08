<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join - AI Summarizer</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page-auth-layout">
    <header class="auth-site-header">
        <div class="auth-site-header__inner">
            <a href="index.php" class="auth-site-header__label"></a>

            <a href="index.php" class="auth-site-header__brand" aria-label="Article Summarizer home">
                <img
                    src="assets/images/poc-neust-logo.png"
                    alt="Off-Campus POC N.E.U.S.T. logo"
                    class="auth-site-header__brand-logo"
                >
            </a>

            <nav class="auth-site-header__nav" aria-label="Authentication navigation">
                <a href="index.php">Home</a>
                <a href="login.php">Login</a>
            </nav>
        </div>
    </header>

    <main class="auth-page-shell">


        <section class="auth-panel">
            <div class="auth-wrapper">
                <div class="auth-card auth-card--space">
                    <div class="auth-brand auth-brand--space">
                        <div class="auth-brand-copy">
                            <span class="auth-brand-copy__eyebrow">New Account</span>
                            <h2>Create Account</h2>
                        </div>
                    </div>
                    
                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="message message-error">
                            <?= htmlspecialchars($_SESSION['error']) ?>
                            <?php unset($_SESSION['error']); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="auth.php?action=register">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        
                        <div class="form-group">
                            <label for="username">Preferred Username</label>
                            <input type="text" id="username" name="username" required placeholder="At least 3 characters">
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required placeholder="name@example.com">
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="pw-input-wrap">
                                <input type="password" id="password" name="password" required placeholder="8+ chars, incl. numbers" autocomplete="new-password">
                                <button type="button" class="pw-toggle" id="toggle-password" aria-label="Show password" tabindex="-1">
                                    <svg class="pw-icon pw-icon--show" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="pw-icon pw-icon--hide" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
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
                            <div class="pw-input-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" required placeholder="Retype password" autocomplete="new-password">
                                <button type="button" class="pw-toggle" id="toggle-confirm" aria-label="Show password" tabindex="-1">
                                    <svg class="pw-icon pw-icon--show" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="pw-icon pw-icon--hide" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
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

                        <button type="submit" class="btn-auth">Register Now</button>
                    </form>

                    <div class="auth-nav-links">
                        <span>Already have an account?</span>
                        <a href="login.php">Login here</a>
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
                        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                        input.setAttribute('type', type);
                        btn.classList.toggle('pw-visible');
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
            
            // Prevent form submission if weak
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', (e) => {
                    const val = pwInput.value;
                    const hasNumber = /[0-9]/.test(val);
                    const hasUpper = /[A-Z]/.test(val);
                    const hasSpecial = /[^a-zA-Z0-9]/.test(val);
                    
                    if (val.length < 8 || !hasNumber || (!hasUpper && !hasSpecial)) {
                        e.preventDefault();
                        alert('Password is too weak. Please add an uppercase letter or special character.');
                    }
                });
            }
        });
    </script>
</body>
</html>
