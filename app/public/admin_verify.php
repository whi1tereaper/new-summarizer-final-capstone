<?php
// Compatibility redirect for bookmarks to the retired administrator challenge.
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Services/AdminSecurityService.php';

// A pending challenge never established a signed-in session. Require a fresh login.
unset($_SESSION['pending_admin_user_id'], $_SESSION['pending_admin_remember_me'], $_SESSION['admin_challenge_verified']);

header('Location: ' . (\App\Src\Services\AdminSecurityService::isVerifiedAdmin()
    ? 'admin_dashboard.php'
    : 'login.php?context=admin'), true, 303);
exit;
