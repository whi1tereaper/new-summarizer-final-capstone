<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Services/AdminSecurityService.php';

use App\Src\Services\AdminSecurityService;

AdminSecurityService::requireVerifiedAdmin('login.php?context=admin');
header('Location: admin_dashboard.php', true, 303);
exit;
