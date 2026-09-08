<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Services/AdminSecurityService.php';
require_once __DIR__ . '/../src/Database.php';

use App\Src\Services\AdminSecurityService;
use App\Src\Database;

if (empty($_SESSION['pending_admin_user_id']) || ($_SESSION['admin_challenge_verified'] ?? false) === true) {
    header('Location: login.php');
    exit;
}

$service = new AdminSecurityService();
if (!$service->hasChallengeSetup((int)$_SESSION['pending_admin_user_id'])) {
    header('Location: admin_challenge_setup.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../src/Utils/validation.php';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session request. Please try again.';
    } else {
        $anime = $_POST['anime'] ?? '';
        $number = $_POST['number'] ?? '';

        if (trim($anime) === '' || trim($number) === '') {
            $error = 'Both fields are required.';
        } else {
            $service = new AdminSecurityService();
            $result = $service->verifyChallenge((int)$_SESSION['pending_admin_user_id'], $anime, $number);

            if (isset($result['error'])) {
                $error = $result['error'];
            } else if (isset($result['success']) && $result['success'] === true) {
                // Success
                \App\Src\Utils\SessionManager::regenerate();
                
                // Fetch the user to setup their session properly
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $_SESSION['pending_admin_user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = 'admin';
                $_SESSION['username'] = $user['username'];
                $_SESSION['admin_challenge_verified'] = true;
                
                unset($_SESSION['pending_admin_user_id']);
                
                require_once __DIR__ . '/../src/Services/TermsAcceptanceService.php';
                $termsAcceptanceService = new \App\Src\Services\TermsAcceptanceService();
                $termsAcceptanceService->storeTermsStateInSession($user);

                if (\App\Src\Services\TermsAcceptanceService::currentUserNeedsAcceptance()) {
                    header('Location: accept_terms.php');
                    exit;
                }

                header('Location: admin_dashboard.php');
                exit;
            }
        }
    }
}

require_once __DIR__ . '/../src/Utils/validation.php';
$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Verification</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=VT323&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .auth-container { max-width: 400px; margin: 100px auto; padding: 30px; background: #fff; box-shadow: var(--shadow-soft); border: 1px solid var(--color-border); }
        .auth-title { font-family: 'Merriweather', serif; font-size: 1.5em; text-align: center; margin-bottom: 20px; color: var(--color-text); }
        .auth-field { margin-bottom: 20px; }
        .auth-field label { display: block; font-family: 'Inter', sans-serif; font-size: 0.8em; font-weight: 600; text-transform: uppercase; color: var(--color-muted); margin-bottom: 8px; }
        .auth-field input { width: 100%; padding: 12px; border: 1px solid var(--color-border); font-family: 'Inter', sans-serif; font-size: 1em; box-sizing: border-box; }
        .auth-submit { width: 100%; padding: 12px; background: var(--color-primary-dark); color: #fff; border: none; font-family: 'Inter', sans-serif; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; }
        .auth-submit:hover { background: var(--color-primary); }
        .auth-error { background: var(--color-danger-soft); color: var(--color-danger); padding: 10px; font-family: 'Inter', sans-serif; font-size: 0.85em; border-left: 3px solid var(--color-danger); margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="auth-container">
        <h2 class="auth-title">Admin Verification Required</h2>
        <?php if ($error): ?>
            <div class="auth-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="admin_verify.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="auth-field">
                <label for="anime">Favorite Anime</label>
                <input type="text" id="anime" name="anime" required autofocus autocomplete="off">
            </div>
            <div class="auth-field">
                <label for="number">Favorite Number</label>
                <input type="number" id="number" name="number" required autocomplete="off">
            </div>
            <button type="submit" class="auth-submit">Verify Identity</button>
        </form>
    </div>
</body>
</html>
