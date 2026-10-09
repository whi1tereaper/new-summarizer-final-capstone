<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';

$csrf_token = generateCsrfToken();
$prefilledEmail = $_SESSION['reset_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — LIGHT</title>
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
        <section class="auth-panel">
            <div class="auth-wrapper">
                <div class="auth-card">
                    <div class="auth-brand">
                        <div class="auth-brand-copy">
                            <span class="auth-brand-copy__eyebrow">NEW CREDENTIALS</span>
                            <h2>Set New Password</h2>
                        </div>
                    </div>
                    <p style="font-family:'Inter', sans-serif; font-size:0.9rem; color:rgba(8,8,8,0.65); line-height:1.55; margin-bottom:24px;">Choose a secure password for your condensed intelligence history.</p>
                
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="message message-error">
                        <?= htmlspecialchars($_SESSION['error']) ?>
                        <?php unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="auth.php?action=resetPassword">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    
                    <div class="form-group">
                        <label for="email">Email Address:</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($prefilledEmail) ?>" required <?= empty($prefilledEmail) ? 'autofocus' : '' ?> placeholder="Verify your email">
                    </div>

                    <div class="form-group">
                        <label for="otp">Security Code (OTP):</label>
                        <input type="text" id="otp" name="otp" required <?= !empty($prefilledEmail) ? 'autofocus' : '' ?> placeholder="Enter 6-digit code from email">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">New Password:</label>
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
                        <label for="confirm_password">Confirm Password:</label>
                        <div class="pw-input-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" required placeholder="Retype new password" autocomplete="new-password">
                            <button type="button" class="pw-toggle" id="toggle-confirm" aria-label="Show password" tabindex="-1">
                                <svg class="pw-icon pw-icon--show" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="pw-icon pw-icon--hide" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-auth">Update Password</button>
                </form>

                <div class="auth-nav-links">
                    <a href="login.php">Return to Login</a>
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
<?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
