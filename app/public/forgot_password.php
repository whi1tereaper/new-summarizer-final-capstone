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
    <title>Recover Password — LIGHT</title>
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
                            <span class="auth-brand-copy__eyebrow">RECOVER ACCESS</span>
                            <h2>Recover Password</h2>
                        </div>
                    </div>
                    <p style="font-family:'Inter', sans-serif; font-size:0.9rem; color:rgba(8,8,8,0.65); line-height:1.55; margin-bottom:24px;">Enter your email address and we will send you a verification code to reset your password.</p>
                    
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

                        <button type="submit" class="btn-auth">Send OTP</button>
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
