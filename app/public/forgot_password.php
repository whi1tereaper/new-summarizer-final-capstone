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
    <?php require_once __DIR__ . '/partials/design-system-head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recover Password — DEPENDED</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    </head>
<body class="page-auth-layout">
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <main class="auth-page-shell">
        <section class="auth-panel">
            <div class="auth-wrapper">
                <div class="auth-card">
                    <div class="auth-brand">
                        <div class="auth-brand-copy">
                            <span class="auth-brand-copy__eyebrow">RECOVER ACCESS</span>
                            <h2>Recover Password</h2>
                        </div>
                    </div>
                    <p class="auth-card-desc">Enter your email address and we will send you a verification code to reset your password.</p>
                    
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

                    <form method="POST" action="auth.php?action=forgotPassword">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required autofocus placeholder="name@example.com">
                        </div>

                        <button type="submit" class="btn btn--primary btn-auth">Send OTP</button>
                    </form>

                    <div class="auth-nav-links">
                        <a href="login.php">Return to Login</a>
                        <a href="register.php">Create Account</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

<?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
