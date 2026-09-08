<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Services/AdminSecurityService.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Services\AdminSecurityService;

if (empty($_SESSION['pending_admin_user_id']) || ($_SESSION['admin_challenge_verified'] ?? false) === true) {
    header('Location: login.php');
    exit;
}

$service = new AdminSecurityService();
if ($service->hasChallengeSetup((int)$_SESSION['pending_admin_user_id'])) {
    header('Location: admin_verify.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session request. Please try again.';
    } else {
        $anime = $_POST['anime'] ?? '';
        $animeConfirm = $_POST['anime_confirm'] ?? '';
        $number = $_POST['number'] ?? '';
        $numberConfirm = $_POST['number_confirm'] ?? '';

        if (trim($anime) === '' || trim($number) === '') {
            $error = 'All fields are required.';
        } elseif ($anime !== $animeConfirm) {
            $error = 'Favorite anime confirmation does not match.';
        } elseif ($number !== $numberConfirm) {
            $error = 'Favorite number confirmation does not match.';
        } else {
            try {
                $service->createOrUpdateChallenge((int)$_SESSION['pending_admin_user_id'], $anime, $number);
                $service->auditLog((int)$_SESSION['pending_admin_user_id'], 'admin_challenge_setup_completed', 'Setup successful');
                error_log('[AdminSecurity] Challenge setup completed for admin ID: ' . $_SESSION['pending_admin_user_id']);
                
                header('Location: admin_verify.php');
                exit;
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (\Exception $e) {
                error_log('[AdminSecurity] Setup failed: ' . $e->getMessage());
                $error = 'An unexpected error occurred during setup.';
            }
        }
    }
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Challenge Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=VT323&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .auth-container { max-width: 450px; margin: 60px auto; padding: 30px; background: #fff; box-shadow: var(--shadow-soft); border: 1px solid var(--color-border); }
        .auth-title { font-family: 'Merriweather', serif; font-size: 1.5em; text-align: center; margin-bottom: 20px; color: var(--color-text); }
        .auth-field { margin-bottom: 20px; }
        .auth-field label { display: block; font-family: 'Inter', sans-serif; font-size: 0.8em; font-weight: 600; text-transform: uppercase; color: var(--color-muted); margin-bottom: 8px; }
        .auth-field input { width: 100%; padding: 12px; border: 1px solid var(--color-border); font-family: 'Inter', sans-serif; font-size: 1em; box-sizing: border-box; }
        .auth-submit { width: 100%; padding: 12px; background: var(--color-primary-dark); color: #fff; border: none; font-family: 'Inter', sans-serif; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; }
        .auth-submit:hover { background: var(--color-primary); }
        .auth-error { background: var(--color-danger-soft); color: var(--color-danger); padding: 10px; font-family: 'Inter', sans-serif; font-size: 0.85em; border-left: 3px solid var(--color-danger); margin-bottom: 20px; }
        .auth-desc { font-family: 'Inter', sans-serif; font-size: 0.9em; color: var(--color-muted); margin-bottom: 25px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="auth-container">
        <h2 class="auth-title">Setup Security Challenge</h2>
        <p class="auth-desc">As an administrator, you must configure a secondary authentication challenge to secure your account. Please answer the following questions. Your answers will be encrypted.</p>
        
        <?php if ($error): ?>
            <div class="auth-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="admin_challenge_setup.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="auth-field">
                <label for="anime">Favorite Anime</label>
                <input type="text" id="anime" name="anime" required autofocus autocomplete="off">
            </div>
            <div class="auth-field">
                <label for="anime_confirm">Confirm Favorite Anime</label>
                <input type="text" id="anime_confirm" name="anime_confirm" required autocomplete="off">
            </div>
            <div class="auth-field">
                <label for="number">Favorite Number</label>
                <input type="number" id="number" name="number" required autocomplete="off">
            </div>
            <div class="auth-field">
                <label for="number_confirm">Confirm Favorite Number</label>
                <input type="number" id="number_confirm" name="number_confirm" required autocomplete="off">
            </div>
            <button type="submit" class="auth-submit">Complete Setup</button>
        </form>
    </div>
</body>
</html>
