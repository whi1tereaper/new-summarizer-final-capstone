<?php
// admin-only page
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Services/AdminSecurityService.php';

use App\Src\Services\AdminSecurityService;

AdminSecurityService::requireVerifiedAdmin('login.php?context=admin');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (($_GET['refresh'] ?? '') === '1') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AdminController.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Controllers\AdminController;
use App\Src\Controllers\AnalyticsController;
use App\Src\Controllers\FeedbackHandler;

// Explicit admin action dispatcher
$action = $_GET['action'] ?? ($_POST['action'] ?? null);
if ($action !== null) {
    if(!is_string($action)){http_response_code(400);exit('Invalid admin action.');}
    $auditAction=in_array($action,['toggle_status','clean_files','delete_files','delete_user','deactivate_user'],true)?$action:'unsupported_action';
    $auditSvc = new AdminSecurityService();
    $callerAdminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    // Gate 1: POST only for state-modifying admin actions
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die('Method not allowed.');
    }

    // Gate 2: Authenticated administrator role
    if (!AdminSecurityService::isVerifiedAdmin()) {
        $auditSvc->auditLog(
            $callerAdminId,
            'admin_action_denied',
            'Unauthorized session attempted admin action: ' . $auditAction,
            null,
            null
        );
        http_response_code(403);
        die('Access denied.');
    }

    // Gate 3: CSRF token validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !verifyCsrfToken($csrfToken)) {
        $auditSvc->auditLog(
            $callerAdminId,
            'admin_action_denied',
            'Invalid CSRF token for action: ' . $auditAction,
            null,
            null
        );
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    $admin = new AdminController();
    switch ($action) {
        case 'toggle_status':
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $status = filter_var($_POST['status'] ?? null, FILTER_VALIDATE_INT);
            if ($userId === false || $userId === null || $userId < 1 || !in_array($status, [0, 1], true)) {
                $_SESSION['flash_error'] = 'Invalid input for status toggle.';
                header('Location: admin_dashboard.php');
                exit;
            }
            $admin->toggleUserStatus($userId, $status);
            rotateCsrfToken();
            header('Location: admin_dashboard.php');
            exit;

        case 'clean_files':
            $admin->cleanOrphanedFiles();
            rotateCsrfToken();
            header('Location: admin_dashboard.php');
            exit;

        case 'delete_files':
            $admin->deleteAllFiles();
            rotateCsrfToken();
            header('Location: admin_dashboard.php');
            exit;

        case 'delete_user':
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($userId === false || $userId === null || $userId < 1) {
                $auditSvc->auditLog($callerAdminId, 'admin_delete_failed', 'Invalid user ID submitted', 'user', null);
                $_SESSION['flash_error'] = 'Invalid user ID.';
                header('Location: admin_dashboard.php');
                exit;
            }
            $admin->hardDeleteUser($userId);
            rotateCsrfToken();
            header('Location: admin_dashboard.php');
            exit;

        case 'deactivate_user':
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($userId === false || $userId === null || $userId < 1) {
                $auditSvc->auditLog($callerAdminId, 'admin_delete_failed', 'Invalid user ID for deactivation', 'user', null);
                $_SESSION['flash_error'] = 'Invalid user ID.';
                header('Location: admin_dashboard.php');
                exit;
            }
            $admin->deactivateUser($userId);
            rotateCsrfToken();
            header('Location: admin_dashboard.php');
            exit;

        default:
            http_response_code(400);
            die('Invalid admin action.');
    }
}

$admin = new AdminController();
$adminStatsRanges = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All time'];
$requestedStatsRange = isset($_GET['stats_range']) && is_scalar($_GET['stats_range'])
    ? (string)$_GET['stats_range']
    : 'all';
$adminStatsRange = isset($adminStatsRanges[$requestedStatsRange]) ? $requestedStatsRange : 'all';
$adminStatsTo = date('Y-m-d');
$adminStatsFrom = $adminStatsRange === 'all'
    ? null
    : date('Y-m-d', strtotime('-' . ((int)$adminStatsRange - 1) . ' days'));
$analyticsStatsFrom = $adminStatsFrom ?? '1970-01-01';
$users = $admin->getAllUsers();
$stats = $admin->getSystemStats($adminStatsFrom, $adminStatsRange === 'all' ? null : $adminStatsTo);
$storageSnapshot=null;$storageError='';
try{$storageSnapshot=$admin->getStorageMaintenanceStats();}catch(Throwable $error){$storageError='Storage status is temporarily unavailable. Refresh to check again.';}
$feedbackStats = FeedbackHandler::getSystemFeedbackStats($adminStatsFrom, $adminStatsRange === 'all' ? null : $adminStatsTo);
$fbFilterRating  = isset($_GET['fb_rating']) && is_numeric($_GET['fb_rating']) ? (int)$_GET['fb_rating'] : null;
$fbFilterComment = isset($_GET['fb_comment']) && $_GET['fb_comment'] !== '' ? ($_GET['fb_comment'] === '1') : null;
$fbFilterSort    = isset($_GET['fb_sort']) && in_array($_GET['fb_sort'], ['newest', 'lowest_rated', 'highest_rated'], true) ? $_GET['fb_sort'] : 'newest';

$feedbackFilters = [
    'rating'      => $fbFilterRating,
    'has_comment' => $fbFilterComment,
    'sort'        => $fbFilterSort,
];
$allFeedback       = FeedbackHandler::getAllFeedbackForAdmin($feedbackFilters);
$feedbackTelemetry = FeedbackHandler::getFeedbackTelemetryBreakdown();
$csrf_token = generateCsrfToken();

// Landing page analytics - gracefully degrade if tables haven't been migrated yet.
$analyticsAvailable = false;
$analyticsKpis      = [];
$analyticsDevices   = [];
$analyticsFunnel    = [];
$analyticsTrend     = [];
$analyticsReferrers = [];
$analyticsData      = null;
$isAdmin            = true;
try {
    $analytics = new AnalyticsController();
    $analyticsKpis      = $analytics->getKpis();
    $analyticsDevices   = $analytics->getDeviceBreakdown();
    $analyticsFunnel    = $analytics->getScrollFunnel();
    $analyticsTrend     = $analytics->getDailyTrend();
    $analyticsReferrers = $analytics->getTopReferrers();
    $analyticsData      = $analytics->getDashboardData(null, true, $analyticsStatsFrom, $adminStatsTo, null);
    $analyticsAvailable = true;
} catch (Throwable $e) {
    error_log('[admin_dashboard analytics] Failed to load landing analytics: ' . $e->getMessage());
    $analyticsAvailable = false;
    $analyticsData = null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require_once __DIR__ . '/partials/design-system-head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — DEPENDED</title>
    <link rel="stylesheet" href="assets/css/style.css?v=navbar-3-<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/dashboard-charts.css?v=<?= file_exists(__DIR__ . '/assets/css/dashboard-charts.css') ? filemtime(__DIR__ . '/assets/css/dashboard-charts.css') : '1' ?>">
    <link rel="stylesheet" href="assets/css/student-access.css?v=<?= filemtime(__DIR__ . '/assets/css/student-access.css') ?>">
    <link rel="stylesheet" href="assets/css/admin-storage.css?v=<?= filemtime(__DIR__ . '/assets/css/admin-storage.css') ?>">
    <link rel="stylesheet" href="assets/css/admin-dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/admin-dashboard.css') ?>">
</head>
<body class="admin-dashboard-page">
<?php $showLightBrand = true; ?>
<?php require __DIR__ . '/partials/site-nav.php'; ?>
    <main class="editorial-layout">
        <div class="admin-toolbar">
            <h1>Administrative Console</h1>
        </div>

        <?php if (isset($_SESSION['flash_success'])): ?>
            <div style="background:var(--color-accent-soft); color:var(--color-accent-strong); padding:15px; margin-bottom:20px; font-family:var(--font-body); font-size:0.85em; border-left:4px solid var(--color-accent);">
                <?= htmlspecialchars($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div style="background:var(--color-danger-soft); color:var(--color-danger); padding:15px; margin-bottom:20px; font-family:var(--font-body); font-size:0.85em; border-left:4px solid var(--color-danger);">
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="admin-section">
            <?php require __DIR__.'/partials/admin-student-registry.php'; ?>
            <h2>System Overview</h2>
            <form class="admin-stats-range" id="admin-stats-range-form" method="get" action="admin_dashboard.php">
                <label for="admin-stats-range-select">Statistics period
                    <select id="admin-stats-range-select" name="stats_range">
                        <?php foreach ($adminStatsRanges as $rangeValue => $rangeLabel): ?>
                            <option value="<?= htmlspecialchars((string)$rangeValue, ENT_QUOTES, 'UTF-8') ?>" <?= $adminStatsRange === (string)$rangeValue ? 'selected' : '' ?>><?= htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php foreach (['fb_rating', 'fb_comment', 'fb_sort'] as $preservedFilter): ?>
                    <?php if (isset($_GET[$preservedFilter]) && is_scalar($_GET[$preservedFilter])): ?>
                        <input type="hidden" name="<?= htmlspecialchars($preservedFilter, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$_GET[$preservedFilter], ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <button class="btn btn--primary" type="submit">Apply period</button>
                <p class="admin-stats-range__caption">Overview totals and analytics use <?= htmlspecialchars($adminStatsRanges[$adminStatsRange], ENT_QUOTES, 'UTF-8') ?>.</p>
            </form>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['user_count'] ?></div>
                    <div class="stat-label">Registered Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['summary_count'] ?></div>
                    <div class="stat-label">Total Summaries</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['guest_user_count'] ?></div>
                    <div class="stat-label">Guest Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $feedbackStats['total_ratings'] ?></div>
                    <div class="stat-label">Total Ratings</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $feedbackStats['avg_rating'] !== null ? number_format($feedbackStats['avg_rating'], 1) . ' &#9733;' : '&mdash;' ?></div>
                    <div class="stat-label">Avg Star Rating</div>
                </div>
            </div>
        </div>

        <?php require __DIR__ . '/partials/analytics-graphs-card.php'; ?>

        <?php require __DIR__.'/partials/admin-storage-maintenance.php'; ?>

        <div class="admin-section">
            <h2>User Management</h2>
            <div class="admin-user-filters" role="search" aria-label="Filter user list">
                <div class="admin-user-filters__field admin-user-filters__field--search">
                    <label for="admin-user-search">Search users</label>
                    <input id="admin-user-search" type="search" autocomplete="off" placeholder="Name, email, or ID" aria-controls="admin-user-table">
                </div>
                <div class="admin-user-filters__field">
                    <label for="admin-user-role">Role</label>
                    <select id="admin-user-role" aria-controls="admin-user-table">
                        <option value="">All roles</option>
                        <option value="admin">Admin</option>
                        <option value="user">User</option>
                    </select>
                </div>
                <div class="admin-user-filters__field">
                    <label for="admin-user-status">Status</label>
                    <select id="admin-user-status" aria-controls="admin-user-table">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <button type="button" class="btn btn--secondary admin-user-filters__clear" id="admin-user-filters-clear">Clear filters</button>
            </div>
            <p class="admin-user-filters__count" id="admin-user-filter-count" role="status" aria-live="polite" aria-atomic="true"></p>
            <div class="table-responsive">
            <table id="admin-user-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Accepted Terms</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="admin-user-table-body">
                    <?php foreach ($users as $user): ?>
                    <tr data-admin-user-row
                        data-search="<?= htmlspecialchars((string)$user['id'] . ' ' . (string)$user['username'] . ' ' . (string)$user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-role="<?= htmlspecialchars((string)$user['role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-status="<?= !empty($user['active']) ? 'active' : 'inactive' ?>">
                        <td><?= $user['id'] ?></td>
                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><span class="role-badge role-<?= htmlspecialchars($user['role']) ?>"><?= htmlspecialchars($user['role']) ?></span></td>
                        <td class="<?= $user['active'] ? 'status-active' : 'status-inactive' ?>"><?= $user['active'] ? 'Active' : 'Inactive' ?></td>
                        <td><?= (int)($user['terms_accepted'] ?? 0) === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                        <td>
                            <?php
                            $isSelf = (int)$user['id'] === (int)$_SESSION['user_id'];
                            $isAdmin = $user['role'] === 'admin';
                            ?>
                            <?php if ($isSelf): ?>
                                <span style="font-size:0.72em; color:var(--color-muted); text-transform:uppercase; letter-spacing:1px;">(You)</span>
                            <?php elseif ($isAdmin): ?>
                                <span style="font-size:0.72em; color:var(--color-muted); text-transform:uppercase; letter-spacing:1px;">Admin</span>
                            <?php else: ?>
                                <div class="table-actions" role="group" aria-label="Account actions for <?= htmlspecialchars((string)$user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?php if ($user['active']): ?>
                                    <form method="POST" action="admin_dashboard.php?action=deactivate_user"
                                        onsubmit="return confirm(<?= htmlspecialchars(json_encode('Deactivate user ' . $user['username'] . '? They will be locked out but their data is kept.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>);">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <button class="text-action" type="submit" aria-label="Deactivate account <?= htmlspecialchars((string)$user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg>
                                            <span>Deactivate</span>
                                        </button>
                                    </form>
                                    <span class="table-actions__divider" aria-hidden="true"></span>
                                <?php endif; ?>
                                <form method="POST" action="admin_dashboard.php?action=delete_user"
                                    onsubmit="return confirm(<?= htmlspecialchars(json_encode('WARNING: Permanently delete user ' . $user['username'] . ' and ALL their data? This cannot be undone.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>);">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                    <button class="text-action text-action--danger" type="submit" aria-label="Permanently delete account <?= htmlspecialchars((string)$user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6m4-6v6"/></svg>
                                        <span>Delete</span>
                                    </button>
                                </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="admin-user-no-results" hidden>
                        <td colspan="8" style="text-align:center; color:var(--color-muted);">No users match these filters.</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Telemetry & Quality Overview -->
        <div class="admin-section" id="feedback-telemetry-section">
            <h2>Summarizer Quality Telemetry</h2>
            <p style="font-size: 0.85em; color: var(--color-muted); margin-bottom: 20px;">
                Direct correlation between user ratings, reported issues, and summarization engine parameters.
            </p>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 25px;">
                <!-- Telemetry by Analysis Mode -->
                <div style="background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); padding: 16px;">
                    <strong style="display: block; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text); margin-bottom: 10px;">By Analysis Mode</strong>
                    <?php if (!empty($feedbackTelemetry['by_style'])): ?>
                    <table style="width: 100%; font-size: 0.82em;">
                        <tbody>
                            <?php foreach ($feedbackTelemetry['by_style'] as $st): ?>
                            <tr>
                                <td style="padding: 4px 0; font-weight: 500;"><?= htmlspecialchars(str_replace('_', ' ', $st['analysis_mode'])) ?></td>
                                <td style="padding: 4px 0; text-align: right; color: var(--color-primary-dark); font-weight: 600;">★ <?= $st['avg_rating'] ?? 'N/A' ?></td>
                                <td style="padding: 4px 0; text-align: right; color: var(--color-muted);">(<?= (int)$st['count'] ?>)</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="font-size: 0.8em; color: var(--color-muted); margin: 0;">No mode data yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Telemetry by Depth -->
                <div style="background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); padding: 16px;">
                    <strong style="display: block; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text); margin-bottom: 10px;">By Summary Depth</strong>
                    <?php if (!empty($feedbackTelemetry['by_length'])): ?>
                    <table style="width: 100%; font-size: 0.82em;">
                        <tbody>
                            <?php foreach ($feedbackTelemetry['by_length'] as $len): ?>
                            <tr>
                                <td style="padding: 4px 0; font-weight: 500; text-transform: capitalize;"><?= htmlspecialchars($len['summary_depth']) ?></td>
                                <td style="padding: 4px 0; text-align: right; color: var(--color-primary-dark); font-weight: 600;">★ <?= $len['avg_rating'] ?? 'N/A' ?></td>
                                <td style="padding: 4px 0; text-align: right; color: var(--color-muted);">(<?= (int)$len['count'] ?>)</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="font-size: 0.8em; color: var(--color-muted); margin: 0;">No depth data yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Reported Issue Reasons -->
                <div style="background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); padding: 16px;">
                    <strong style="display: block; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text); margin-bottom: 10px;">Reported Issue Reasons</strong>
                    <?php if (!empty($feedbackTelemetry['reasons_counts'])): 
                        $allowedLabels = \App\Src\Services\FeedbackService::ALLOWED_REASONS;
                    ?>
                    <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.82em;">
                        <?php foreach ($feedbackTelemetry['reasons_counts'] as $rKey => $rCount): ?>
                        <li style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--color-border);">
                            <span style="color: var(--color-text);"><?= htmlspecialchars($allowedLabels[$rKey] ?? $rKey) ?></span>
                            <span style="font-weight: 600; color: var(--color-danger);"><?= (int)$rCount ?> report<?= $rCount > 1 ? 's' : '' ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p style="font-size: 0.8em; color: var(--color-muted); margin: 0;">No negative issue tags reported.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="admin-section" id="admin-feedback-section">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                <h2 style="margin: 0;">User Feedback (Anonymous Repository)</h2>
                
                <!-- Feedback Filters Bar -->
                <form method="GET" action="admin_dashboard.php" class="feedback-filters">
                    <label style="font-size: 0.8em; color: var(--color-muted);">Rating:
                        <select name="fb_rating" style="padding: 4px 8px; font-size: 0.85em; background: var(--color-bg); color: var(--color-text); border: 1px solid var(--color-border);">
                            <option value="">All ratings</option>
                            <option value="5" <?= $fbFilterRating === 5 ? 'selected' : '' ?>>5 Stars</option>
                            <option value="4" <?= $fbFilterRating === 4 ? 'selected' : '' ?>>4 Stars</option>
                            <option value="3" <?= $fbFilterRating === 3 ? 'selected' : '' ?>>3 Stars</option>
                            <option value="2" <?= $fbFilterRating === 2 ? 'selected' : '' ?>>2 Stars</option>
                            <option value="1" <?= $fbFilterRating === 1 ? 'selected' : '' ?>>1 Star</option>
                        </select>
                    </label>

                    <label style="font-size: 0.8em; color: var(--color-muted);">Comments:
                        <select name="fb_comment" style="padding: 4px 8px; font-size: 0.85em; background: var(--color-bg); color: var(--color-text); border: 1px solid var(--color-border);">
                            <option value="">All</option>
                            <option value="1" <?= $fbFilterComment === true ? 'selected' : '' ?>>With comment</option>
                            <option value="0" <?= $fbFilterComment === false ? 'selected' : '' ?>>No comment</option>
                        </select>
                    </label>

                    <label style="font-size: 0.8em; color: var(--color-muted);">Sort:
                        <select name="fb_sort" style="padding: 4px 8px; font-size: 0.85em; background: var(--color-bg); color: var(--color-text); border: 1px solid var(--color-border);">
                            <option value="newest" <?= $fbFilterSort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                            <option value="lowest_rated" <?= $fbFilterSort === 'lowest_rated' ? 'selected' : '' ?>>Lowest Rated</option>
                            <option value="highest_rated" <?= $fbFilterSort === 'highest_rated' ? 'selected' : '' ?>>Highest Rated</option>
                        </select>
                    </label>

                    <button class="btn btn--primary" type="submit" >Filter</button>
                    <a href="admin_dashboard.php#admin-feedback-section" class="btn btn--ghost feedback-reset" >Reset</a>
                </form>
            </div>

            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Summary ID</th>
                        <th>Summary Title</th>
                        <th>Rating</th>
                        <th>Reported Reasons</th>
                        <th>Comment</th>
                        <th>Configuration Telemetry</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $allowedLabels = \App\Src\Services\FeedbackService::ALLOWED_REASONS;
                    foreach ($allFeedback as $fb): 
                        $ratingVal = (int)($fb['rating'] ?? 0);
                        $reasonsList = [];
                        if (!empty($fb['reasons'])) {
                            $decoded = json_decode($fb['reasons'], true);
                            if (is_array($decoded)) {
                                foreach ($decoded as $rk) {
                                    $reasonsList[] = $allowedLabels[$rk] ?? $rk;
                                }
                            }
                        }
                    ?>
                    <tr>
                        <td><?= (int)$fb['summary_id'] ?></td>
                        <td title="<?= htmlspecialchars($fb['article_title'] ?? 'Deleted Summary') ?>">
                            <?php if (!empty($fb['article_title'])): ?>
                                <a href="result.php?id=<?= (int)$fb['summary_id'] ?>" target="_blank" style="color:var(--color-primary-dark); font-weight:600;">
                                    <?= htmlspecialchars(mb_strimwidth($fb['article_title'], 0, 30, "...")) ?>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--color-muted); font-style:italic;">Deleted Summary</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-weight: 700; color: <?= $ratingVal <= 2 ? 'var(--color-danger)' : ($ratingVal >= 4 ? 'var(--color-success)' : 'var(--color-warning)') ?>;">
                                ★ <?= $ratingVal > 0 ? $ratingVal : '-' ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($reasonsList)): ?>
                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    <?php foreach ($reasonsList as $rl): ?>
                                    <span style="font-size: 0.72em; background: var(--color-danger-soft); color: var(--color-danger); border: 1px solid var(--color-danger-border); padding: 2px 6px; border-radius: 999px;">
                                        <?= htmlspecialchars($rl) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: var(--color-muted); font-size: 0.8em;">-</span>
                            <?php endif; ?>
                        </td>
                        <td title="<?= htmlspecialchars($fb['comment'] ?? '') ?>">
                            <?= !empty($fb['comment']) ? htmlspecialchars(mb_strimwidth($fb['comment'], 0, 60, "...")) : '<span style="color:var(--color-muted); font-style:italic;">None</span>' ?>
                        </td>
                        <td style="font-size: 0.78em; color: var(--color-muted);">
                            <?= htmlspecialchars(($fb['analysis_mode'] ?? 'standard') . ' · ' . ($fb['summary_depth'] ?? 'balanced')) ?>
                            <?php if (!empty($fb['processing_time'])): ?>
                                <br><span><?= number_format((float)$fb['processing_time'], 2) ?>s</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M d, Y', strtotime($fb['created_at'] ?? 'now')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allFeedback)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; color:var(--color-muted);">No feedback matching current filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="admin-section">
            <h2>Feedback Comments Repository (Anonymous)</h2>
            <div class="comments-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: var(--space-5);">
                <?php 
                $allowedLabels = \App\Src\Services\FeedbackService::ALLOWED_REASONS;
                foreach ($allFeedback as $fb): 
                    $commentText = trim($fb['comment'] ?? '');
                    $ratingVal = (int)($fb['rating'] ?? 0);
                    $reasonsList = [];
                    if (!empty($fb['reasons'])) {
                        $decoded = json_decode($fb['reasons'], true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $rk) {
                                $reasonsList[] = $allowedLabels[$rk] ?? $rk;
                            }
                        }
                    }
                ?>
                    <div class="comment-card" style="background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); padding: 20px; border-radius: 0; position: relative;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <div>
                                <strong style="display: block; font-size: 1em; color: var(--color-text);">Anonymous User</strong>
                                <span style="font-size: 0.75em; color: var(--color-muted); text-transform: uppercase;"><?= date('M d, Y', strtotime($fb['created_at'])) ?></span>
                            </div>
                            <span style="font-size: 0.75em; font-weight: 700; color: <?= $ratingVal <= 2 ? 'var(--color-danger)' : ($ratingVal >= 4 ? 'var(--color-success)' : 'var(--color-warning)') ?>; background: var(--color-bg-tertiary); padding: 4px 8px; border-radius: 4px;">
                                ★ <?= $ratingVal > 0 ? $ratingVal . ' / 5' : 'No rating' ?>
                            </span>
                        </div>

                        <?php if (!empty($reasonsList)): ?>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 10px;">
                            <?php foreach ($reasonsList as $rl): ?>
                            <span style="font-size: 0.7em; background: var(--color-danger-soft); color: var(--color-danger); border: 1px solid var(--color-danger-border); padding: 2px 6px; border-radius: 999px;">
                                <?= htmlspecialchars($rl) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <p style="font-size: 0.9em; line-height: 1.6; color: <?= $commentText ? 'var(--color-text)' : 'var(--color-text-muted)' ?>; margin: 0 0 12px 0; font-style: <?= $commentText ? 'normal' : 'italic' ?>;">
                            <?= $commentText ? '"' . htmlspecialchars($commentText) . '"' : '(No detailed comment provided)' ?>
                        </p>

                        <!-- Telemetry metadata footer -->
                        <div style="padding-top: 10px; border-top: 1px solid var(--color-border); font-size: 0.75em; color: var(--color-muted); display: flex; flex-direction: column; gap: 3px;">
                            <div>
                                <strong>Summary:</strong> 
                                <?php if (!empty($fb['article_title'])): ?>
                                    <a href="result.php?id=<?= (int)$fb['summary_id'] ?>" target="_blank" style="color: var(--color-primary-dark); text-decoration: underline;"><?= htmlspecialchars(mb_strimwidth($fb['article_title'], 0, 35, "...")) ?></a>
                                <?php else: ?>
                                    <span style="font-style:italic;">(Deleted Summary)</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong>Engine Configuration:</strong> 
                                <?= htmlspecialchars(($fb['analysis_mode'] ?? 'general') . ' · ' . ($fb['summary_depth'] ?? 'balanced') . ' · ' . ($fb['source_type'] ?? 'text')) ?>
                                <?php if (!empty($fb['summary_word_count'])): ?>
                                    <span>(<?= (int)$fb['summary_word_count'] ?> words)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($allFeedback)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--color-muted); border: 1px dashed var(--color-border); border-radius: 0;">
                        No feedback has been submitted yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div style="border-top: 1px solid var(--color-border); padding-top: 20px; margin-top: 40px;">
            <a href="index.php" style="font-family:var(--font-body); font-size:0.8em; color:var(--color-primary-dark); text-decoration:underline; text-transform:uppercase; letter-spacing:1px;">Return to Dashboard</a>
        </div>

        <?php if (!$analyticsAvailable): ?>
        <div class="admin-section">
            <h2>Landing Page Analytics</h2>
            <p style="font-family:var(--font-body); font-size:0.85em; color:var(--color-muted); padding:20px; border:1px dashed var(--color-border); text-align:center;">
                Analytics tables not yet installed. Run <code>database/analytics_schema.sql</code> to enable this section.
            </p>
        </div>
        <?php else: ?>
        <div class="admin-section" id="landing-analytics">
            <h2>Landing Page Analytics</h2>

            <?php
            // ── KPI helpers ──────────────────────────────────────────────────
            $lcpMs       = $analyticsKpis['median_lcp_ms'];
            $lcpLabel    = $lcpMs === null ? 'N/A' : number_format($lcpMs) . ' ms';
            $lcpStatus   = 'lcp-good';
            if ($lcpMs !== null) {
                if ($lcpMs > 4000)      $lcpStatus = 'lcp-poor';
                elseif ($lcpMs > 2500)  $lcpStatus = 'lcp-needs-improvement';
            }

            $avgDwell  = $analyticsKpis['avg_dwell_seconds'];
            $dwellFmt  = $avgDwell > 0 ? gmdate('i:s', (int)$avgDwell) : '0:00';
            ?>

            <!-- ── KPI Cards ── -->
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">

                <div class="stat-card">
                    <div class="stat-value"><?= $analyticsKpis['sessions_7d'] ?></div>
                    <div class="stat-label">Sessions (7 days)</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $analyticsKpis['sessions_today'] ?></div>
                    <div class="stat-label">Sessions Today</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $analyticsKpis['conversion_rate'] ?>%</div>
                    <div class="stat-label">CTA Conversion Rate</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $dwellFmt ?></div>
                    <div class="stat-label">Avg Dwell Time</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value <?= $lcpStatus ?>"><?= $lcpLabel ?></div>
                    <div class="stat-label">Median LCP</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $analyticsKpis['bounce_rate'] ?>%</div>
                    <div class="stat-label">Bounce Rate</div>
                </div>

            </div><!-- /.stats-grid -->

            <!-- ── Two-column grid: Device + Funnel ── -->
            <div class="analytics-two-col">

                <!-- Device Breakdown -->
                <div class="analytics-panel">
                    <h3 class="analytics-panel__title">Device Breakdown</h3>
                    <?php foreach ($analyticsDevices as $dev => $pct): ?>
                    <div class="device-row">
                        <span class="device-row__label"><?= ucfirst($dev) ?></span>
                        <div class="device-row__bar-wrap">
                            <div class="device-row__bar" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="device-row__pct"><?= $pct ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Scroll Funnel -->
                <div class="analytics-panel">
                    <h3 class="analytics-panel__title">Scroll Depth Funnel</h3>
                    <div class="funnel-steps">
                        <?php
                        $funnelLabels = [25 => 'Scrolled 25%', 50 => 'Scrolled 50%', 75 => 'Scrolled 75%', 100 => 'Full Page'];
                        foreach ($analyticsFunnel as $milestone => $pct):
                        ?>
                        <div class="funnel-step">
                            <div class="funnel-step__bar-wrap">
                                <div class="funnel-step__bar" style="height:<?= $pct ?>%;"></div>
                            </div>
                            <div class="funnel-step__pct"><?= $pct ?>%</div>
                            <div class="funnel-step__label"><?= $funnelLabels[$milestone] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div><!-- /.analytics-two-col -->

            <!-- ── Daily Sessions Sparkline ── -->
            <div class="analytics-panel" style="margin-bottom:30px;">
                <h3 class="analytics-panel__title">Daily Sessions (Last 14 Days)</h3>
                <?php
                $trendValues = array_column($analyticsTrend, 'sessions');
                $trendMax    = max(array_merge([1], $trendValues)); // avoid div-by-zero
                $svgW = 600;
                $svgH = 80;
                $pts  = [];
                $barW = floor($svgW / count($trendValues));
                foreach ($trendValues as $i => $v) {
                    $x = $i * $barW + $barW / 2;
                    $y = $svgH - round(($v / $trendMax) * ($svgH - 10)) - 2;
                    $pts[] = "$x,$y";
                }
                $polyline = implode(' ', $pts);
                $areaPath = 'M ' . $pts[0];
                foreach (array_slice($pts, 1) as $p) $areaPath .= ' L ' . $p;
                $lastPt   = end($pts); list($lx) = explode(',', $lastPt);
                $firstPt  = reset($pts); list($fx) = explode(',', $firstPt);
                $areaPath .= " L {$lx},{$svgH} L {$fx},{$svgH} Z";
                ?>
                <div class="sparkline-wrap">
                    <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" preserveAspectRatio="none"
                         xmlns="http://www.w3.org/2000/svg" class="sparkline-svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="sparkGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%"   stop-color="var(--color-chart-1)" stop-opacity="0.25"/>
                                <stop offset="100%" stop-color="var(--color-chart-1)" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <path d="<?= $areaPath ?>" fill="url(#sparkGrad)"/>
                        <polyline points="<?= $polyline ?>" fill="none"
                                  stroke="var(--color-chart-1)" stroke-width="2" stroke-linejoin="round"/>
                    </svg>
                    <div class="sparkline-dates">
                        <span><?= date('M j', strtotime($analyticsTrend[0]['date'])) ?></span>
                        <span><?= date('M j', strtotime(end($analyticsTrend)['date'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- ── Top Referrers ── -->
            <div class="analytics-panel">
                <h3 class="analytics-panel__title">Top Referrers</h3>
                <?php if (empty($analyticsReferrers)): ?>
                    <p style="font-size:0.85em; color:var(--color-muted); margin:0;">No referrer data yet. Most traffic is direct.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Sessions</th>
                            <th>Conversions</th>
                            <th>Conv. Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analyticsReferrers as $ref): ?>
                        <tr>
                            <td style="font-family:var(--font-mono); font-size:0.88em;"><?= $ref['domain'] ?></td>
                            <td><?= $ref['sessions'] ?></td>
                            <td><?= $ref['conversions'] ?></td>
                            <td><?= $ref['sessions'] > 0 ? round($ref['conversions'] / $ref['sessions'] * 100, 1) . '%' : 'N/A' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /#landing-analytics -->
        <?php endif; ?>



        <div style="border-top: 1px solid var(--color-border); padding-top: 20px; margin-top: 40px;">
            <a href="index.php" style="font-family:var(--font-body); font-size:0.8em; color:var(--color-primary-dark); text-decoration:underline; text-transform:uppercase; letter-spacing:1px;">Return to Dashboard</a>
        </div>
    </main>
    <script src="assets/js/vendor/chart.umd.min.js"></script>
    <script src="assets/js/dashboard-charts.js?v=<?= file_exists(__DIR__ . '/assets/js/dashboard-charts.js') ? filemtime(__DIR__ . '/assets/js/dashboard-charts.js') : '1' ?>"></script>
    <script src="assets/js/admin-user-filters.js?v=<?= file_exists(__DIR__ . '/assets/js/admin-user-filters.js') ? filemtime(__DIR__ . '/assets/js/admin-user-filters.js') : '1' ?>"></script>
    <script src="assets/js/admin-storage.js?v=<?= filemtime(__DIR__ . '/assets/js/admin-storage.js') ?>"></script>
    <script src="assets/js/index.js?v=<?= filemtime(__DIR__ . '/assets/js/index.js') ?>"></script>
<?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
