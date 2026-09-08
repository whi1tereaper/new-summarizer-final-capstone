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
    <title>Recover Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="editorial-layout">
        <nav style="margin-bottom: 60px;">
            <a href="index.php" style="text-decoration:none; font-size:0.8em; color:#666; font-family:'Inter'; text-transform:uppercase; letter-spacing:1px;">← Back to Home</a>
        </nav>

        <div class="auth-wrapper">
            <div class="auth-card">
                <h2>Recover Password</h2>
                <p style="font-family:'Inter', sans-serif; font-size:0.9em; color:#666; margin-bottom:30px;">Enter your email address and we will send you instructions to reset your password.</p>
                
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
                        <label for="email">Email Address:</label>
                        <input type="email" id="email" name="email" required autofocus placeholder="name@example.com">
                    </div>

                    <button type="submit" class="btn-auth">Send OTP</button>
                </form>

                <div class="auth-nav-links">
                    <a href="login.php">Return to Login</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
