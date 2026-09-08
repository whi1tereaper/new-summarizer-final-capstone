<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Services/AdminSecurityService.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Services\AdminSecurityService;

// Ensure user is logged in as admin
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session request. Please try again.';
    } else {
        $currentAnime = $_POST['current_anime'] ?? '';
        $currentNumber = $_POST['current_number'] ?? '';
        $newAnime = $_POST['new_anime'] ?? '';
        $newAnimeConfirm = $_POST['new_anime_confirm'] ?? '';
        $newNumber = $_POST['new_number'] ?? '';
        $newNumberConfirm = $_POST['new_number_confirm'] ?? '';

        if (trim($currentAnime) === '' || trim($currentNumber) === '' || trim($newAnime) === '' || trim($newNumber) === '') {
            $error = 'All fields are required.';
        } elseif ($newAnime !== $newAnimeConfirm) {
            $error = 'New favorite anime confirmation does not match.';
        } elseif ($newNumber !== $newNumberConfirm) {
            $error = 'New favorite number confirmation does not match.';
        } else {
            $service = new AdminSecurityService();
            // Verify current challenge before allowing change
            $verifyResult = $service->verifyChallenge((int)$_SESSION['user_id'], $currentAnime, $currentNumber);

            if (isset($verifyResult['error'])) {
                // If it's locked, we should probably log out the user, but for now just show error
                if ($verifyResult['locked']) {
                    error_log('[AdminSecurity] Admin locked out during challenge change. ID: ' . $_SESSION['user_id']);
                    $service->auditLog((int)$_SESSION['user_id'], 'admin_challenge_change_failed', 'Account locked out');
                    // Optionally force logout here
                    require_once __DIR__ . '/../src/Controllers/AuthController.php';
                    $auth = new \App\Src\Controllers\AuthController();
                    $auth->logout();
                    exit;
                }
                $service->auditLog((int)$_SESSION['user_id'], 'admin_challenge_change_failed', 'Verification failed');
                error_log('[AdminSecurity] Failed challenge change attempt. ID: ' . $_SESSION['user_id']);
                $error = 'Current challenge verification failed.';
            } else {
                try {
                    $service->createOrUpdateChallenge((int)$_SESSION['user_id'], $newAnime, $newNumber);
                    $service->auditLog((int)$_SESSION['user_id'], 'admin_challenge_changed_successfully', 'Change successful');
                    error_log('[AdminSecurity] Challenge successfully changed for admin ID: ' . $_SESSION['user_id']);
                    $success = 'Security challenge updated successfully.';
                } catch (\InvalidArgumentException $e) {
                    $service->auditLog((int)$_SESSION['user_id'], 'admin_challenge_change_failed', 'Validation error: ' . $e->getMessage());
                    $error = $e->getMessage();
                } catch (\Exception $e) {
                    $service->auditLog((int)$_SESSION['user_id'], 'admin_challenge_change_failed', 'Unexpected error');
                    error_log('[AdminSecurity] Challenge change error: ' . $e->getMessage());
                    $error = 'An unexpected error occurred updating the challenge.';
                }
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
    <title>Change Admin Challenge</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=VT323&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .admin-layout { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        .auth-container { padding: 30px; background: #fff; box-shadow: var(--shadow-soft); border: 1px solid var(--color-border); }
        .auth-title { font-family: 'Merriweather', serif; font-size: 1.5em; margin-bottom: 20px; color: var(--color-text); border-bottom: 2px solid var(--color-primary-dark); padding-bottom: 10px; }
        .auth-field { margin-bottom: 20px; }
        .auth-field label { display: block; font-family: 'Inter', sans-serif; font-size: 0.8em; font-weight: 600; text-transform: uppercase; color: var(--color-muted); margin-bottom: 8px; }
        .auth-field input { width: 100%; padding: 12px; border: 1px solid var(--color-border); font-family: 'Inter', sans-serif; font-size: 1em; box-sizing: border-box; }
        .auth-submit { width: 100%; padding: 12px; background: var(--color-primary-dark); color: #fff; border: none; font-family: 'Inter', sans-serif; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; }
        .auth-submit:hover { background: var(--color-primary); }
        .auth-error { background: var(--color-danger-soft); color: var(--color-danger); padding: 15px; font-family: 'Inter', sans-serif; font-size: 0.85em; border-left: 3px solid var(--color-danger); margin-bottom: 20px; }
        .auth-success { background: rgba(242, 238, 255, 0.88); color: var(--color-primary-dark); padding: 15px; font-family: 'Inter', sans-serif; font-size: 0.85em; border-left: 3px solid var(--color-primary-dark); margin-bottom: 20px; }
        .nav-back { display: inline-block; margin-bottom: 20px; font-family: 'Inter', sans-serif; font-size: 0.85em; text-transform: uppercase; letter-spacing: 1px; color: var(--color-muted); text-decoration: none; }
        .nav-back:hover { color: var(--color-primary-dark); }
        hr { border: 0; border-top: 1px solid var(--color-border); margin: 30px 0; }
    </style>
</head>
<body>
    <div class="admin-layout">
        <a href="admin_dashboard.php" class="nav-back">&larr; Back to Dashboard</a>
        <div class="auth-container">
            <h2 class="auth-title">Update Security Challenge</h2>
            
            <?php if ($error): ?>
                <div class="auth-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="auth-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="admin_challenge_change.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div class="auth-field">
                    <label for="current_anime">Current Favorite Anime</label>
                    <input type="text" id="current_anime" name="current_anime" required autocomplete="off">
                </div>
                <div class="auth-field">
                    <label for="current_number">Current Favorite Number</label>
                    <input type="number" id="current_number" name="current_number" required autocomplete="off">
                </div>
                
                <hr>
                
                <div class="auth-field">
                    <label for="new_anime">New Favorite Anime</label>
                    <input type="text" id="new_anime" name="new_anime" required autocomplete="off">
                </div>
                <div class="auth-field">
                    <label for="new_anime_confirm">Confirm New Favorite Anime</label>
                    <input type="text" id="new_anime_confirm" name="new_anime_confirm" required autocomplete="off">
                </div>
                <div class="auth-field">
                    <label for="new_number">New Favorite Number</label>
                    <input type="number" id="new_number" name="new_number" required autocomplete="off">
                </div>
                <div class="auth-field">
                    <label for="new_number_confirm">Confirm New Favorite Number</label>
                    <input type="number" id="new_number_confirm" name="new_number_confirm" required autocomplete="off">
                </div>
                
                <button type="submit" class="auth-submit">Update Challenge</button>
            </form>
        </div>
    </div>
</body>
</html>
