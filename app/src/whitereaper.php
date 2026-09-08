<?php
// Set up the runtime once so every public page starts with the same session and config state.

require_once __DIR__ . '/Services/GuestSessionService.php';
require_once __DIR__ . '/Services/TermsAcceptanceService.php';
require_once __DIR__ . '/Services/RememberMeService.php';
require_once __DIR__ . '/Support/config.php';
require_once __DIR__ . '/Support/RuntimePaths.php';

use App\Src\Services\GuestSessionService;
use App\Src\Services\TermsAcceptanceService;
use App\Src\Services\RememberMeService;
use App\Src\Support\RuntimePaths;

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

date_default_timezone_set('Asia/Manila');
RuntimePaths::ensureRequiredDirectories();



if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/Utils/SessionManager.php';
    \App\Src\Utils\SessionManager::start();
}

if (PHP_SAPI !== 'cli') {
    // Apply baseline security headers
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    // Attempt auto-login if the user has a valid remember-me cookie
    RememberMeService::loginFromCookie();

    // Guests and logged-in users share the same public pages, so bootstrap both contexts up front.
    (new GuestSessionService())->bootstrapCurrentVisitor();

    // Terms state is enforced centrally so each page does not need to duplicate that gate.
    $termsAcceptanceService = new TermsAcceptanceService();
    $termsAcceptanceService->bootstrapCurrentUserSession();
    TermsAcceptanceService::redirectAuthenticatedUserIfTermsPending();
}
